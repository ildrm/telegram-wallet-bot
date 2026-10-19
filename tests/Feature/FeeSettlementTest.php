<?php

namespace Tests\Feature;

use App\Custody\FakeCustodyProvider;
use App\Domain\DepositService;
use App\Domain\Ledger;
use App\Domain\ReconciliationService;
use App\Domain\WalletService;
use App\Finance\FeeSettlementService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FeeSettlementTest extends TestCase
{
    use RefreshDatabase;

    private function earn(): array
    {
        config(['telegram.admin_roles' => ['9001' => ['Finance'], '9002' => ['Owner'], '9003' => ['Support']],
            'wallet.owner_destination' => '0x'.str_repeat('c', 40),
            'wallet.fees.internal' => ['fixed' => '100000000000000000', 'bps' => 0, 'min' => '0', 'max' => null]]);
        $wallet = app(WalletService::class);
        $a = $wallet->register('1001');
        $wallet->accept($a['id']);
        $b = $wallet->register('1002');
        $wallet->accept($b['id']);
        $address = app(FakeCustodyProvider::class)->allocateAddress($a['wallet_code']);
        DB::table('deposit_addresses')->insert(['wallet_user_id' => $a['id'], 'address' => $address, 'created_at' => now()]);
        $event = ['asset' => 'ETH', 'network' => 'ethereum-sepolia', 'txid' => '0x'.str_repeat('a', 64), 'index' => 0,
            'address' => $address, 'amount' => '2000000000000000000', 'confirmations' => 12, 'removed' => false];
        app(FakeCustodyProvider::class)->observeDeposit($event);
        app(DepositService::class)->ingest($event);
        $q = $wallet->quote($a['id'], 'internal', '1000000000000000000', $b['wallet_code']);
        $wallet->confirm($a['id'], $q['id']);
        $this->assertTrue(app(ReconciliationService::class)->run()['balanced']);

        return [$a, $b];
    }

    public function test_two_person_delayed_settlement_spends_only_platform_revenue(): void
    {
        [$a,$b] = $this->earn();
        $service = app(FeeSettlementService::class);
        $beforeA = app(WalletService::class)->balances($a['id']);
        $beforeB = app(WalletService::class)->balances($b['id']);
        $s = $service->propose('9001', '50000000000000000', 'test-request');
        $this->assertSame($s['id'], $service->propose('9001', '50000000000000000', 'test-request')['id']);
        $s = $service->approve('9002', $s['id']);
        $this->assertSame('approved', $s['status']);
        $service->process($s['id']);
        $this->assertDatabaseCount('fake_custody_payments', 0);
        $this->travel(25)->hours();
        config(['wallet.fake.mode' => 'confirmed']);
        $this->assertSame('completed', $service->process($s['id'])['status']);
        $this->assertSame('completed', $service->process($s['id'])['status']);
        $this->assertDatabaseCount('fake_custody_payments', 1);
        $this->assertSame('0', app(Ledger::class)->balance('system:fee_payable'));
        $this->assertSame($beforeA, app(WalletService::class)->balances($a['id']));
        $this->assertSame($beforeB, app(WalletService::class)->balances($b['id']));
        $this->assertTrue(app(ReconciliationService::class)->run()['balanced']);
    }

    public function test_cannot_settle_customer_liabilities_as_fees(): void
    {
        $this->earn();
        $service = app(FeeSettlementService::class);
        $s = $service->propose('9001', '1000000000000000000');
        $this->expectException(DomainException::class);
        $service->approve('9002', $s['id']);
    }

    public function test_approval_requires_different_actor_and_rechecked_permission(): void
    {
        $this->earn();
        $service = app(FeeSettlementService::class);
        config(['telegram.admin_roles.9001' => ['Owner', 'Finance']]);
        $s = $service->propose('9001', '10000000000000000');
        try {
            $service->approve('9001', $s['id']);
            $this->fail('Self approval');
        } catch (DomainException $e) {
            $this->assertStringContainsString('different', $e->getMessage());
        }
        $this->expectException(DomainException::class);
        $service->approve('9003', $s['id']);
    }

    public function test_destination_substitution_holds_reserved_platform_funds(): void
    {
        $this->earn();
        $service = app(FeeSettlementService::class);
        $s = $service->propose('9001', '10000000000000000');
        $service->approve('9002', $s['id']);
        $this->travel(25)->hours();
        config(['wallet.owner_destination' => '0x'.str_repeat('d', 40)]);
        $this->assertSame('manual_review', $service->process($s['id'])['status']);
        $this->assertDatabaseCount('fake_custody_payments', 0);
        $this->assertNotSame('0', app(Ledger::class)->balance('system:fee_payable'));
    }

    public function test_unknown_owner_submission_looks_up_original_payment_and_never_resends(): void
    {
        $this->earn();
        $service = app(FeeSettlementService::class);
        $s = $service->propose('9001', '10000000000000000');
        $service->approve('9002', $s['id']);
        $this->travel(25)->hours();
        config(['wallet.fake.mode' => 'timeout_after_submit']);
        $this->assertSame('unknown', $service->process($s['id'])['status']);
        $this->assertSame('broadcast', $service->process($s['id'])['status']);
        $this->assertDatabaseCount('fake_custody_payments', 1);
        app(FakeCustodyProvider::class)->setStatus('settlement:'.$s['id'], 'confirmed');
        $this->assertSame('completed', $service->process($s['id'])['status']);
        $this->assertTrue(app(ReconciliationService::class)->run()['balanced']);
    }
}
