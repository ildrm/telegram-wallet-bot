<?php

namespace Tests\Feature;

use App\Domain\Ledger;
use App\Domain\WalletService;
use App\Jobs\AllocateDepositAddress;
use App\Jobs\PrepareTelegramQuote;
use App\Jobs\ProcessTelegramUpdate;
use App\Telegram\Amount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 100;

    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.webhook_secret' => str_repeat('s', 40), 'telegram.admin_roles' => [], 'queue.default' => 'database']);
        Queue::fake();
    }

    private function update(string $actor, string $text = '', ?string $callback = null, ?int $updateId = null): array
    {
        $message = ['message_id' => 1, 'from' => ['id' => (int) $actor, 'is_bot' => false, 'username' => 'original'], 'chat' => ['id' => (int) $actor, 'type' => 'private'], 'text' => $text];
        $payload = ['update_id' => $updateId ?? ++$this->sequence];
        if ($callback !== null) {
            $payload['callback_query'] = ['id' => 'callback-'.($payload['update_id']), 'from' => $message['from'], 'message' => $message, 'data' => $callback];
        } else {
            $payload['message'] = $message;
        }

        return $payload;
    }

    private function deliver(array $payload): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', str_repeat('s', 40))->postJson('/webhooks/telegram', $payload)->assertOk();
        $row = DB::table('telegram_updates')->where('update_id', (string) $payload['update_id'])->first();
        if ($row) {
            app()->call([new ProcessTelegramUpdate($row->id), 'handle']);
        }
    }

    private function payload(): array
    {
        return json_decode(DB::table('outbox')->where('type', 'telegram.notify')->orderByDesc('id')->value('payload'), true);
    }

    private function button(string $label): string
    {
        foreach ($this->payload()['reply_markup']['inline_keyboard'] ?? [] as $row) {
            foreach ($row as $button) {
                if ($button['text'] === $label) {
                    return $button['callback_data'];
                }
            }
        }
        $this->fail('Button not found: '.$label);
    }

    private function accepted(string $id): array
    {
        $wallet = app(WalletService::class);

        return $wallet->accept($wallet->register($id)['id']);
    }

    private function funded(string $id): array
    {
        $user = $this->accepted($id);
        DB::transaction(fn () => app(Ledger::class)->post('test-credit:'.$id, 'test_deposit', ['system:custody' => '1000000000000000000', 'user:'.$user['id'].':available' => '-1000000000000000000']));

        return $user;
    }

    public function test_webhook_rejects_bad_secret_and_ignores_nonprivate_or_mismatched_actor(): void
    {
        $payload = $this->update('101', '/start');
        $this->postJson('/webhooks/telegram', $payload)->assertForbidden();
        $payload['message']['chat']['type'] = 'group';
        $this->deliver($payload);
        $payload['update_id']++;
        $payload['message']['chat']['type'] = 'private';
        $payload['message']['chat']['id'] = 102;
        $this->deliver($payload);
        $this->assertDatabaseCount('telegram_updates', 0);
        $this->assertDatabaseCount('wallet_users', 0);
    }

    public function test_duplicate_update_registers_once_and_does_not_duplicate_notification(): void
    {
        $payload = $this->update('101', '/start');
        $this->deliver($payload);
        $this->deliver($payload);
        $this->assertDatabaseCount('wallet_users', 1);
        $this->assertDatabaseCount('telegram_updates', 1);
        $this->assertDatabaseCount('outbox', 1);
        $this->assertStringContainsString('Fake custody', $this->payload()['text']);
        $token = $this->button('I am eligible · Accept v1');
        $this->deliver($this->update('101', callback: $token));
        $this->assertNotNull(DB::table('wallet_users')->value('accepted_at'));
        $this->assertStringContainsString('Available:', $this->payload()['text']);
    }

    public function test_duplicate_update_id_with_changed_body_is_rejected(): void
    {
        $payload = $this->update('101', '/start');
        $this->deliver($payload);
        $payload['message']['text'] = '/withdraw';
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', str_repeat('s', 40))->postJson('/webhooks/telegram', $payload)->assertStatus(409);
        $this->assertDatabaseCount('telegram_updates', 1);
        $this->assertDatabaseCount('outbox', 1);
    }

    public function test_duplicate_update_with_reordered_nested_keys_is_accepted_but_changed_scalar_type_is_rejected(): void
    {
        $payload = $this->update('101', '/start');
        $this->deliver($payload);
        $reordered = array_reverse($payload, true);
        $reordered['message'] = array_reverse($payload['message'], true);
        $reordered['message']['from'] = array_reverse($payload['message']['from'], true);
        $reordered['message']['chat'] = array_reverse($payload['message']['chat'], true);
        $this->deliver($reordered);
        $this->assertDatabaseCount('telegram_updates', 1);
        $this->assertDatabaseCount('wallet_users', 1);
        $this->assertDatabaseCount('outbox', 1);

        $reordered['message']['from']['id'] = '101';
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', str_repeat('s', 40))->postJson('/webhooks/telegram', $reordered)->assertStatus(409);
        $this->assertDatabaseCount('telegram_updates', 1);
        $this->assertDatabaseCount('outbox', 1);
    }

    public function test_callback_is_owned_expiring_and_single_use(): void
    {
        $this->deliver($this->update('101', '/start'));
        $token = $this->button('I am eligible · Accept v1');
        $this->deliver($this->update('102', callback: $token));
        $this->assertStringContainsString('belongs to another', $this->payload()['text']);
        $this->assertNull(DB::table('wallet_users')->where('telegram_id', '102')->value('accepted_at'));
        $this->deliver($this->update('101', callback: $token));
        $this->deliver($this->update('101', callback: $token));
        $this->assertStringContainsString('already used', $this->payload()['text']);
        $this->deliver($this->update('103', '/start'));
        $expired = $this->button('I am eligible · Accept v1');
        $this->travel(11)->minutes();
        $this->deliver($this->update('103', callback: $expired));
        $this->assertNull(DB::table('wallet_users')->where('telegram_id', '103')->value('accepted_at'));
        $this->assertTrue(DB::table('outbox')->where('dedupe_key', 'like', 'telegram:callback:%')->exists());
    }

    public function test_internal_flow_discloses_fees_and_confirmation_cannot_double_spend(): void
    {
        $sender = $this->funded('101');
        $recipient = $this->accepted('102');
        $this->deliver($this->update('101', '/send'));
        $this->deliver($this->update('101', $recipient['wallet_code']));
        $this->deliver($this->update('101', '0.01'));
        $inbox = DB::table('telegram_updates')->orderByDesc('id')->value('id');
        app()->call([new PrepareTelegramQuote($sender['id'], '101', ['kind' => 'internal', 'recipient' => $recipient['wallet_code']], '10000000000000000', $inbox), 'handle']);
        $this->assertStringContainsString('Service fee:', $this->payload()['text']);
        $this->assertStringContainsString('Total deduction:', $this->payload()['text']);
        $confirm = $this->button('Confirm transfer');
        $this->deliver($this->update('101', callback: $confirm));
        $this->assertStringContainsString('Completed', $this->payload()['text']);
        $this->deliver($this->update('101', callback: $confirm));
        $this->assertDatabaseCount('transfers', 1);
        $this->assertSame('10000000000000000', app(WalletService::class)->balances($recipient['id'])['available']);
    }

    public function test_cancel_invalidates_inflight_quote_and_existing_preview(): void
    {
        $sender = $this->funded('101');
        $recipient = $this->accepted('102');
        $this->deliver($this->update('101', '/send'));
        $this->deliver($this->update('101', $recipient['wallet_code']));
        $this->deliver($this->update('101', '0.01'));
        $inbox = DB::table('telegram_updates')->orderByDesc('id')->value('id');
        $this->deliver($this->update('101', '/cancel'));
        app()->call([new PrepareTelegramQuote($sender['id'], '101', ['kind' => 'internal', 'recipient' => $recipient['wallet_code']], '10000000000000000', $inbox), 'handle']);
        $this->assertDatabaseCount('quotes', 0);
        $this->assertSame('cancelled', DB::table('telegram_quote_requests')->where('inbox_id', $inbox)->value('status'));
    }

    public function test_stale_admin_button_rechecks_roles_before_mutation(): void
    {
        $this->accepted('101');
        config(['telegram.admin_roles' => ['101' => ['Operations']]]);
        $this->deliver($this->update('101', '/admin'));
        $this->deliver($this->update('101', callback: $this->button('Emergency pauses')));
        $pause = $this->button('Pause withdrawals');
        config(['telegram.admin_roles' => []]);
        $this->deliver($this->update('101', callback: $pause));
        $this->assertStringContainsString('Permission denied', $this->payload()['text']);
        $this->assertSame('false', DB::table('controls')->where('key', 'withdrawals_paused')->value('value'));
        $this->assertDatabaseHas('audit_logs', ['actor' => '101', 'action' => 'admin.denied']);
    }

    public function test_wrong_network_invalid_amount_and_expired_conversation_never_quote(): void
    {
        $this->accepted('101');
        $this->deliver($this->update('101', '/withdraw'));
        $this->deliver($this->update('101', 'ethereum-mainnet:0x'.str_repeat('1', 40)));
        $this->assertStringContainsString('Invalid destination or network', $this->payload()['text']);
        $this->deliver($this->update('101', 'ethereum-sepolia:0x'.str_repeat('1', 40)));
        $this->deliver($this->update('101', '1e-8'));
        $this->assertStringContainsString('18 decimal places', $this->payload()['text']);
        $this->travel(11)->minutes();
        $this->deliver($this->update('101', '0.1'));
        $this->assertStringContainsString('expired', $this->payload()['text']);
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_receive_address_allocation_is_idempotent_and_copyable(): void
    {
        $user = $this->accepted('101');
        $this->deliver($this->update('101', '/receive'));
        $id = DB::table('telegram_updates')->value('id');
        $job = new AllocateDepositAddress($user['id'], '101', $id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        $this->assertDatabaseCount('deposit_addresses', 1);
        $this->assertSame(1, DB::table('outbox')->where('dedupe_key', 'telegram:receive:'.$id)->count());
        $this->assertArrayHasKey('copy_text', $this->payload()['reply_markup']['inline_keyboard'][0][0]);
        $this->assertStringContainsString('do not send real assets', $this->payload()['text']);
    }

    public function test_username_is_never_a_recipient_or_authorization_identifier(): void
    {
        $this->accepted('101');
        $this->accepted('102');
        $this->deliver($this->update('101', '/send'));
        $this->deliver($this->update('101', '@original'));
        $this->assertStringContainsString('Recipient not found', $this->payload()['text']);
        $this->assertDatabaseCount('quotes', 0);
        $payload = $this->update('101', '/menu');
        $payload['message']['from']['username'] = 'reused_by_somebody';
        $this->deliver($payload);
        $this->assertDatabaseCount('wallet_users', 2);
        $this->assertDatabaseHas('wallet_users', ['telegram_id' => '101', 'username' => 'reused_by_somebody']);
    }

    public function test_support_messages_stay_bound_to_ticket_owner(): void
    {
        $this->accepted('101');
        $this->accepted('102');
        $this->deliver($this->update('101', '/support'));
        $this->deliver($this->update('101', callback: $this->button('Open a ticket')));
        $this->deliver($this->update('101', 'Deposit is delayed.'));
        $view = $this->button('View ticket');
        $this->deliver($this->update('102', callback: $view));
        $this->assertStringContainsString('belongs to another', $this->payload()['text']);
        $this->assertDatabaseCount('support_messages', 1);
    }

    public function test_amount_conversion_is_exact_for_smallest_base_unit(): void
    {
        $this->assertSame('1', Amount::parse('0.000000000000000001'));
        $this->assertSame('0.000000000000000001 ETH', Amount::format('1'));
        $this->assertSame('1000000000000000001', Amount::parse('1.000000000000000001'));
    }

    public function test_deposit_history_is_paginated_and_details_are_owner_scoped(): void
    {
        $user = $this->accepted('101');
        $depositIds = [];
        for ($i = 0; $i < 6; $i++) {
            $depositIds[] = DB::table('deposits')->insertGetId(['user_id' => $user['id'], 'network' => 'ethereum-sepolia', 'asset' => 'ETH', 'txid' => '0x'.str_pad((string) $i, 64, '1'), 'event_index' => 0, 'address' => '0x'.str_repeat('1', 40), 'amount' => '1', 'confirmations' => 2, 'status' => 'pending', 'created_at' => now()->subSeconds(6 - $i), 'updated_at' => now()]);
        }
        $this->deliver($this->update('101', '/history'));
        $depositToken = $this->button('Deposit #'.$depositIds[5]);
        $next = $this->button('Next');
        $this->deliver($this->update('101', callback: $depositToken));
        $this->assertStringContainsString('Confirmations: 2 / 12', $this->payload()['text']);
        $this->deliver($this->update('101', callback: $next));
        $this->assertStringContainsString('page 2', $this->payload()['text']);
        $this->assertSame('Deposit #'.$depositIds[0], $this->payload()['reply_markup']['inline_keyboard'][0][0]['text']);
    }

    public function test_finance_admin_can_review_alerts_but_cannot_freeze(): void
    {
        $this->accepted('101');
        config(['telegram.admin_roles' => ['101' => ['Finance']]]);
        $this->deliver($this->update('101', '/admin'));
        $review = $this->button('Transaction review');
        $alerts = $this->button('Alerts');
        $this->assertStringNotContainsString('Freeze account', json_encode($this->payload()));
        $this->deliver($this->update('101', callback: $review));
        $this->assertStringContainsString('uncertain outcomes first', $this->payload()['text']);
        $this->deliver($this->update('101', callback: $alerts));
        $this->assertStringContainsString('Unknown withdrawals: 0', $this->payload()['text']);
        $this->assertStringContainsString('Latest reconciliation: not run', $this->payload()['text']);
    }

    public function test_settlement_approval_button_requires_distinct_owner_and_fresh_role(): void
    {
        $this->accepted('101');
        $this->accepted('102');
        config(['telegram.admin_roles' => ['101' => ['Owner'], '102' => ['Owner']]]);
        $id = '00000000-0000-4000-8000-000000000001';
        DB::table('fee_settlements')->insert(['id' => $id, 'proposed_by' => '101', 'destination' => '0x'.str_repeat('1', 40), 'amount' => '1', 'network_fee' => '1', 'total' => '2', 'status' => 'proposed', 'created_at' => now(), 'updated_at' => now()]);
        $this->deliver($this->update('101', '/settlements'));
        $this->deliver($this->update('101', callback: $this->button('00000000 · proposed')));
        $this->assertStringNotContainsString('Approve this payout', json_encode($this->payload()));
        $this->deliver($this->update('102', '/settlements'));
        $this->deliver($this->update('102', callback: $this->button('00000000 · proposed')));
        $approve = $this->button('Approve this payout · wait 24h+');
        $this->assertStringContainsString('Destination: 0x'.str_repeat('1', 40), $this->payload()['text']);
        config(['telegram.admin_roles' => ['101' => ['Owner']]]);
        $this->deliver($this->update('102', callback: $approve));
        $this->assertStringContainsString('Permission denied', $this->payload()['text']);
        $this->assertSame('proposed', DB::table('fee_settlements')->where('id', $id)->value('status'));
    }
}
