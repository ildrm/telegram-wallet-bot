<?php

// Integration-test worker only. No HTTP route invokes this script.
use App\Domain\WalletService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $userId, $quoteId, $barrierDirectory, $slot] = $argv;

try {
    if (DB::getDriverName() !== 'mysql' || ! is_dir($barrierDirectory)) {
        throw new RuntimeException('Concurrency worker requires MySQL and an existing barrier directory.');
    }
    // Establish every independent connection before releasing any confirmation.
    $connectionId = (string) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
    file_put_contents($barrierDirectory.'/ready-'.$slot, $connectionId, LOCK_EX);
    $deadline = microtime(true) + 60;
    while (! is_file($barrierDirectory.'/release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timed out.');
        }
        usleep(10000);
        clearstatcache(true, $barrierDirectory.'/release');
    }
    try {
        $transfer = $app->make(WalletService::class)->confirm((int) $userId, $quoteId);
        echo json_encode(['status' => 'reserved', 'transfer_id' => $transfer['id'], 'quote_id' => $quoteId, 'connection_id' => $connectionId], JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (DomainException $exception) {
        if ($exception->getMessage() !== 'Insufficient available balance including fees.') {
            throw $exception;
        }
        echo json_encode(['status' => 'insufficient', 'quote_id' => $quoteId, 'connection_id' => $connectionId], JSON_THROW_ON_ERROR).PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
