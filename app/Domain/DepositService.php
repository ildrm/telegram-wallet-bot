<?php

namespace App\Domain;

use DomainException;
use Illuminate\Support\Facades\DB;

final class DepositService
{
    public function __construct(private Ledger $ledger) {}

    /** Authenticated provider inbox ONLY. Never call this from an unauthenticated endpoint. */
    public function ingest(array $event): array
    {
        foreach (['asset', 'network', 'txid', 'address', 'amount', 'confirmations'] as $key) {
            if (! isset($event[$key])) {
                throw new DomainException('Incomplete deposit event.');
            }
        }
        if ($event['asset'] !== config('wallet.asset', 'ETH') || $event['network'] !== config('wallet.network', 'ethereum-sepolia')) {
            throw new DomainException('Unsupported deposit asset or network.');
        }
        if (! is_string($event['amount'])) {
            throw new DomainException('Deposit base units must be an exact integer string.');
        }
        $amount = Amount::unsigned($event['amount'], true);
        if (! preg_match('/^0x[a-fA-F0-9]{64}$/D', $event['txid']) || ! preg_match('/^0x[a-fA-F0-9]{40}$/D', $event['address'])) {
            throw new DomainException('Invalid chain transaction or address.');
        }
        $index = $event['index'] ?? 0;
        if (! is_int($index) || $index < 0 || $index > 1000000 || ! is_int($event['confirmations']) || $event['confirmations'] < 0 || $event['confirmations'] > 1000000000) {
            throw new DomainException('Invalid chain event index or confirmations.');
        }
        if (isset($event['removed']) && ! is_bool($event['removed'])) {
            throw new DomainException('Invalid removal indicator.');
        }

        return DB::transaction(function () use ($event, $amount, $index) {
            Ledger::controls();
            $txid = strtolower($event['txid']);
            $address = strtolower($event['address']);
            $deposit = DB::table('deposits')->where(['network' => $event['network'], 'txid' => $txid, 'event_index' => $index])->lockForUpdate()->first();
            if ($deposit && ($deposit->asset !== $event['asset'] || Amount::cmp((string) $deposit->amount, $amount) !== 0 || $deposit->address !== $address)) {
                throw new DomainException('Conflicting immutable chain event.');
            }
            $userId = $deposit?->user_id ?? DB::table('deposit_addresses')->whereRaw('LOWER(address) = ?', [$address])->value('wallet_user_id');
            if ($userId) {
                DB::table('wallet_users')->where('id', $userId)->lockForUpdate()->first();
            }
            if (! $deposit) {
                $id = DB::table('deposits')->insertGetId(['user_id' => $userId, 'asset' => $event['asset'], 'network' => $event['network'], 'txid' => $txid, 'event_index' => $index, 'address' => $address, 'amount' => $amount, 'confirmations' => $event['confirmations'], 'status' => $userId ? 'pending' : 'unrecognized', 'block_hash' => $event['block_hash'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
                $deposit = DB::table('deposits')->find($id);
            }
            $hashChanged = $deposit->block_hash && isset($event['block_hash']) && $deposit->block_hash !== $event['block_hash'];
            $removed = ($event['removed'] ?? false) || $hashChanged;
            $status = $deposit->status;
            if ($removed && $status === 'credited') {
                $this->ledger->post('deposit:'.$deposit->id.':reverse', 'deposit.reorg', ['system:custody' => Amount::neg($amount), $this->ledger->userCode($userId) => $amount], ['deposit_id' => $deposit->id]);
                DB::table('wallet_users')->where('id', $userId)->update(['frozen' => true, 'updated_at' => now()]);
                DB::table('controls')->where('key', 'transfers_paused')->update(['value' => 'true', 'updated_at' => now()]);
                $status = 'reversed';
                Ledger::audit('custody', 'deposit.reorg', (string) $deposit->id, ['txid' => $txid]);
                Ledger::outbox('telegram.notify', 'deposit:'.$deposit->id.':reverse', ['user_id' => $userId, 'text' => 'A confirmed sandbox deposit was reorganized. Transfers are paused and your wallet is frozen for review. Contact support.']);
            } elseif ($removed && ! in_array($status, ['credited', 'reversed'], true)) {
                $status = 'removed';
            } elseif ($userId && ! in_array($status, ['credited', 'reversed', 'removed'], true)) {
                $status = 'pending';
                if ($event['confirmations'] >= (int) config('wallet.confirmations', 12)) {
                    $this->ledger->post('deposit:'.$deposit->id.':credit', 'deposit', ['system:custody' => $amount, $this->ledger->userCode($userId) => Amount::neg($amount)], ['deposit_id' => $deposit->id]);
                    $status = 'credited';
                    Ledger::outbox('telegram.notify', 'deposit:'.$deposit->id.':credited', ['user_id' => $userId, 'text' => 'Sandbox deposit confirmed: '.Amount::format($amount).' ETH.']);
                }
            }
            DB::table('deposits')->where('id', $deposit->id)->update(['user_id' => $userId, 'confirmations' => $event['confirmations'], 'status' => $status, 'block_hash' => $event['block_hash'] ?? $deposit->block_hash, 'updated_at' => now()]);

            return (array) DB::table('deposits')->find($deposit->id);
        }, 3);
    }
}
