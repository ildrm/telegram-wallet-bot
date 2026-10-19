<?php

namespace App\Providers;

use App\Custody\CustodyProvider;
use App\Custody\FakeCustodyProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CustodyProvider::class, function () {
            if (config('wallet.live_enabled') || config('wallet.custody_driver') !== 'fake'
                || config('wallet.network') !== 'ethereum-sepolia' || config('wallet.asset') !== 'ETH') {
                throw new \RuntimeException('Live custody is disabled. Only fake ETH/Sepolia custody is implemented.');
            }

            return new FakeCustodyProvider;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $queueDatabase = config('queue.connections.database.connection');
        if ($queueDatabase !== null && $queueDatabase !== config('database.default')) {
            throw new \RuntimeException('Wallet inboxes, financial writes and database queue must share one database connection.');
        }
        RateLimiter::for('webhooks', fn ($request) => Limit::perMinute(300)->by($request->ip()));
        // Fail closed even for commands and webhook handlers which do not resolve the adapter.
        if (config('wallet.live_enabled') || config('wallet.custody_driver') !== 'fake'
            || config('wallet.network') !== 'ethereum-sepolia' || config('wallet.asset') !== 'ETH') {
            throw new \RuntimeException('This release supports fake ETH/Sepolia custody only.');
        }
    }
}
