<?php

namespace Tests\Integration;

use App\Custody\CustodyProvider;
use App\Custody\FakeCustodyProvider;
use App\Domain\DepositService;
use App\Domain\Ledger;
use App\Domain\ReconciliationService;
use App\Domain\WalletService;
use App\Domain\WithdrawalService;
use App\Jobs\ProcessCustodyEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MySqlRestoreTest extends TestCase
{
    public function test_real_mysql_dump_restore_and_authenticated_event_replay_preserve_financial_history(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $docker = getenv('DOCKER_BIN') ?: '/usr/local/bin/docker';
        $this->assertFileExists($docker, 'Set DOCKER_BIN to the Docker CLI path.');
        $suffix = bin2hex(random_bytes(6));
        $source = 'wallet_drill_source_'.$suffix;
        $restored = 'wallet_drill_restored_'.$suffix;
        $originalDefault = DB::getDefaultConnection();
        $connection = config('database.connections.mysql');
        $this->assertSame('127.0.0.1', $connection['host'], 'Restore drill is restricted to the isolated local container.');
        $this->assertSame('33384', (string) $connection['port']);
        $this->assertSame('wallet_test', $connection['database']);
        $administrator = DB::connection('mysql');
        $dump = tempnam(sys_get_temp_dir(), 'wallet-dump-');
        $this->assertNotFalse($dump);
        chmod($dump, 0600);
        $created = [];
        try {
            foreach ([$source, $restored] as $database) {
                $administrator->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $created[] = $database;
            }
            $this->selectDrillDatabase($connection, $source);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'restore_drill', '--force' => true]));
            config(['wallet.custody_webhook_secret' => 'restore-drill-secret', 'queue.default' => 'database']);
            $wallet = app(WalletService::class);
            $provider = app(FakeCustodyProvider::class);
            $sender = $wallet->register('991000001');
            $recipient = $wallet->register('991000002');
            $wallet->accept($sender['id']);
            $wallet->accept($recipient['id']);
            $address = $provider->allocateAddress($sender['wallet_code']);
            DB::table('deposit_addresses')->insert(['wallet_user_id' => $sender['id'], 'address' => $address, 'created_at' => now()]);
            $event = ['event_id' => 'before-backup', 'asset' => 'ETH', 'network' => 'ethereum-sepolia',
                'txid' => '0x'.str_repeat('a', 64), 'index' => 0, 'address' => $address,
                'amount' => '1000000000000000000', 'confirmations' => 12, 'removed' => false];
            $this->ingestAuthenticatedEvent($event);
            $internal = $wallet->quote($sender['id'], 'internal', '10000000000000000', $recipient['wallet_code']);
            $wallet->confirm($sender['id'], $internal['id']);
            $external = $wallet->quote($sender['id'], 'withdrawal', '10000000000000000', null, '0x'.str_repeat('b', 40));
            $transfer = $wallet->confirm($sender['id'], $external['id']);
            config(['wallet.fake.mode' => 'timeout_after_submit']);
            $unknown = app(WithdrawalService::class)->process($transfer['id']);
            $this->assertSame('unknown', $unknown['status']);
            $this->assertSame(1, DB::table('fake_custody_payments')->count());
            $this->assertSame($transfer['id'], DB::table('fake_custody_payments')->value('idempotency_key'));
            config(['wallet.fake.mode' => 'submitted']);

            // The source and restored environment remain quarantined throughout the drill.
            // No queue worker runs, and both outbound-control switches are captured in the backup.
            DB::table('controls')->whereIn('key', ['transfers_paused', 'withdrawals_paused'])->update(['value' => 'true']);
            $beforeBalances = [$wallet->balances($sender['id']), $wallet->balances($recipient['id'])];
            $beforeJournals = DB::table('journals')->orderBy('id')->get()->toJson();
            $beforePostings = DB::table('postings')->orderBy('id')->get()->toJson();
            $journalCount = DB::table('journals')->count();
            $postingCount = DB::table('postings')->count();
            $cli = [$docker, 'exec', '-i', '-e', 'MYSQL_PWD='.(string) $connection['password'], 'telegram-wallet-mysql-test'];
            $this->runCommand(array_merge($cli, ['mysqldump', '--user='.(string) $connection['username'],
                '--single-transaction', '--no-tablespaces', '--set-gtid-purged=OFF', '--skip-comments', $source]), null, $dump);
            $this->assertGreaterThan(1000, filesize($dump));
            $dumpHash = hash_file('sha256', $dump);
            $this->assertTrue(str_contains(file_get_contents($dump), 'TRIGGER `postings_immutable_update`'), 'The real dump must retain append-only triggers.');
            $this->runCommand(array_merge($cli, ['mysql', '--user='.(string) $connection['username'], $restored]), $dump);
            $this->assertSame($dumpHash, hash_file('sha256', $dump));
            $this->selectDrillDatabase($connection, $restored);

            $this->assertSame($beforeBalances, [$wallet->balances($sender['id']), $wallet->balances($recipient['id'])]);
            $this->assertSame($beforeJournals, DB::table('journals')->orderBy('id')->get()->toJson());
            $this->assertSame($beforePostings, DB::table('postings')->orderBy('id')->get()->toJson());
            $this->assertSame(['true'], DB::table('controls')->whereIn('key', ['transfers_paused', 'withdrawals_paused'])->distinct()->pluck('value')->all());
            $this->assertSame('unknown', DB::table('transfers')->where('id', $transfer['id'])->value('status'));

            // Replay the authenticated original and a newly delivered notification of the same
            // blockchain event. Both inbox and chain-event idempotency must survive restoration.
            $this->ingestAuthenticatedEvent($event);
            $event['event_id'] = 'after-restore-redelivery';
            $this->ingestAuthenticatedEvent($event);
            $this->assertSame(1, DB::table('deposits')->count());
            $this->assertSame(2, DB::table('provider_events')->count());
            $this->assertSame($journalCount, DB::table('journals')->count());
            $this->assertSame($postingCount, DB::table('postings')->count());
            $this->assertSame($beforeBalances, [$wallet->balances($sender['id']), $wallet->balances($recipient['id'])]);
            $this->assertSame($beforeJournals, DB::table('journals')->orderBy('id')->get()->toJson());
            $this->assertSame($beforePostings, DB::table('postings')->orderBy('id')->get()->toJson());

            // Reconcile the original uncertain payment; never submit another payment after restore.
            $provider->setStatus($transfer['id'], 'confirmed');
            $lookupOnly = \Mockery::mock(CustodyProvider::class);
            $lookupOnly->shouldNotReceive('submitWithdrawal');
            $lookupOnly->shouldReceive('withdrawalStatus')->once()->with($transfer['id'])
                ->andReturn($provider->withdrawalStatus($transfer['id']));
            $result = (new WithdrawalService(app(Ledger::class), $lookupOnly))->reconcile($transfer['id']);
            $this->assertSame('completed', $result['status']);
            $this->assertSame($transfer['id'], $result['provider_reference']);
            $this->assertSame(1, DB::table('fake_custody_payments')->count());
            $reconciliation = app(ReconciliationService::class)->run();
            $this->assertTrue($reconciliation['balanced']);
            $this->assertSame('true', DB::table('controls')->where('key', 'transfers_paused')->value('value'));
            $this->assertSame('true', DB::table('controls')->where('key', 'withdrawals_paused')->value('value'));
            try {
                DB::table('postings')->where('id', DB::table('postings')->min('id'))->update(['amount' => '0']);
                $this->fail('Restored journal triggers must reject rewriting history.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('append-only table', $exception->getMessage());
            }
        } finally {
            DB::disconnect('restore_drill');
            DB::setDefaultConnection($originalDefault);
            foreach ($created as $database) {
                $administrator->statement('DROP DATABASE `'.$database.'`');
            }
            unlink($dump);
        }
    }

    private function selectDrillDatabase(array $connection, string $database): void
    {
        // A restore starts a fresh worker process in operations. Mirror that here:
        // database queues cache their original Connection object and must be rebuilt.
        Queue::clearResolvedInstance('queue');
        $this->app->forgetInstance('queue');
        $connection['database'] = $database;
        $connection['url'] = null;
        config(['database.connections.restore_drill' => $connection]);
        DB::purge('restore_drill');
        DB::setDefaultConnection('restore_drill');
    }

    private function ingestAuthenticatedEvent(array $event): void
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $this->call('POST', '/webhooks/custody', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CUSTODY_TIMESTAMP' => $timestamp,
            'HTTP_X_CUSTODY_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, 'restore-drill-secret'),
        ], $body)->assertStatus(202);
        $id = DB::table('provider_events')->where('event_id', $event['event_id'])->value('id');
        $this->assertNotNull($id, 'Authenticated event must be durable before processing.');
        (new ProcessCustodyEvent($id))->handle(app(FakeCustodyProvider::class), app(DepositService::class));
    }

    private function runCommand(array $command, ?string $inputFile = null, ?string $outputFile = null): void
    {
        $descriptors = [0 => $inputFile ? ['file', $inputFile, 'r'] : ['pipe', 'r'],
            1 => $outputFile ? ['file', $outputFile, 'w'] : ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        $this->assertIsResource($process);
        if (! $inputFile) {
            fclose($pipes[0]);
        }
        if (! $outputFile) {
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), 'MySQL backup/restore command failed: '.$stderr);
    }
}
