<?php

namespace Tests\Feature;

use App\Domain\Ledger;
use App\Domain\WalletService;
use App\Jobs\DeliverOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_failure_leaves_ledger_untouched_and_can_retry(): void
    {
        config(['telegram.bot_token' => 'test-token']);
        $user = app(WalletService::class)->register('1001');
        DB::transaction(fn () => app(Ledger::class)->post('delivery:fund', 'deposit', [
            'system:custody' => '1000000000000000000', 'user:'.$user['id'].':available' => '-1000000000000000000']));
        Ledger::outbox('telegram.notify', 'notification-1', ['chat_id' => '1001', 'text' => 'Posted successfully']);
        Http::fake(['*' => Http::sequence()->push(['ok' => false], 503)->push(['ok' => true], 200)]);
        $id = DB::table('outbox')->value('id');
        try {
            app()->call([new DeliverOutbox($id), 'handle']);
            $this->fail('Transport failure expected');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('test-token', $e->getMessage());
        }
        $this->assertNull(DB::table('outbox')->where('id', $id)->value('delivered_at'));
        $this->assertDatabaseCount('journals', 1);
        $this->assertSame('1000000000000000000', app(Ledger::class)->balance('system:custody'));
        $this->travel(2)->minutes();
        app()->call([new DeliverOutbox($id), 'handle']);
        $this->assertNotNull(DB::table('outbox')->where('id', $id)->value('delivered_at'));
        app()->call([new DeliverOutbox($id), 'handle']);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('journals', 1);
    }

    public function test_web_backend_exposes_only_two_post_callbacks(): void
    {
        $this->get('/')->assertNotFound();
        $this->get('/admin')->assertNotFound();
        $this->get('/storage/test')->assertNotFound();
    }
}
