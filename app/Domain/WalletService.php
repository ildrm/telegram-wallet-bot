<?php

namespace App\Domain;

use App\Custody\CustodyProvider;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WalletService
{
    public function __construct(private Ledger $ledger, private FeePolicy $fees, private CustodyProvider $custody) {}

    public function register(string $telegramId, ?string $username = null): array
    {
        if (! preg_match('/^[1-9][0-9]{0,19}$/D', $telegramId)) {
            throw new DomainException('Invalid Telegram account.');
        }
        if ($username !== null && ! preg_match('/^[A-Za-z0-9_]{1,32}$/D', $username)) {
            $username = null;
        }

        return DB::transaction(function () use ($telegramId, $username) {
            DB::table('wallet_users')->insertOrIgnore(['telegram_id' => $telegramId, 'wallet_code' => 'W'.strtoupper(Str::random(15)), 'username' => $username, 'created_at' => now(), 'updated_at' => now()]);
            $user = DB::table('wallet_users')->where('telegram_id', $telegramId)->lockForUpdate()->first();
            DB::table('wallet_users')->where('id', $user->id)->update(['username' => $username, 'updated_at' => now()]);
            foreach (['available', 'reserved', 'held'] as $kind) {
                DB::table('ledger_accounts')->insertOrIgnore(['code' => $this->ledger->userCode($user->id, $kind), 'wallet_user_id' => $user->id, 'kind' => $kind, 'created_at' => now()]);
            }

            return (array) DB::table('wallet_users')->find($user->id);
        }, 3);
    }

    public function accept(int $userId): array
    {
        return DB::transaction(function () use ($userId) {
            $user = DB::table('wallet_users')->where('id', $userId)->lockForUpdate()->first();
            if (! $user) {
                throw new DomainException('Start the bot first.');
            }
            DB::table('wallet_users')->where('id', $userId)->update(['accepted_at' => $user->accepted_at ?? now(), 'terms_version' => config('wallet.terms_version', 'sandbox-v1'), 'updated_at' => now()]);
            Ledger::audit('user:'.$userId, 'terms.accepted', (string) $userId, ['version' => config('wallet.terms_version', 'sandbox-v1')]);

            return (array) DB::table('wallet_users')->find($userId);
        }, 3);
    }

    public function balances(int $userId): array
    {
        $result = [];
        foreach (['available', 'reserved', 'held'] as $kind) {
            $result[$kind] = Amount::neg($this->ledger->balance($this->ledger->userCode($userId, $kind)));
        }
        $result['pending'] = '0';
        foreach (DB::table('deposits')->where('user_id', $userId)->where('status', 'pending')->cursor() as $deposit) {
            $result['pending'] = Amount::add($result['pending'], (string) $deposit->amount);
        }

        return $result;
    }

    public function quote(int $userId, string $kind, string $amountBaseUnits, ?string $recipientWalletCode = null, ?string $destination = null): array
    {
        $amount = Amount::unsigned($amountBaseUnits, true);
        if (! in_array($kind, ['internal', 'withdrawal'], true)) {
            throw new DomainException('Choose an internal transfer or withdrawal.');
        }
        $max = (string) config('wallet.limits.transaction_max', '1000000000000000000');
        if ($kind === 'withdrawal') {
            $max = (string) config('wallet.limits.withdrawal_max', $max);
        }
        if (Amount::cmp($amount, Amount::unsigned($max, true)) > 0) {
            throw new DomainException('Amount exceeds the transaction limit.');
        }
        $networkFee = '0';
        $expiry = now()->addSeconds((int) config('wallet.quote_ttl_seconds', 120));
        if ($kind === 'withdrawal') {
            if (! $destination || ! $this->custody->validateAddress($destination)) {
                throw new DomainException('Use a valid external Ethereum Sepolia address.');
            }
            if (DB::table('deposit_addresses')->whereRaw('LOWER(address) = ?', [strtolower($destination)])->exists()) {
                throw new DomainException('Use an internal wallet transfer for a bot deposit address.');
            }
            $providerQuote = $this->custody->quoteWithdrawal($destination, $amount);
            $networkFee = Amount::unsigned((string) $providerQuote['network_fee']);
            if (isset($providerQuote['expires_at'])) {
                $expiry = $expiry->min(CarbonImmutable::parse($providerQuote['expires_at']));
            }
        }
        $serviceFee = $this->fees->calculate($kind, $amount);
        $total = Amount::add(Amount::add($amount, $serviceFee), $networkFee);

        return DB::transaction(function () use ($userId, $kind, $amount, $recipientWalletCode, $destination, $networkFee, $serviceFee, $total, $expiry) {
            $controls = Ledger::controls();
            $this->checkPaused($controls, $kind);
            $user = DB::table('wallet_users')->where('id', $userId)->lockForUpdate()->first();
            $this->spendable($user);
            $recipient = null;
            if ($kind === 'internal') {
                $recipient = DB::table('wallet_users')->where('wallet_code', strtoupper(trim($recipientWalletCode ?? '')))->first();
                $this->spendable($recipient);
                if ($recipient->id === $userId) {
                    throw new DomainException('Choose another wallet.');
                }
            }
            if (Amount::cmp($this->balances($userId)['available'], $total) < 0) {
                throw new DomainException('Insufficient available balance including fees.');
            }
            $this->checkDaily($userId, $total);
            $id = (string) Str::uuid();
            DB::table('quotes')->insert(['id' => $id, 'user_id' => $userId, 'kind' => $kind, 'recipient_user_id' => $recipient?->id, 'destination' => $kind === 'withdrawal' ? $destination : null, 'amount' => $amount, 'service_fee' => $serviceFee, 'network_fee' => $networkFee, 'total' => $total, 'policy_version' => (string) config('wallet.fees.version', 'sandbox-v1'), 'expires_at' => $expiry, 'created_at' => now()]);

            return (array) DB::table('quotes')->where('id', $id)->first();
        }, 3);
    }

    public function confirm(int $userId, string $quoteId): array
    {
        return DB::transaction(function () use ($userId, $quoteId) {
            $controls = Ledger::controls();
            $quote = DB::table('quotes')->where('id', $quoteId)->where('user_id', $userId)->lockForUpdate()->first();
            if (! $quote) {
                throw new DomainException('This quote is unavailable. Start again.');
            }
            $existing = DB::table('transfers')->where('quote_id', $quoteId)->first();
            if ($existing) {
                return (array) $existing;
            }
            $this->checkPaused($controls, $quote->kind);
            if ($quote->policy_version !== (string) config('wallet.fees.version', 'sandbox-v1')) {
                throw new DomainException('Fee policy changed. Create a new preview.');
            }
            if ($quote->consumed_at || now()->greaterThanOrEqualTo(CarbonImmutable::parse($quote->expires_at))) {
                throw new DomainException('This quote expired. Create a new preview.');
            }
            $ids = array_filter([$userId, $quote->recipient_user_id]);
            sort($ids, SORT_NUMERIC);
            $users = DB::table('wallet_users')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->spendable($users[$userId] ?? null);
            if ($quote->recipient_user_id) {
                $this->spendable($users[$quote->recipient_user_id] ?? null);
            }
            if (Amount::cmp($this->balances($userId)['available'], (string) $quote->total) < 0) {
                throw new DomainException('Insufficient available balance including fees.');
            }
            $this->checkDaily($userId, (string) $quote->total);
            $id = (string) Str::uuid();
            $status = $quote->kind === 'internal' ? 'completed' : 'reserved';
            DB::table('transfers')->insert(['id' => $id, 'quote_id' => $quoteId, 'user_id' => $userId, 'recipient_user_id' => $quote->recipient_user_id, 'kind' => $quote->kind, 'status' => $status, 'destination' => $quote->destination, 'amount' => $quote->amount, 'service_fee' => $quote->service_fee, 'network_fee' => $quote->network_fee, 'total' => $quote->total, 'created_at' => now(), 'updated_at' => now()]);
            $entries = [$this->ledger->userCode($userId) => (string) $quote->total];
            if ($quote->kind === 'internal') {
                $entries[$this->ledger->userCode($quote->recipient_user_id)] = Amount::neg((string) $quote->amount);
                $entries['system:revenue'] = Amount::neg((string) $quote->service_fee);
            } else {
                $entries[$this->ledger->userCode($userId, 'reserved')] = Amount::neg((string) $quote->total);
            }
            $this->ledger->post('transfer:'.$id.':'.($status === 'completed' ? 'complete' : 'reserve'), $quote->kind, $entries, ['transfer_id' => $id, 'fee_policy' => $quote->policy_version]);
            DB::table('quotes')->where('id', $quoteId)->update(['consumed_at' => now()]);
            Ledger::audit('user:'.$userId, 'transfer.confirmed', $id, ['quote_id' => $quoteId]);
            if ($quote->kind === 'withdrawal') {
                Ledger::outbox('withdrawal.process', 'withdrawal:'.$id, ['transaction_id' => $id]);
            } else {
                Ledger::outbox('telegram.notify', 'received:'.$id, ['user_id' => $quote->recipient_user_id, 'text' => 'Received '.Amount::format((string) $quote->amount).' ETH (sandbox) from wallet '.$users[$userId]->wallet_code.'.']);
            }

            return (array) DB::table('transfers')->where('id', $id)->first();
        }, 3);
    }

    private function spendable(?object $user): void
    {
        if (! $user || ! $user->accepted_at) {
            throw new DomainException('Accept the wallet disclosures before transferring.');
        }
        if ($user->frozen) {
            throw new DomainException('This wallet is frozen. Contact support.');
        }
    }

    private function checkPaused(array $controls, string $kind): void
    {
        $last = DB::table('reconciliations')->orderByDesc('id')->first();
        if ($last && ! $last->balanced) {
            throw new DomainException('Transfers require a clean reconciliation after the last discrepancy.');
        }
        if (($controls['transfers_paused'] ?? 'true') === 'true' || ($kind === 'withdrawal' && ($controls['withdrawals_paused'] ?? 'true') === 'true')) {
            throw new DomainException('Transfers are temporarily paused. Try later.');
        }
    }

    private function checkDaily(int $userId, string $total): void
    {
        $spent = '0';
        foreach (DB::table('transfers')->where('user_id', $userId)->where('created_at', '>=', now()->startOfDay())->whereNotIn('status', ['failed'])->cursor() as $t) {
            $spent = Amount::add($spent, (string) $t->total);
        }
        if (Amount::cmp(Amount::add($spent, $total), Amount::unsigned((string) config('wallet.limits.daily_max', '5000000000000000000'), true)) > 0) {
            throw new DomainException('Daily authorized deduction limit reached.');
        }
    }

    public function history(int $userId, int $page = 1): array
    {
        $page = max(1, $page);
        $rows = DB::table('transfers')->where(fn ($q) => $q->where('user_id', $userId)->orWhere('recipient_user_id', $userId))->orderByDesc('created_at')->orderBy('id')->offset(($page - 1) * 5)->limit(6)->get()->map(fn ($t) => (array) $t)->all();

        return ['transfers' => array_slice($rows, 0, 5), 'page' => $page, 'has_more' => count($rows) > 5];
    }

    public function details(int $userId, string $id): array
    {
        $row = DB::table('transfers')->where('id', $id)->where(fn ($q) => $q->where('user_id', $userId)->orWhere('recipient_user_id', $userId))->first();
        if (! $row) {
            throw new DomainException('Transaction unavailable.');
        }

        return (array) $row;
    }

    private function admin(int $actorTelegramId, array $allowed): void
    {
        $roles = config('telegram.admin_roles.'.$actorTelegramId, []);
        if (is_string($roles)) {
            $roles = [$roles];
        }
        if (! array_intersect(array_map('strtolower', $roles), array_map('strtolower', $allowed))) {
            throw new DomainException('Administrator permission denied.');
        }
    }

    public function setFrozen(int $targetUserId, bool $frozen, int $actorTelegramId): void
    {
        $this->admin($actorTelegramId, ['Owner', 'Compliance']);
        DB::transaction(function () use ($targetUserId, $frozen, $actorTelegramId) {
            Ledger::controls();
            $user = DB::table('wallet_users')->where('id', $targetUserId)->lockForUpdate()->first();
            if (! $user) {
                throw new DomainException('Wallet unavailable.');
            }
            if (! $frozen && Amount::cmp($this->balances($targetUserId)['available'], '0') < 0) {
                throw new DomainException('Resolve the negative balance before unfreezing.');
            }
            DB::table('wallet_users')->where('id', $targetUserId)->update(['frozen' => $frozen, 'updated_at' => now()]);
            Ledger::audit((string) $actorTelegramId, 'wallet.freeze', (string) $targetUserId, ['frozen' => $frozen]);
        }, 3);
    }

    public function setPaused(string $scope, bool $paused, int $actorTelegramId): void
    {
        $this->admin($actorTelegramId, ['Owner', 'Operations']);
        if (! in_array($scope, ['transfers', 'withdrawals'], true)) {
            throw new DomainException('Invalid pause scope.');
        }
        DB::transaction(function () use ($scope, $paused, $actorTelegramId) {
            Ledger::controls();
            DB::table('controls')->where('key', $scope.'_paused')->update(['value' => $paused ? 'true' : 'false', 'updated_at' => now()]);
            Ledger::audit((string) $actorTelegramId, 'system.pause', $scope, ['paused' => $paused]);
        }, 3);
    }

    public function hold(int $userId, string $amount, bool $release, int $actorTelegramId): void
    {
        $this->admin($actorTelegramId, ['Owner', 'Compliance']);
        Amount::unsigned($amount, true);
        DB::transaction(function () use ($userId, $amount, $release, $actorTelegramId) {
            Ledger::controls();
            $user = DB::table('wallet_users')->where('id', $userId)->lockForUpdate()->first();
            if (! $user) {
                throw new DomainException('Wallet unavailable.');
            }
            $source = $release ? 'held' : 'available';
            $target = $release ? 'available' : 'held';
            if (Amount::cmp($this->balances($userId)[$source], $amount) < 0) {
                throw new DomainException('Insufficient funds in this balance bucket.');
            }
            $ref = 'hold:'.Str::uuid();
            $this->ledger->post($ref, $release ? 'hold.release' : 'hold.place', [$this->ledger->userCode($userId, $source) => $amount, $this->ledger->userCode($userId, $target) => Amount::neg($amount)]);
            Ledger::audit((string) $actorTelegramId, $release ? 'hold.release' : 'hold.place', (string) $userId, ['amount' => $amount, 'reference' => $ref]);
        }, 3);
    }
}
