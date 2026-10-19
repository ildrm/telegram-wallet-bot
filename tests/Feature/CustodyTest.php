<?php

namespace Tests\Feature;

use App\Custody\CustodyProvider;
use App\Custody\FakeCustodyProvider;
use App\Custody\UnknownSubmission;
use App\Jobs\ProcessCustodyEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CustodyTest extends TestCase
{
    use RefreshDatabase;

    private function event(): array
    {
        return ['event_id' => 'event-1', 'asset' => 'ETH', 'network' => 'ethereum-sepolia',
            'txid' => '0x'.str_repeat('a', 64), 'index' => 0, 'address' => '0x'.str_repeat('b', 40),
            'amount' => '999999999999999999', 'confirmations' => 12, 'removed' => false];
    }

    public function test_forged_custody_webhooks_and_expired_signatures_are_rejected(): void
    {
        config(['wallet.custody_webhook_secret' => 'sandbox-secret']);
        $this->postJson('/webhooks/custody', $this->event())->assertForbidden();
        $body = json_encode($this->event());
        $timestamp = (string) (time() - 301);
        $this->call('POST', '/webhooks/custody', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_CUSTODY_TIMESTAMP' => $timestamp, 'HTTP_X_CUSTODY_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, 'sandbox-secret')], $body)->assertForbidden();
        $this->assertDatabaseCount('provider_events', 0);
    }

    public function test_authenticated_duplicate_event_is_durable_and_changed_payload_rejected(): void
    {
        Queue::fake();
        config(['wallet.custody_webhook_secret' => 'sandbox-secret']);
        $event = $this->event();
        foreach ([$event, $event] as $data) {
            $this->sendEvent($data)->assertStatus(202);
        }
        $this->assertDatabaseCount('provider_events', 1);
        Queue::assertPushed(ProcessCustodyEvent::class);
        $event['amount'] = '1';
        $this->sendEvent($event)->assertStatus(409);
        $this->assertDatabaseCount('provider_events', 1);
    }

    private function sendEvent(array $event)
    {
        $body = json_encode($event);
        $timestamp = (string) time();

        return $this->call('POST', '/webhooks/custody', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_CUSTODY_TIMESTAMP' => $timestamp, 'HTTP_X_CUSTODY_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, 'sandbox-secret')], $body);
    }

    public function test_fake_provider_timeout_after_submit_preserves_original_identifier(): void
    {
        $provider = app(FakeCustodyProvider::class);
        $provider->observeDeposit($this->event());
        config(['wallet.fake.mode' => 'timeout_after_submit']);
        try {
            $provider->submitWithdrawal('one-payment', '0x'.str_repeat('c', 40), '100', '21000000000000');
            $this->fail('Expected uncertain submission');
        } catch (UnknownSubmission) {
        }
        $this->assertSame('submitted', $provider->withdrawalStatus('one-payment')['status']);
        config(['wallet.fake.mode' => 'submitted']);
        $provider->submitWithdrawal('one-payment', '0x'.str_repeat('c', 40), '100', '21000000000000');
        $this->assertDatabaseCount('fake_custody_payments', 1);
        $provider->setStatus('one-payment', 'confirmed');
        $provider->setStatus('one-payment', 'confirmed');
        $this->assertSame('999978999999999899', $provider->balance());
    }

    public function test_out_of_order_confirmation_counts_do_not_duplicate_provider_assets(): void
    {
        $p = app(FakeCustodyProvider::class);
        $e = $this->event();
        $p->observeDeposit($e);
        $e['confirmations'] = 1;
        $p->observeDeposit($e);
        $e['confirmations'] = 12;
        $p->observeDeposit($e);
        $this->assertSame($e['amount'], $p->balance());
        $e['removed'] = true;
        $p->observeDeposit($e);
        $p->observeDeposit($e);
        $this->assertSame('0', $p->balance());
        $e['removed'] = false;
        $p->observeDeposit($e);
        $this->assertSame('0', $p->balance());
    }

    public function test_real_custody_cannot_be_enabled_by_configuration(): void
    {
        config(['wallet.live_enabled' => true]);
        $this->app->forgetInstance(CustodyProvider::class);
        $this->expectException(\RuntimeException::class);
        app(CustodyProvider::class);
    }
}
