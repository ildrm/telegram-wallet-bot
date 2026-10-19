<?php

namespace App\Finance;

use App\Custody\CustodyProvider;
use App\Domain\Amount;
use App\Domain\Ledger;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Batch settlement of reconciled platform equity; never debits a customer account. */
final class FeeSettlementService
{
    public function __construct(private Ledger $ledger, private CustodyProvider $custody) {}

    public function propose(string $actorTelegramId, string $amountBaseUnits, ?string $requestKey = null): array
    {
        $this->authorize($actorTelegramId, ['Owner', 'Finance']);
        $amount = Amount::unsigned($amountBaseUnits, true);
        if ($requestKey !== null && strlen($requestKey) > 200) {
            throw new DomainException('Invalid proposal request.');
        }
        $existing = $requestKey ? DB::table('fee_settlements')->where('request_key', $requestKey)->first() : null;
        if ($existing) {
            return $this->matchingProposal($existing, $actorTelegramId, $amount);
        }
        $destination = (string) config('wallet.owner_destination');
        if (! $this->custody->validateAddress($destination)) {
            throw new DomainException('No approved sandbox owner destination configured.');
        }
        if (DB::table('deposit_addresses')->where('address', $destination)->exists()) {
            throw new DomainException('Owner payout must be an external destination.');
        }
        $fee = Amount::unsigned($this->custody->quoteWithdrawal($destination, $amount)['network_fee']);

        return DB::transaction(function () use ($actorTelegramId, $amount, $destination, $fee, $requestKey) {
            Ledger::controls();
            $existing = $requestKey ? DB::table('fee_settlements')->where('request_key', $requestKey)->first() : null;
            if ($existing) {
                return $this->matchingProposal($existing, $actorTelegramId, $amount);
            }
            $id = (string) Str::uuid();
            DB::table('fee_settlements')->insert(['id' => $id, 'request_key' => $requestKey, 'proposed_by' => $actorTelegramId, 'destination' => $destination,
                'amount' => $amount, 'network_fee' => $fee, 'total' => Amount::add($amount, $fee), 'status' => 'proposed', 'created_at' => now(), 'updated_at' => now()]);
            Ledger::audit($actorTelegramId, 'settlement.proposed', $id, ['destination' => $destination, 'amount' => $amount]);

            return (array) DB::table('fee_settlements')->where('id', $id)->first();
        }, 3);
    }

    public function approve(string $actorTelegramId, string $id): array
    {
        $this->authorize($actorTelegramId, ['Owner']);

        return DB::transaction(function () use ($actorTelegramId, $id) {
            $controls = Ledger::controls();
            $s = DB::table('fee_settlements')->where('id', $id)->lockForUpdate()->first();
            if (! $s) {
                throw new DomainException('Settlement unavailable.');
            }
            if ($s->proposed_by === $actorTelegramId) {
                throw new DomainException('A different authorized owner must approve this payout.');
            }
            if ($s->status !== 'proposed') {
                throw new DomainException('This payout proposal is no longer pending.');
            }
            if (now()->gt(Carbon::parse($s->created_at)->addDay())) {
                throw new DomainException('Payout proposal expired.');
            }
            $this->checkSafe($s, $controls);
            $this->ledger->post('settlement:'.$id.':reserve', 'fee.settlement.reserve', [
                'system:revenue' => (string) $s->total, 'system:fee_payable' => Amount::neg((string) $s->total)], ['settlement_id' => $id]);
            DB::table('fee_settlements')->where('id', $id)->update(['approved_by' => $actorTelegramId, 'status' => 'approved',
                'eligible_at' => now()->addHours(max(24, (int) config('wallet.settlement_delay_hours', 24))), 'updated_at' => now()]);
            Ledger::audit($actorTelegramId, 'settlement.approved', $id, ['destination' => $s->destination]);

            return (array) DB::table('fee_settlements')->where('id', $id)->first();
        }, 3);
    }

    public function process(string $id): array
    {
        $actual = Amount::unsigned($this->custody->balance());
        [$s,$submit] = DB::transaction(function () use ($id, $actual) {
            $controls = Ledger::controls();
            $s = DB::table('fee_settlements')->where('id', $id)->lockForUpdate()->first();
            if (! $s) {
                throw new DomainException('Settlement unavailable.');
            }
            if (in_array($s->status, ['completed', 'failed', 'manual_review', 'proposed'], true)) {
                return [$s, false];
            }
            if ($s->status === 'approved') {
                if (now()->lt($s->eligible_at)) {
                    return [$s, false];
                }
                if ($s->destination !== config('wallet.owner_destination')) {
                    DB::table('fee_settlements')->where('id', $id)->update(['status' => 'manual_review', 'updated_at' => now()]);
                    Ledger::audit('system', 'settlement.destination_changed', $id);
                    $s->status = 'manual_review';

                    return [$s, false];
                }
                $this->authorize($s->proposed_by, ['Owner', 'Finance']);
                $this->authorize($s->approved_by, ['Owner']);
                if (($controls['transfers_paused'] ?? 'true') === 'true' || ($controls['withdrawals_paused'] ?? 'true') === 'true') {
                    return [$s, false];
                }
                if (DB::table('transfers')->where('kind', 'withdrawal')->whereNotIn('status', ['completed', 'failed'])->exists()) {
                    return [$s, false];
                }
                if (Amount::cmp($actual, $this->ledger->balance('system:custody')) !== 0 || Amount::cmp(Amount::sub($actual, (string) $s->total), $this->liabilities()) < 0) {
                    DB::table('controls')->where('key', 'withdrawals_paused')->update(['value' => 'true', 'updated_at' => now()]);
                    Ledger::audit('system', 'settlement.backing_discrepancy', $id);

                    return [$s, false];
                }
                DB::table('fee_settlements')->where('id', $id)->update(['status' => 'submitting', 'updated_at' => now()]);
                $s->status = 'submitting';

                return [$s, true];
            }

            return [$s, false];
        }, 3);
        if (in_array($s->status, ['completed', 'failed', 'manual_review', 'proposed', 'approved'], true)) {
            return (array) $s;
        }
        try {
            $result = $submit ? $this->custody->submitWithdrawal('settlement:'.$id, $s->destination, (string) $s->amount, (string) $s->network_fee)
                : $this->custody->withdrawalStatus('settlement:'.$id);
        } catch (\Throwable) {
            $result = null;
        }

        return DB::transaction(function () use ($id, $result) {
            Ledger::controls();
            $s = DB::table('fee_settlements')->where('id', $id)->lockForUpdate()->first();
            if (in_array($s->status, ['completed', 'failed', 'manual_review'], true)) {
                return (array) $s;
            }
            $updates = ['status' => 'unknown', 'updated_at' => now()];
            if (isset($result['id'])) {
                $updates['provider_reference'] = $result['id'];
            }
            if (isset($result['txid'])) {
                $updates['txid'] = $result['txid'];
            }
            if (($result['status'] ?? '') === 'confirmed') {
                if (! isset($result['network_fee'])) {
                    $updates['status'] = 'manual_review';
                } else {
                    $fee = Amount::unsigned((string) $result['network_fee']);
                    $this->ledger->post('settlement:'.$id.':complete', 'fee.settlement.complete', [
                        'system:fee_payable' => (string) $s->total, 'system:custody' => Amount::neg(Amount::add((string) $s->amount, $fee)),
                        'system:network_expense' => $fee, 'system:revenue' => Amount::neg((string) $s->network_fee)], ['settlement_id' => $id]);
                    $updates['status'] = 'completed';
                    $updates['actual_network_fee'] = $fee;
                    if (Amount::cmp($fee, (string) $s->network_fee) > 0) {
                        DB::table('controls')->where('key', 'withdrawals_paused')->update(['value' => 'true', 'updated_at' => now()]);
                    }
                }
            } elseif (($result['status'] ?? '') === 'failed') {
                if (! isset($result['network_fee']) || Amount::cmp(Amount::unsigned((string) $result['network_fee']), '0') !== 0) {
                    $updates['status'] = 'manual_review';
                    DB::table('controls')->where('key', 'withdrawals_paused')->update(['value' => 'true', 'updated_at' => now()]);
                } else {
                    $this->ledger->post('settlement:'.$id.':refund', 'fee.settlement.failed', [
                        'system:fee_payable' => (string) $s->total, 'system:revenue' => Amount::neg((string) $s->total)], ['settlement_id' => $id]);
                    $updates['status'] = 'failed';
                }
            } elseif (in_array($result['status'] ?? '', ['submitted', 'pending', 'broadcast'], true)) {
                $updates['status'] = 'broadcast';
            }
            DB::table('fee_settlements')->where('id', $id)->update($updates);
            Ledger::audit('custody', 'settlement.'.$updates['status'], $id);

            return (array) DB::table('fee_settlements')->where('id', $id)->first();
        }, 3);
    }

    public function list(): array
    {
        return DB::table('fee_settlements')->orderByDesc('created_at')->limit(10)->get()->map(fn ($s) => (array) $s)->all();
    }

    private function matchingProposal(object $s, string $actor, string $amount): array
    {
        if ($s->proposed_by !== $actor || Amount::cmp((string) $s->amount, $amount) !== 0) {
            throw new DomainException('Proposal request key has conflicting parameters.');
        }

        return (array) $s;
    }

    private function authorize(string $actor, array $roles): void
    {
        $actual = config('telegram.admin_roles.'.$actor, []);
        if (! is_array($actual) || ! array_intersect(array_map('strtolower', $actual), array_map('strtolower', $roles))) {
            throw new DomainException('Settlement permission denied.');
        }
    }

    private function liabilities(): string
    {
        $sum = '0';
        foreach (DB::table('ledger_accounts')->whereNotNull('wallet_user_id')->get() as $a) {
            $owed = Amount::neg($this->ledger->balance($a->code));
            if (Amount::cmp($owed, '0') > 0) {
                $sum = Amount::add($sum, $owed);
            }
        }

        return $sum;
    }

    private function checkSafe(object $s, array $controls): void
    {
        if ($s->destination !== config('wallet.owner_destination')) {
            throw new DomainException('Approved destination has changed; create a new proposal.');
        }
        if (($controls['transfers_paused'] ?? 'true') === 'true' || ($controls['withdrawals_paused'] ?? 'true') === 'true') {
            throw new DomainException('Transfers are paused.');
        }
        $r = DB::table('reconciliations')->orderByDesc('id')->first();
        if (! $r || ! $r->balanced || now()->gt(Carbon::parse($r->created_at)->addMinutes(5))) {
            throw new DomainException('A recent clean reconciliation is required.');
        }
        $available = Amount::neg(Amount::add(Amount::add($this->ledger->balance('system:revenue'), $this->ledger->balance('system:network_expense')), $this->ledger->balance('system:network_recovery')));
        if (Amount::cmp($available, (string) $s->total) < 0) {
            throw new DomainException('Insufficient reconciled platform revenue including network cost.');
        }
        if (DB::table('transfers')->where('kind', 'withdrawal')->whereNotIn('status', ['completed', 'failed'])->exists()
            || DB::table('fee_settlements')->where('id', '!=', $s->id)->whereNotIn('status', ['completed', 'failed', 'proposed'])->exists()) {
            throw new DomainException('Resolve outstanding payments before a fee settlement.');
        }
        if (Amount::cmp(Amount::sub($this->ledger->balance('system:custody'), (string) $s->total), $this->liabilities()) < 0) {
            throw new DomainException('Customer backing must remain intact.');
        }
    }
}
