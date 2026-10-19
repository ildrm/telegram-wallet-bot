<?php

namespace App\Custody;

use Illuminate\Support\Facades\DB;

/** Database-persistent simulator. NO blockchain RPC, keys, or actual Sepolia transfers. */
class FakeCustodyProvider implements CustodyProvider
{
    public function allocateAddress(string $walletCode): string
    {
        $this->available();

        return '0x'.substr(hash('sha256', 'fake-only:'.$walletCode), 0, 40);
    }

    public function validateAddress(string $destination): bool
    {
        // Conservative lowercase-only subset avoids silently bypassing mixed-case checksum validation.
        return (bool) preg_match('/\A0x[0-9a-f]{40}\z/D', $destination)
            && $destination !== '0x'.str_repeat('0', 40);
    }

    public function quoteWithdrawal(string $destination, string $amount): array
    {
        $this->available();
        if (! $this->validateAddress($destination) || ! preg_match('/\A[1-9][0-9]{0,35}\z/D', $amount)) {
            throw new \InvalidArgumentException('Invalid sandbox destination or amount.');
        }

        return ['network_fee' => (string) config('wallet.fake.network_fee'), 'expires_at' => now()->addSeconds(120)->toIso8601String()];
    }

    public function submitWithdrawal(string $idempotencyKey, string $destination, string $amount, string $maxNetworkFee): array
    {
        $this->available();
        $quote = $this->quoteWithdrawal($destination, $amount);
        if (bccomp($quote['network_fee'], $maxNetworkFee, 0) > 0) {
            throw new ProviderUnavailable('Fee exceeds authorization; original payment was not submitted.');
        }
        $payment = DB::transaction(function () use ($idempotencyKey, $destination, $amount, $quote) {
            DB::table('fake_custody_balances')->insertOrIgnore(['id' => 1, 'amount' => '0']);
            DB::table('fake_custody_balances')->where('id', 1)->lockForUpdate()->first();
            $existing = DB::table('fake_custody_payments')->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->destination !== $destination || $existing->amount !== $amount) {
                    throw new \LogicException('Idempotency key reused with changed payment parameters.');
                }

                return (array) $existing;
            }
            $payment = [
                'idempotency_key' => $idempotencyKey, 'destination' => $destination, 'amount' => $amount,
                'network_fee' => $quote['network_fee'], 'status' => 'submitted',
                'txid' => '0x'.hash('sha256', $idempotencyKey), 'balance_applied' => false, 'created_at' => now(),
            ];
            DB::table('fake_custody_payments')->insert($payment);

            return $payment;
        }, 3);
        if (config('wallet.fake.mode') === 'timeout_after_submit') {
            throw new UnknownSubmission('Simulator accepted the original request, then connection timed out.');
        }
        if (config('wallet.fake.mode') === 'confirmed') {
            $this->setStatus($idempotencyKey, 'confirmed');
        } elseif (config('wallet.fake.mode') === 'failed') {
            $this->setStatus($idempotencyKey, 'failed');
        }

        return $this->withdrawalStatus($idempotencyKey) ?? $this->response($payment);
    }

    public function withdrawalStatus(string $idempotencyKey): ?array
    {
        $this->available();
        $payment = DB::table('fake_custody_payments')->where('idempotency_key', $idempotencyKey)->first();

        return $payment ? $this->response((array) $payment) : null;
    }

    public function balance(): string
    {
        $this->available();

        return (string) (DB::table('fake_custody_balances')->where('id', 1)->value('amount') ?? '0');
    }

    /** Simulator test control; never reachable as a public API. Confirmed is terminal. */
    public function setStatus(string $key, string $status, ?string $actualFee = null): void
    {
        if (! in_array($status, ['submitted', 'confirmed', 'failed'], true)) {
            throw new \InvalidArgumentException('Invalid simulator state.');
        }
        DB::transaction(function () use ($key, $status, $actualFee) {
            DB::table('fake_custody_balances')->insertOrIgnore(['id' => 1, 'amount' => '0']);
            $balance = DB::table('fake_custody_balances')->where('id', 1)->lockForUpdate()->first();
            $payment = DB::table('fake_custody_payments')->where('idempotency_key', $key)->lockForUpdate()->first();
            if (! $payment) {
                throw new \InvalidArgumentException('Unknown simulator payment.');
            }
            if (in_array($payment->status, ['confirmed', 'failed'], true)) {
                if ($payment->status !== $status) {
                    throw new \LogicException('Final simulator state cannot change.');
                }

                return;
            }
            $fee = $actualFee ?? $payment->network_fee;
            if (! preg_match('/\A(?:0|[1-9][0-9]{0,35})\z/D', $fee)) {
                throw new \InvalidArgumentException('Invalid fee.');
            }
            $applied = (bool) $payment->balance_applied;
            if ($status === 'confirmed' && ! $applied) {
                $debit = bcadd($payment->amount, $fee, 0);
                if (bccomp($balance->amount, $debit, 0) < 0) {
                    throw new ProviderUnavailable('Simulator custody has insufficient assets.');
                }
                DB::table('fake_custody_balances')->where('id', 1)->update(['amount' => bcsub($balance->amount, $debit, 0)]);
                $applied = true;
            }
            DB::table('fake_custody_payments')->where('idempotency_key', $key)->update(['status' => $status, 'network_fee' => $fee, 'balance_applied' => $applied]);
        }, 3);
    }

    /** Event-driven independent custody balance; this is simulation, never provider verification. */
    public function observeDeposit(array $event): void
    {
        if ($event['asset'] !== config('wallet.asset') || $event['network'] !== config('wallet.network')) {
            return;
        }
        if (! is_string($event['amount']) || ! preg_match('/\A[1-9][0-9]{0,35}\z/D', $event['amount'])) {
            throw new \InvalidArgumentException('Invalid deposit units.');
        }
        $key = $event['network'].':'.strtolower($event['txid']).':'.($event['index'] ?? 0);
        DB::transaction(function () use ($event, $key) {
            DB::table('fake_custody_balances')->insertOrIgnore(['id' => 1, 'amount' => '0']);
            $balance = DB::table('fake_custody_balances')->where('id', 1)->lockForUpdate()->first();
            DB::table('fake_custody_deposits')->insertOrIgnore(['event_key' => $key, 'amount' => $event['amount']]);
            $deposit = DB::table('fake_custody_deposits')->where('event_key', $key)->lockForUpdate()->first();
            if ($deposit->amount !== $event['amount']) {
                throw new \LogicException('Conflicting original deposit amount.');
            }
            $hashChanged = $deposit->block_hash && isset($event['block_hash']) && $deposit->block_hash !== $event['block_hash'];
            $removed = (bool) ($event['removed'] ?? false) || $deposit->removed || $hashChanged;
            $shouldApply = ! $removed && ($deposit->balance_applied || ($event['confirmations'] ?? 0) >= config('wallet.confirmations'));
            $amount = $balance->amount;
            if ($shouldApply && ! $deposit->balance_applied) {
                $amount = bcadd($amount, $deposit->amount, 0);
            }
            if ($removed && $deposit->balance_applied) {
                $amount = bcsub($amount, $deposit->amount, 0);
            }
            DB::table('fake_custody_balances')->where('id', 1)->update(['amount' => $amount]);
            DB::table('fake_custody_deposits')->where('event_key', $key)->update(['balance_applied' => $shouldApply, 'removed' => $removed, 'block_hash' => $event['block_hash'] ?? $deposit->block_hash]);
        }, 3);
    }

    private function available(): void
    {
        if (config('wallet.fake.mode') === 'down') {
            throw new ProviderUnavailable('Simulated custody outage.');
        }
    }

    private function response(array $payment): array
    {
        return ['id' => $payment['idempotency_key'], 'status' => $payment['status'], 'txid' => $payment['txid'], 'network_fee' => $payment['status'] === 'failed' ? '0' : $payment['network_fee']];
    }
}
