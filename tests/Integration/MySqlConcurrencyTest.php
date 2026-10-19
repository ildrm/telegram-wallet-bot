<?php

namespace Tests\Integration;

use App\Domain\Amount;
use App\Domain\Ledger;
use App\Domain\WalletService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MySqlConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_twenty_independent_mysql_workers_cannot_overspend_one_wallet(): void
    {
        $this->assertSame('mysql', DB::getDriverName(), 'Run this suite with phpunit.mysql.xml against the isolated test database.');
        $this->assertStringContainsString('8.4.', (string) DB::selectOne('SELECT VERSION() AS version')->version);
        $this->assertSame('InnoDB', DB::selectOne("SHOW TABLE STATUS WHERE Name = 'postings'")->Engine);

        $wallet = $this->app->make(WalletService::class);
        $ledger = $this->app->make(Ledger::class);
        $user = $wallet->register('990000001', 'concurrency_fixture');
        $wallet->accept($user['id']);

        // Fund first, then prepare every independent quote before starting processes.
        // Each worker uses the same production configuration and original quote.
        $amount = '10000000000000000';
        $initialFunding = '1000000000000000000';
        DB::transaction(fn () => $ledger->post('concurrency:fund', 'deposit', [
            'system:custody' => $initialFunding,
            $ledger->userCode($user['id']) => Amount::neg($initialFunding),
        ]));
        $quotes = [];
        for ($worker = 0; $worker < 20; $worker++) {
            $quotes[] = $wallet->quote($user['id'], 'withdrawal', $amount, null, '0x'.str_repeat('1', 40));
        }
        $total = (string) $quotes[0]['total'];
        $funding = bcadd(bcmul($total, '5', 0), bcdiv($total, '2', 0), 0);
        $excess = bcsub($initialFunding, $funding, 0);
        DB::transaction(fn () => $ledger->post('concurrency:reduce-funding', 'fixture', [
            'system:custody' => Amount::neg($excess),
            $ledger->userCode($user['id']) => $excess,
        ]));
        $this->assertSame($funding, $wallet->balances($user['id'])['available']);

        $barrier = sys_get_temp_dir().'/wallet-concurrency-'.bin2hex(random_bytes(12));
        $this->assertTrue(mkdir($barrier, 0700));
        $processes = [];
        $environment = array_merge(getenv(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) config('database.connections.mysql.host'),
            'DB_PORT' => (string) config('database.connections.mysql.port'),
            'DB_DATABASE' => (string) config('database.connections.mysql.database'),
            'DB_USERNAME' => (string) config('database.connections.mysql.username'),
            'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
            'WALLET_LIVE_ENABLED' => 'false', 'CUSTODY_DRIVER' => 'fake',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database',
        ]);
        try {
            foreach ($quotes as $slot => $quote) {
                $process = proc_open([
                    PHP_BINARY, base_path('ops/concurrency-worker.php'), (string) $user['id'],
                    $quote['id'], $barrier, (string) $slot,
                ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $processes[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
            }

            $deadline = microtime(true) + 40;
            do {
                $ready = glob($barrier.'/ready-*') ?: [];
                if (count($ready) === 20) {
                    break;
                }
                foreach ($processes as $entry) {
                    $state = proc_get_status($entry['process']);
                    if (! $state['running']) {
                        $this->fail('Worker exited before barrier: '.stream_get_contents($entry['stderr']));
                    }
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertCount(20, $ready, 'All20 workers must be connected and waiting before any confirm begins.');
            $connections = array_map(fn ($path) => trim(file_get_contents($path)), $ready);
            $this->assertCount(20, array_unique($connections), 'Workers must have20 distinct MySQL sessions.');
            $this->assertSame(0, DB::table('transfers')->count(), 'No worker may confirm before barrier release.');
            file_put_contents($barrier.'/release', 'go', LOCK_EX);

            $results = [];
            foreach ($processes as &$entry) {
                stream_set_blocking($entry['stdout'], true);
                stream_set_blocking($entry['stderr'], true);
                stream_set_timeout($entry['stdout'], 45);
                $stdout = stream_get_contents($entry['stdout']);
                $stderr = stream_get_contents($entry['stderr']);
                fclose($entry['stdout']);
                fclose($entry['stderr']);
                $code = proc_close($entry['process']);
                $entry['closed'] = true;
                $this->assertSame(0, $code, 'Worker failed: '.$stderr.' '.$stdout);
                $results[] = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
            }
            unset($entry);
            $this->assertCount(20, $results);
            $this->assertCount(20, array_unique(array_column($results, 'quote_id')));
            $successes = array_values(array_filter($results, fn ($result) => $result['status'] === 'reserved'));
            $this->assertCount(5, $successes);
            $this->assertCount(15, array_filter($results, fn ($result) => $result['status'] === 'insufficient'));
            $this->assertCount(5, array_unique(array_column($successes, 'transfer_id')));
            $this->assertSame(5, DB::table('transfers')->where('status', 'reserved')->count());
            $this->assertSame(5, DB::table('quotes')->whereNotNull('consumed_at')->count());
            $this->assertSame(0, DB::table('fake_custody_payments')->count(), 'Reservations never broadcast inside a transaction.');
            $reserved = bcmul($total, '5', 0);
            $balances = $wallet->balances($user['id']);
            $this->assertSame($reserved, $balances['reserved']);
            $this->assertSame(bcsub($funding, $reserved, 0), $balances['available']);
            $this->assertGreaterThanOrEqual(0, bccomp($balances['available'], '0', 0));
            $this->assertSame($funding, bcadd($balances['reserved'], $balances['available'], 0));
            $this->assertSame(5, DB::table('journals')->where('reference', 'like', 'transfer:%:reserve')->count());
            foreach (DB::table('journals')->get() as $journal) {
                $sum = '0';
                $postings = DB::table('postings')->where('journal_id', $journal->id)->get();
                $this->assertCount(2, $postings);
                foreach ($postings as $posting) {
                    $sum = bcadd($sum, (string) $posting->amount, 0);
                }
                $this->assertSame('0', $sum, 'Unbalanced journal '.$journal->reference);
            }
            $this->assertSame(14, DB::table('postings')->count());
        } finally {
            foreach ($processes as $entry) {
                if (! ($entry['closed'] ?? false)) {
                    proc_terminate($entry['process']);
                    if (is_resource($entry['stdout'])) {
                        fclose($entry['stdout']);
                    }
                    if (is_resource($entry['stderr'])) {
                        fclose($entry['stderr']);
                    }
                    proc_close($entry['process']);
                }
            }
            foreach (glob($barrier.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($barrier);
        }
    }
}
