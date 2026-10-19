<?php

namespace Tests\Feature;

use App\Custody\CustodyProvider;
use App\Custody\FakeCustodyProvider;
use App\Domain\Amount;
use App\Domain\DepositService;
use App\Domain\FeePolicy;
use App\Domain\Ledger;
use App\Domain\ReconciliationService;
use App\Domain\WalletService;
use App\Domain\WithdrawalService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallet;

    private FakeCustodyProvider $fake;

    private const DEST = '0x1111111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['wallet.fees.internal' => ['fixed' => '10', 'bps' => 0], 'wallet.fees.withdrawal' => ['fixed' => '10', 'bps' => 0], 'wallet.fake.network_fee' => '5', 'wallet.fake.mode' => 'submitted', 'telegram.admin_roles' => ['999' => ['Owner']]]);
        $this->wallet = app(WalletService::class);
        $this->fake = app(FakeCustodyProvider::class);
    }

    private function user(string $id = '100'): array
    {
        return $this->wallet->accept($this->wallet->register($id, 'alice')['id']);
    }

    private function deposit(array $user, string $amount = '1000', int $confirmations = 12, string $seed = 'deposit'): array
    {
        $address = $this->fake->allocateAddress($user['wallet_code']);
        DB::table('deposit_addresses')->insertOrIgnore(['wallet_user_id' => $user['id'], 'address' => $address, 'created_at' => now()]);
        $event = ['asset' => 'ETH', 'network' => 'ethereum-sepolia', 'txid' => '0x'.hash('sha256', $seed), 'address' => $address, 'amount' => $amount, 'index' => 0, 'confirmations' => $confirmations, 'removed' => false];
        $this->fake->observeDeposit($event);
        app(DepositService::class)->ingest($event);

        return $event;
    }

    private function withdrawal(array $u, string $amount = '100'): array
    {
        $q = $this->wallet->quote($u['id'], 'withdrawal', $amount, null, self::DEST);

        return $this->wallet->confirm($u['id'], $q['id']);
    }

    private function assertInvariant(): void
    {
        foreach (DB::table('journals')->get() as $j) {
            $sum = '0';
            foreach (DB::table('postings')->where('journal_id', $j->id)->get() as $p) {
                $sum = Amount::add($sum, (string) $p->amount);
            }
            $this->assertSame('0', $sum);
        }
    }

    public function test_registration_is_idempotent_and_username_never_authenticates(): void
    {
        $a = $this->wallet->register('100', 'alice');
        $b = $this->wallet->register('100', 'renamed');
        $c = $this->wallet->register('200', 'alice');
        $this->assertSame($a['id'], $b['id']);
        $this->assertNotSame($a['id'], $c['id']);
        $this->assertSame('renamed', $b['username']);
        $this->assertSame(6, DB::table('ledger_accounts')->whereNotNull('wallet_user_id')->count());
    }

    public function test_deposit_confirmation_delay_duplicate_and_replay(): void
    {
        $u = $this->user();
        $event = $this->deposit($u, '1000000000000000000000000000001', 11);
        $this->assertSame('0', $this->wallet->balances($u['id'])['available']);
        $this->assertSame($event['amount'], $this->wallet->balances($u['id'])['pending']);
        $event['confirmations'] = 12;
        for ($i = 0; $i < 4; $i++) {
            $this->fake->observeDeposit($event);
            app(DepositService::class)->ingest($event);
        }
        $this->assertSame($event['amount'], $this->wallet->balances($u['id'])['available']);
        $this->assertDatabaseCount('journals', 1);
        $this->assertTrue(app(ReconciliationService::class)->run()['balanced']);
        $this->assertInvariant();
    }

    public function test_internal_transfer_double_click_and_exact_fee(): void
    {
        $u = $this->user();
        $v = $this->user('200');
        $this->deposit($u);
        $q = $this->wallet->quote($u['id'], 'internal', '100', $v['wallet_code']);
        $t = $this->wallet->confirm($u['id'], $q['id']);
        $again = $this->wallet->confirm($u['id'], $q['id']);
        $this->assertSame($t['id'], $again['id']);
        $this->assertSame('890', $this->wallet->balances($u['id'])['available']);
        $this->assertSame('100', $this->wallet->balances($v['id'])['available']);
        $this->assertSame('-10', app(Ledger::class)->balance('system:revenue'));
        $this->assertDatabaseCount('transfers', 1);
        $this->assertInvariant();
    }

    public function test_stale_quote_does_not_reserve_funds(): void
    {
        $u = $this->user();
        $this->deposit($u);
        $q = $this->wallet->quote($u['id'], 'withdrawal', '100', null, self::DEST);
        $this->travel(121)->seconds();
        try {
            $this->wallet->confirm($u['id'], $q['id']);
            $this->fail('Expired quote accepted');
        } catch (DomainException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }
        $this->assertSame('1000', $this->wallet->balances($u['id'])['available']);
        $this->assertDatabaseCount('transfers', 0);
    }

    public function test_changed_fee_version_rejects_an_unconsumed_quote(): void
    {
        $u = $this->user();
        $this->deposit($u);
        $q = $this->wallet->quote($u['id'], 'withdrawal', '100', null, self::DEST);
        config(['wallet.fees.version' => 'new-policy']);
        try {
            $this->wallet->confirm($u['id'], $q['id']);
            $this->fail('Stale policy accepted');
        } catch (DomainException $e) {
            $this->assertStringContainsString('policy changed', $e->getMessage());
        }
        $this->assertSame('1000', $this->wallet->balances($u['id'])['available']);
    }

    public function test_provider_outage_after_reservation_never_refunds_or_resends_uncertain_funds(): void
    {
        $u = $this->user();
        $this->deposit($u);
        $t = $this->withdrawal($u);
        config(['wallet.fake.mode' => 'down']);
        $this->assertSame('unknown', app(WithdrawalService::class)->process($t['id'])['status']);
        config(['wallet.fake.mode' => 'submitted']);
        $this->assertSame('unknown', app(WithdrawalService::class)->process($t['id'])['status']);
        $this->assertSame('115', $this->wallet->balances($u['id'])['reserved']);
        $this->assertDatabaseCount('fake_custody_payments', 0);
    }

    public function test_wrong_network_and_invalid_address_never_create_spendable_funds_or_quotes(): void
    {
        $u = $this->user();
        $e = $this->deposit($u, '1000', 0);
        $e['network'] = 'ethereum-mainnet';
        try {
            app(DepositService::class)->ingest($e);
            $this->fail('Wrong network accepted');
        } catch (DomainException) {
        }
        $this->assertSame('0', $this->wallet->balances($u['id'])['available']);
        foreach (['not-an-address', '0x'.str_repeat('0', 40), '0x'.str_repeat('A', 40)] as $address) {
            try {
                $this->wallet->quote($u['id'], 'withdrawal', '100', null, $address);
                $this->fail('Bad address accepted');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_reconciliation_discrepancy_pauses_transfers_and_does_not_edit_history(): void
    {
        $u = $this->user();
        $this->deposit($u);
        DB::table('fake_custody_balances')->where('id', 1)->update(['amount' => '999']);
        $this->assertFalse(app(ReconciliationService::class)->run()['balanced']);
        $this->assertDatabaseHas('controls', ['key' => 'transfers_paused', 'value' => 'true']);
        $this->assertSame('1000', $this->wallet->balances($u['id'])['available']);
        $this->assertDatabaseCount('journals', 1);
    }

    public function test_twenty_competing_quotes_recheck_available_funds_on_confirmation(): void
    {
        // Deterministic race model: all 20 previews see one balance, each confirm must recheck under locks.
        // Real simultaneous MySQL sessions are a distinct required launch check.
        $u = $this->user();
        $this->deposit($u, '1000');
        $quotes = [];
        for ($i = 0; $i < 20; $i++) {
            $quotes[] = $this->wallet->quote($u['id'], 'withdrawal', '100', null, self::DEST);
        }
        $accepted = 0;
        foreach ($quotes as $q) {
            try {
                $this->wallet->confirm($u['id'], $q['id']);
                $accepted++;
            } catch (DomainException) {
            }
        }
        $this->assertSame(8, $accepted);
        $this->assertSame('80', $this->wallet->balances($u['id'])['available']);
        $this->assertSame('920', $this->wallet->balances($u['id'])['reserved']);
        $this->assertInvariant();
    }

    public function test_unknown_submission_reconciles_original_and_never_sends_twice(): void
    {
        $u = $this->user();
        $this->deposit($u);
        $t = $this->withdrawal($u);
        config(['wallet.fake.mode' => 'timeout_after_submit']);
        $this->assertSame('unknown', app(WithdrawalService::class)->process($t['id'])['status']);
        $this->assertSame('115', $this->wallet->balances($u['id'])['reserved']);
        config(['wallet.fake.mode' => 'submitted']);
        $this->assertSame('broadcast', app(WithdrawalService::class)->process($t['id'])['status']);
        $this->fake->setStatus($t['id'], 'confirmed', '3');
        $done = app(WithdrawalService::class)->process($t['id']);
        app(WithdrawalService::class)->process($t['id']);
        $this->assertSame('completed', $done['status']);
        $this->assertSame('887', $this->wallet->balances($u['id'])['available']);
        $this->assertSame('0', $this->wallet->balances($u['id'])['reserved']);
        $this->assertDatabaseCount('fake_custody_payments', 1);
        $this->assertSame('-10', app(Ledger::class)->balance('system:revenue'));
        $this->assertTrue(app(ReconciliationService::class)->run()['balanced']);
        $this->assertInvariant();
    }

    public function test_provider_failure_refunds_reserve_once_and_earns_no_revenue(): void
    {
        $u = $this->user();
        $this->deposit($u);
        $t = $this->withdrawal($u);
        config(['wallet.fake.mode' => 'failed']);
        app(WithdrawalService::class)->process($t['id']);
        app(WithdrawalService::class)->process($t['id']);
        $this->assertSame('1000', $this->wallet->balances($u['id'])['available']);
        $this->assertSame('0', app(Ledger::class)->balance('system:revenue'));
        $this->assertDatabaseCount('journals', 3);
        $this->assertInvariant();
    }

    public function test_reorg_after_internal_spend_freezes_source_and_global_transfers(): void
    {
        $u = $this->user();
        $v = $this->user('200');
        $event = $this->deposit($u);
        $q = $this->wallet->quote($u['id'], 'internal', '900', $v['wallet_code']);
        $this->wallet->confirm($u['id'], $q['id']);
        $event['removed'] = true;
        $this->fake->observeDeposit($event);
        app(DepositService::class)->ingest($event);
        app(DepositService::class)->ingest($event);
        $this->assertSame('-910', $this->wallet->balances($u['id'])['available']);
        $this->assertDatabaseHas('wallet_users', ['id' => $u['id'], 'frozen' => true]);
        $this->assertDatabaseHas('controls', ['key' => 'transfers_paused', 'value' => 'true']);
        $this->assertFalse(app(ReconciliationService::class)->run()['balanced']);
        $this->assertInvariant();
    }

    public function test_held_funds_cannot_spend_and_admin_is_independently_checked(): void
    {
        $u = $this->user();
        $this->deposit($u);
        try {
            $this->wallet->hold($u['id'], '950', false, 123);
            $this->fail('Unauthorised hold');
        } catch (DomainException) {
        }
        $this->wallet->hold($u['id'], '950', false, 999);
        $this->assertSame('50', $this->wallet->balances($u['id'])['available']);
        try {
            $this->withdrawal($u);
            $this->fail('Spent held balance');
        } catch (DomainException) {
        }
        $this->wallet->hold($u['id'], '950', true, 999);
        $this->assertSame('1000', $this->wallet->balances($u['id'])['available']);
        $this->assertInvariant();
    }

    public function test_failed_reconciliation_blocks_reserved_submission_even_if_controls_are_reopened(): void
    {
        $u = $this->user();
        $this->deposit($u);
        $t = $this->withdrawal($u);
        DB::table('fake_custody_balances')->where('id', 1)->update(['amount' => '999']);
        app(ReconciliationService::class)->run();
        DB::table('controls')->update(['value' => 'false']);
        $this->assertSame('reserved', app(WithdrawalService::class)->process($t['id'])['status']);
        $this->assertDatabaseCount('fake_custody_payments', 0);
    }

    public function test_failed_payment_with_chain_cost_is_held_for_review(): void
    {
        $u = $this->user();
        $this->deposit($u);
        $t = $this->withdrawal($u);
        $provider = \Mockery::mock(CustodyProvider::class);
        $provider->shouldReceive('submitWithdrawal')->once()->andReturn(['id' => $t['id'], 'status' => 'failed', 'network_fee' => '3']);
        $service = new WithdrawalService(app(Ledger::class), $provider);
        $this->assertSame('manual_review', $service->process($t['id'])['status']);
        $this->assertSame('115', $this->wallet->balances($u['id'])['reserved']);
        $this->assertSame('0', app(Ledger::class)->balance('system:revenue'));
        $this->assertDatabaseHas('controls', ['key' => 'withdrawals_paused', 'value' => 'true']);
    }

    public function test_journal_idempotency_rejects_conflicting_intent(): void
    {
        $entries = ['system:custody' => '10', 'system:revenue' => '-10'];
        $id = DB::transaction(fn () => app(Ledger::class)->post('unique-ref', 'test', $entries));
        $this->assertSame($id, DB::transaction(fn () => app(Ledger::class)->post('unique-ref', 'test', $entries)));
        $this->expectException(DomainException::class);
        DB::transaction(fn () => app(Ledger::class)->post('unique-ref', 'test', ['system:custody' => '11', 'system:revenue' => '-11']));
    }

    public function test_network_fee_overrun_never_exceeds_customer_authorization(): void
    {
        $u = $this->user();
        $this->deposit($u);
        $t = $this->withdrawal($u);
        app(WithdrawalService::class)->process($t['id']);
        $this->fake->setStatus($t['id'], 'confirmed', '8');
        app(WithdrawalService::class)->process($t['id']);
        $this->assertSame('885', $this->wallet->balances($u['id'])['available']);
        $this->assertDatabaseHas('controls', ['key' => 'withdrawals_paused', 'value' => 'true']);
        $this->assertSame('8', app(Ledger::class)->balance('system:network_expense'));
        $this->assertSame('-5', app(Ledger::class)->balance('system:network_recovery'));
        $this->assertTrue(app(ReconciliationService::class)->run()['balanced']);
        $this->assertInvariant();
    }

    public function test_fee_rounding_and_policy_min_max_are_exact(): void
    {
        config(['wallet.fees.internal' => ['fixed' => '2', 'bps' => 1, 'min' => '5', 'max' => '10']]);
        $fees = app(FeePolicy::class);
        $this->assertSame('5', $fees->calculate('internal', '1'));
        $this->assertSame('6', $fees->calculate('internal', '30001'));
        $this->assertSame('10', $fees->calculate('internal', '99999999999999999999999999999'));
    }

    public function test_a_balanced_journal_cannot_mix_assets(): void
    {
        DB::table('ledger_accounts')->insert(['code' => 'unsupported:btc', 'asset' => 'BTC', 'kind' => 'custody', 'created_at' => now()]);
        $this->expectException(DomainException::class);
        DB::transaction(fn () => app(Ledger::class)->post('mixed', 'test', ['system:custody' => '10', 'unsupported:btc' => '-10']));
    }

    public function test_ledger_rejects_unbalanced_journal_and_database_rejects_rewriting(): void
    {
        try {
            DB::transaction(fn () => app(Ledger::class)->post('bad', 'bad', ['system:custody' => '1', 'system:revenue' => '0']));
            $this->fail('Unbalanced journal');
        } catch (DomainException) {
        }
        $u = $this->user();
        $this->deposit($u);
        $this->expectException(QueryException::class);
        DB::table('postings')->update(['amount' => '0']);
    }
}
