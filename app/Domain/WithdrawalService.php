<?php

namespace App\Domain;

use App\Custody\CustodyProvider;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class WithdrawalService
{
    public function __construct(private Ledger $ledger, private CustodyProvider $custody) {}

    /** A crash after state claim can only lead to lookup of the original idempotency key. */
    public function process(string $transactionId): array
    {
        [$transfer,$submit] = DB::transaction(function () use ($transactionId) {
            $controls = Ledger::controls();
            $t = DB::table('transfers')->where('id', $transactionId)->lockForUpdate()->first();
            if (! $t || $t->kind !== 'withdrawal') {
                throw new DomainException('Withdrawal unavailable.');
            }
            if (in_array($t->status, ['completed', 'failed', 'manual_review'], true)) {
                return [$t, false];
            }
            $user = DB::table('wallet_users')->where('id', $t->user_id)->lockForUpdate()->first();
            $submit = $t->status === 'reserved';
            $lastReconciliation = DB::table('reconciliations')->orderByDesc('id')->first();
            if ($submit && $lastReconciliation && ! $lastReconciliation->balanced) {
                return [$t, false];
            }
            if ($submit && (($controls['transfers_paused'] ?? 'true') === 'true' || ($controls['withdrawals_paused'] ?? 'true') === 'true' || $user->frozen)) {
                return [$t, false];
            }
            if ($submit) {
                DB::table('transfers')->where('id', $transactionId)->update(['status' => 'submitting', 'updated_at' => now()]);
                $t->status = 'submitting';
            }

            return [$t, $submit];
        }, 3);
        if (in_array($transfer->status, ['completed', 'failed', 'manual_review', 'reserved'], true)) {
            return (array) $transfer;
        }
        try {
            $result = $submit
                ? $this->custody->submitWithdrawal($transactionId, $transfer->destination, (string) $transfer->amount, (string) $transfer->network_fee)
                : $this->custody->withdrawalStatus($transactionId);
        } catch (Throwable $error) {
            // No refund and no retry send: the provider may already have broadcast it.
            $result = ['status' => 'unknown'];
        }

        return $this->apply($transactionId, $result);
    }

    public function reconcile(string $transactionId): array
    {
        return $this->process($transactionId);
    }

    private function apply(string $id, ?array $result): array
    {
        return DB::transaction(function () use ($id, $result) {
            Ledger::controls();
            $t = DB::table('transfers')->where('id', $id)->lockForUpdate()->first();
            DB::table('wallet_users')->where('id', $t->user_id)->lockForUpdate()->first();
            if (in_array($t->status, ['completed', 'failed', 'manual_review'], true)) {
                return (array) $t;
            }
            $status = $result['status'] ?? 'unknown';
            $updates = ['status' => 'unknown', 'updated_at' => now(), 'last_error' => null];
            if (isset($result['id'])) {
                $updates['provider_reference'] = (string) $result['id'];
            }
            if (isset($result['txid'])) {
                $updates['txid'] = (string) $result['txid'];
            }
            if ($status === 'confirmed') {
                if (! isset($result['network_fee'])) {
                    $updates['status'] = 'manual_review';
                    $updates['last_error'] = 'Provider finality omitted actual network fee.';
                } else {
                    $actual = Amount::unsigned((string) $result['network_fee']);
                    $maximum = (string) $t->network_fee;
                    $charged = Amount::cmp($actual, $maximum) > 0 ? $maximum : $actual;
                    $refund = Amount::sub($maximum, $charged);
                    $this->ledger->post('transfer:'.$id.':complete', 'withdrawal.complete', [
                        $this->ledger->userCode($t->user_id, 'reserved') => (string) $t->total,
                        $this->ledger->userCode($t->user_id) => Amount::neg($refund),
                        'system:custody' => Amount::neg(Amount::add((string) $t->amount, $actual)),
                        'system:revenue' => Amount::neg((string) $t->service_fee),
                        'system:network_expense' => $actual,
                        'system:network_recovery' => Amount::neg($charged),
                    ], ['transfer_id' => $id, 'actual_network_fee' => $actual]);
                    $updates['status'] = 'completed';
                    $updates['actual_network_fee'] = $actual;
                    if (Amount::cmp($actual, $maximum) > 0) {
                        DB::table('controls')->where('key', 'withdrawals_paused')->update(['value' => 'true', 'updated_at' => now()]);
                        Ledger::audit('custody', 'withdrawal.network_overrun', $id, ['authorized_max' => $maximum, 'actual' => $actual]);
                    }
                }
            } elseif ($status === 'failed' && (! isset($result['network_fee']) || Amount::cmp(Amount::unsigned((string) $result['network_fee']), '0') !== 0)) {
                $updates['status'] = 'manual_review';
                $updates['last_error'] = 'Failed payment charged or omitted network cost; reserve retained for explicit reviewed accounting.';
                DB::table('controls')->where('key', 'withdrawals_paused')->update(['value' => 'true', 'updated_at' => now()]);
            } elseif ($status === 'failed') {
                $this->ledger->post('transfer:'.$id.':refund', 'withdrawal.failed', [$this->ledger->userCode($t->user_id, 'reserved') => (string) $t->total, $this->ledger->userCode($t->user_id) => Amount::neg((string) $t->total)], ['transfer_id' => $id]);
                $updates['status'] = 'failed';
            } elseif (in_array($status, ['submitted', 'broadcast', 'pending'], true)) {
                $updates['status'] = 'broadcast';
            } else {
                $updates['last_error'] = $result === null ? 'Original provider request absent; requires reconciliation; never automatically resubmitted.' : 'Provider outcome uncertain; lookup original request only.';
            }
            DB::table('transfers')->where('id', $id)->update($updates);
            if ($updates['status'] === 'manual_review') {
                DB::table('controls')->where('key', 'withdrawals_paused')->update(['value' => 'true', 'updated_at' => now()]);
            }
            if (in_array($updates['status'], ['completed', 'failed', 'manual_review'], true)) {
                Ledger::audit('custody', 'withdrawal.'.$updates['status'], $id);
                Ledger::outbox('telegram.notify', 'withdrawal:'.$id.':'.$updates['status'], ['user_id' => $t->user_id, 'text' => 'Sandbox withdrawal '.$id.': '.$updates['status'].'.']);
            }

            return (array) DB::table('transfers')->where('id', $id)->first();
        }, 3);
    }
}
