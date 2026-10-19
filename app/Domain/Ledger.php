<?php

namespace App\Domain;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Ledger
{
    public function userCode(int $userId, string $bucket = 'available'): string
    {
        return "user:$userId:$bucket";
    }

    public function balance(string $code): string
    {
        $id = DB::table('ledger_accounts')->where('code', $code)->value('id');
        if (! $id) {
            throw new DomainException('Unknown ledger account.');
        }
        $sum = '0';
        // SQLite SUM coerces large integers to REAL; exact addition also makes test semantics identical.
        foreach (DB::table('postings')->where('account_id', $id)->orderBy('id')->cursor() as $posting) {
            $sum = Amount::add($sum, (string) $posting->amount);
        }

        return $sum;
    }

    /** Caller must own the user/control locks. Every posting is immutable and balances per asset. */
    public function post(string $reference, string $kind, array $entries, array $metadata = []): string
    {
        if (DB::transactionLevel() < 1) {
            throw new DomainException('Ledger writes require a database transaction.');
        }
        $existing = DB::table('journals')->where('reference', $reference)->first();
        if ($existing) {
            $posted = [];
            foreach (DB::table('postings')->join('ledger_accounts', 'ledger_accounts.id', '=', 'postings.account_id')->where('journal_id', $existing->id)->select('ledger_accounts.code', 'postings.amount')->get() as $p) {
                $posted[$p->code] = (string) $p->amount;
            }
            ksort($posted, SORT_STRING);
            ksort($entries, SORT_STRING);
            if ($existing->kind !== $kind || array_keys($posted) !== array_keys($entries)) {
                throw new DomainException('Journal reference reused with different intent.');
            }
            foreach ($entries as $code => $amount) {
                if (Amount::cmp($posted[$code], $amount) !== 0) {
                    throw new DomainException('Journal reference reused with different amounts.');
                }
            }

            return $existing->id;
        }
        ksort($entries, SORT_STRING);
        $sum = '0';
        foreach ($entries as $amount) {
            if (! is_string($amount) || ! preg_match('/^-?(0|[1-9][0-9]{0,63})$/D', $amount)) {
                throw new DomainException('Invalid signed ledger amount.');
            }
            $sum = Amount::add($sum, $amount);
        }
        if (count($entries) < 2 || Amount::cmp($sum, '0') !== 0) {
            throw new DomainException('Journal must balance to zero.');
        }
        $accounts = DB::table('ledger_accounts')->whereIn('code', array_keys($entries))->orderBy('code')->lockForUpdate()->get()->keyBy('code');
        if ($accounts->count() !== count($entries)) {
            throw new DomainException('Unknown journal account.');
        }
        foreach ($accounts as $account) {
            if ($account->asset !== config('wallet.asset', 'ETH')) {
                throw new DomainException('Journal account asset is unsupported.');
            }
        }
        $id = (string) Str::uuid();
        DB::table('journals')->insert(['id' => $id, 'reference' => $reference, 'kind' => $kind, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        foreach ($entries as $code => $amount) {
            DB::table('postings')->insert(['journal_id' => $id, 'account_id' => $accounts[$code]->id, 'amount' => $amount, 'created_at' => now()]);
        }

        return $id;
    }

    public static function audit(string $actor, string $action, string $subject, array $metadata = []): void
    {
        DB::table('audit_logs')->insert(['actor' => $actor, 'action' => $action, 'subject' => $subject, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public static function outbox(string $type, string $key, array $payload): void
    {
        DB::table('outbox')->insertOrIgnore(['type' => $type, 'dedupe_key' => $key, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'available_at' => now(), 'created_at' => now()]);
    }

    public static function controls(): array
    {
        return DB::table('controls')->orderBy('key')->lockForUpdate()->pluck('value', 'key')->all();
    }
}
