<?php

namespace App\Domain;

use App\Custody\CustodyProvider;
use Illuminate\Support\Facades\DB;

final class ReconciliationService
{
    public function __construct(private Ledger $ledger, private CustodyProvider $custody) {}

    public function run(): array
    {
        $actual = Amount::unsigned($this->custody->balance());

        return DB::transaction(function () use ($actual) {
            Ledger::controls();
            $custody = $this->ledger->balance('system:custody');
            $liabilities = '0';
            foreach (DB::table('ledger_accounts')->whereNotNull('wallet_user_id')->orderBy('code')->get() as $account) {
                $balance = Amount::neg($this->ledger->balance($account->code));
                // A customer receivable never offsets another customer's liability.
                if (Amount::cmp($balance, '0') > 0) {
                    $liabilities = Amount::add($liabilities, $balance);
                }
            }
            $fees = Amount::neg($this->ledger->balance('system:revenue'));
            $integrity = true;
            foreach (DB::table('journals')->orderBy('id')->cursor() as $journal) {
                $sum = '0';
                $count = 0;
                foreach (DB::table('postings')->where('journal_id', $journal->id)->cursor() as $p) {
                    $sum = Amount::add($sum, (string) $p->amount);
                    $count++;
                }
                if ($sum !== '0' || $count < 2) {
                    $integrity = false;
                }
            }
            $balanced = $integrity && Amount::cmp($actual, $custody) === 0 && Amount::cmp($actual, $liabilities) >= 0;
            $row = ['custody_balance' => $actual, 'ledger_custody' => $custody, 'liabilities' => $liabilities, 'earned_fees' => $fees, 'balanced' => $balanced, 'created_at' => now()];
            $id = DB::table('reconciliations')->insertGetId($row);
            if (! $balanced) {
                DB::table('controls')->where('key', 'transfers_paused')->update(['value' => 'true', 'updated_at' => now()]);
                Ledger::audit('system', 'reconciliation.discrepancy', (string) $id, $row);
            }
            $row['id'] = $id;

            return $row;
        }, 3);
    }
}
