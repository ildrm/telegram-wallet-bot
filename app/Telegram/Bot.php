<?php

namespace App\Telegram;

use App\Custody\CustodyProvider;
use App\Domain\WalletService;
use App\Finance\FeeSettlementService;
use App\Jobs\AllocateDepositAddress;
use App\Jobs\PrepareTelegramQuote;
use App\Jobs\ProposeTelegramSettlement;
use App\Jobs\RunTelegramReconciliation;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;

final class Bot
{
    private string $actor;

    private int $inboxId;

    private int $response = 0;

    private array $user;

    public function __construct(private readonly WalletService $wallet, private readonly CustodyProvider $custody, private readonly AdminAuthorizer $auth) {}

    public function prepare(array $update): void
    {
        $message = $update['callback_query']['message'] ?? $update['message'];
        $sender = $update['callback_query']['from'] ?? $message['from'];
        $id = (string) $sender['id'];
        $this->wallet->register($id, is_string($sender['username'] ?? null) ? $sender['username'] : null);
        DB::table('telegram_actors')->insertOrIgnore(['telegram_id' => $id]);
    }

    public function handle(array $update, int $inboxId): void
    {
        $message = $update['callback_query']['message'] ?? $update['message'];
        $sender = $update['callback_query']['from'] ?? $message['from'];
        $this->actor = (string) $sender['id'];
        $this->inboxId = $inboxId;
        $this->response = 0;
        $this->user = (array) DB::table('wallet_users')->where('telegram_id', $this->actor)->firstOrFail();
        try {
            if (isset($update['callback_query'])) {
                DB::table('outbox')->insertOrIgnore([
                    'type' => 'telegram.notify', 'dedupe_key' => 'telegram:callback:'.$inboxId,
                    'payload' => json_encode(['method' => 'answerCallbackQuery', 'callback_query_id' => $update['callback_query']['id']], JSON_THROW_ON_ERROR),
                    'available_at' => now(), 'created_at' => now(),
                ]);
                $this->callback((string) ($update['callback_query']['data'] ?? ''));

                return;
            }
            $text = trim((string) ($message['text'] ?? ''));
            if (str_starts_with($text, '/')) {
                $command = strtolower(explode(' ', $text)[0]);
                $this->clearState();
                $this->action(match ($command) {
                    '/start' => 'start', '/menu' => 'menu', '/receive' => 'receive', '/send' => 'send',
                    '/withdraw' => 'withdraw', '/history' => 'history', '/fees', '/limits' => 'fees',
                    '/terms' => 'terms', '/privacy' => 'privacy', '/support' => 'support', '/admin' => 'admin',
                    '/cancel' => 'cancel', default => 'unknown',
                    '/settle' => 'admin_settlement_amount', '/settlements' => 'admin_settlement_list',
                });

                return;
            }
            $state = DB::table('telegram_conversations')->where('telegram_id', $this->actor)->lockForUpdate()->first();
            if (! $state || Carbon::parse($state->expires_at)->isPast()) {
                $this->clearState();
                $this->reply('This conversation has expired or no input is expected. Open /menu to continue.');

                return;
            }
            $this->input($state->state, json_decode($state->context, true), $text);
        } catch (DomainException|InvalidArgumentException $e) {
            $this->reply($e->getMessage(), [[$this->button('Back to menu', 'cancel')]]);
        }
    }

    private function callback(string $token): void
    {
        $callback = preg_match('/^[a-f0-9]{40}$/D', $token)
            ? DB::table('telegram_callbacks')->where('token', hash('sha256', $token))->lockForUpdate()->first() : null;
        if (! $callback || $callback->telegram_id !== $this->actor || $callback->consumed_at || Carbon::parse($callback->expires_at)->isPast()) {
            $this->reply('This button expired, was already used, or belongs to another account. Open /menu for fresh buttons.');

            return;
        }
        DB::table('telegram_callbacks')->where('token', $callback->token)->update(['consumed_at' => now()]);
        $this->action($callback->action, json_decode($callback->context, true));
    }

    private function action(string $action, array $context = []): void
    {
        if (in_array($action, ['start', 'terms', 'privacy', 'accept', 'cancel', 'unknown'], true)) {
            match ($action) {
                'start' => $this->onboarding(),
                'terms' => $this->reply('Sandbox terms v1: fake custody only; no real assets, no buying or investment services. Telegram account access controls your wallet. Blockchain transfers may be irreversible. Eligibility: 18+ and permitted jurisdiction. Real-money service remains unavailable pending independent security, custody and legal approval.', [[$this->button('Back', 'start')]]),
                'privacy' => $this->reply('Sandbox privacy v1: we retain your Telegram numeric ID, current username, wallet, transaction and support records for operation and audit. Telegram and the hosting operator process messages. Do not send identity documents, private keys or recovery phrases here. Retention, data subject procedures and jurisdictional disclosures require legal review before launch.', [[$this->button('Back', 'start')]]),
                'accept' => $this->accept(),
                'cancel' => $this->cancel(),
                default => $this->reply('Unknown command. Open /menu for available actions.'),
            };

            return;
        }
        if (! $this->user['accepted_at']) {
            $this->onboarding();

            return;
        }
        switch ($action) {
            case 'menu': $this->clearState();
                $this->menu();
                break;
            case 'receive':
                Queue::connection('database')->push(new AllocateDepositAddress($this->user['id'], $this->actor, $this->inboxId));
                $this->reply('Preparing your sandbox deposit address. No real assets are supported.');
                break;
            case 'send':
                $this->state('recipient');
                $this->reply('Send internally · Enter the recipient wallet code (W…). Use their stable code; usernames are not accepted.', $this->cancelRows());
                break;
            case 'withdraw':
                $this->state('destination');
                $this->reply('Withdraw sandbox ETH · Paste ethereum-sepolia: followed by the external 0x address. Check the full address and network. Example: ethereum-sepolia:0x…', $this->cancelRows());
                break;
            case 'confirm':
                $transfer = $this->wallet->confirm($this->user['id'], $context['quote_id']);
                $this->clearState();
                $this->transaction($transfer);
                break;
            case 'history': $this->history((int) ($context['page'] ?? 1));
                break;
            case 'detail': $this->transaction($this->wallet->details($this->user['id'], $context['id']));
                break;
            case 'deposit_detail': $this->deposit((int) $context['id']);
                break;
            case 'fees': $this->fees();
                break;
            case 'support': $this->support();
                break;
            case 'ticket': $this->ticket((int) $context['id'], false);
                break;
            case 'ticket_new': $this->state('ticket_new');
                $this->reply('Describe the issue in 1–1000 characters. Never include a private key or recovery phrase.', $this->cancelRows());
                break;
            case 'ticket_reply': $this->state('ticket_reply', ['id' => $context['id']]);
                $this->reply('Enter your follow-up (1–1000 characters).', $this->cancelRows());
                break;
            default: $this->admin($action, $context);
                break;
        }
    }

    private function onboarding(): void
    {
        if ($this->user['accepted_at']) {
            $this->menu();

            return;
        }
        $this->reply("SANDBOX · ETH wallet\nFake custody on ethereum-sepolia. Do not send real assets.\nTelegram account access controls this custodial wallet. Transfers can be irreversible.\nBy accepting, you confirm you are 18+, eligible in your jurisdiction, and accept sandbox terms/privacy v1.", [
            [$this->button('Terms', 'terms'), $this->button('Privacy', 'privacy')],
            [$this->button('I am eligible · Accept v1', 'accept')],
        ]);
    }

    private function accept(): void
    {
        $this->user = $this->wallet->accept($this->user['id']);
        $this->menu();
    }

    private function cancel(): void
    {
        $this->clearState();
        // Cancel invalidates outstanding authorizations, including previews from previous screens.
        // Lock quote requests before callbacks: a concurrent quote job locks its request before
        // publishing a confirm button, so cancellation cannot miss that newly inserted token.
        DB::table('telegram_quote_requests')->where('telegram_id', $this->actor)->update(['status' => 'cancelled']);
        DB::table('telegram_callbacks')->where('telegram_id', $this->actor)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        $this->user['accepted_at'] ? $this->menu() : $this->onboarding();
    }

    private function menu(): void
    {
        $balances = $this->wallet->balances($this->user['id']);
        $text = "SANDBOX · ETH / ethereum-sepolia\nWallet: {$this->user['wallet_code']}\nAvailable: ".Amount::format($balances['available'])."\nPending deposits: ".Amount::format($balances['pending'])."\nReserved: ".Amount::format($balances['reserved'])."\nHeld: ".Amount::format($balances['held']);
        if ($this->user['frozen']) {
            $text .= "\nAccount frozen — contact support.";
        }
        $this->reply($text, [
            [$this->button('Receive', 'receive'), $this->button('Send internally', 'send')],
            [$this->button('Withdraw', 'withdraw'), $this->button('History', 'history')],
            [$this->button('Fees & limits', 'fees'), $this->button('Support', 'support')],
            [$this->button('Terms', 'terms'), $this->button('Privacy', 'privacy')],
        ]);
    }

    private function input(string $state, array $context, string $text): void
    {
        if (! $this->user['accepted_at']) {
            $this->onboarding();

            return;
        }
        switch ($state) {
            case 'recipient':
                $recipient = DB::table('wallet_users')->where('wallet_code', $text)->whereNotNull('accepted_at')->first();
                if (! $recipient || $recipient->id === $this->user['id']) {
                    throw new DomainException('Recipient not found or is your own wallet. Enter another registered wallet code.');
                }
                $this->state('amount', ['kind' => 'internal', 'recipient' => $recipient->wallet_code]);
                $this->reply("Recipient wallet: {$recipient->wallet_code}\nEnter the ETH amount the recipient should receive.", $this->cancelRows());
                break;
            case 'destination':
                $prefix = 'ethereum-sepolia:';
                if (! str_starts_with($text, $prefix) || ! $this->custody->validateAddress(substr($text, strlen($prefix)))) {
                    throw new DomainException('Invalid destination or network. Paste ethereum-sepolia:0x followed by the complete supported address.');
                }
                $this->state('amount', ['kind' => 'withdrawal', 'destination' => substr($text, strlen($prefix))]);
                $this->reply('Enter the ETH amount the external recipient should receive.', $this->cancelRows());
                break;
            case 'amount':
                $amount = Amount::parse($text);
                DB::table('telegram_quote_requests')->insert(['inbox_id' => $this->inboxId, 'telegram_id' => $this->actor, 'status' => 'queued']);
                Queue::connection('database')->push(new PrepareTelegramQuote($this->user['id'], $this->actor, $context, $amount, $this->inboxId));
                $this->clearState();
                $this->reply('Preparing an expiring quote. Wait for the fee preview, then explicitly confirm.', $this->cancelRows());
                break;
            case 'ticket_new': case 'ticket_reply': case 'admin_ticket_reply':
                $this->supportInput($state, $context, $text);
                break;
            case 'admin_target':
                $this->requirePermission($context['permission']);
                $target = DB::table('wallet_users')->where('wallet_code', $text)->first();
                if (! $target) {
                    throw new DomainException('Wallet code not found.');
                }
                if ($context['permission'] === 'accounts') {
                    $balances = $this->wallet->balances($target->id);
                    $rows = [];
                    foreach ($this->wallet->history($target->id)['transfers'] as $transfer) {
                        $rows[] = [$this->button(substr($transfer['id'], 0, 8).' · '.$transfer['status'], 'admin_transaction', ['target' => $target->id, 'id' => $transfer['id']])];
                    }
                    $this->reply("Wallet: {$target->wallet_code}\nFrozen: ".($target->frozen ? 'yes' : 'no')."\nBalances in base units: ".json_encode($balances)."\nLatest 5 transfers:", $rows);
                    $this->clearState();
                } elseif ($context['permission'] === 'hold') {
                    $this->state('admin_hold_amount', ['target' => $target->id]);
                    $this->reply("Wallet: {$target->wallet_code}\nEnter ETH amount to hold or release.", $this->cancelRows());
                } else {
                    $this->clearState();
                    $this->reply("Wallet: {$target->wallet_code}\nConfirm account control:", [[
                        $this->button('Freeze', 'admin_freeze', ['target' => $target->id, 'freeze' => true]),
                        $this->button('Unfreeze', 'admin_freeze', ['target' => $target->id, 'freeze' => false]),
                    ], ...$this->cancelRows()]);
                }
                break;
            case 'admin_hold_amount':
                $this->requirePermission('hold');
                $amount = Amount::parse($text);
                $this->clearState();
                $this->reply('Confirm control for '.Amount::format($amount).':', [[
                    $this->button('Hold funds', 'admin_hold', ['target' => $context['target'], 'amount' => $amount, 'release' => false]),
                    $this->button('Release hold', 'admin_hold', ['target' => $context['target'], 'amount' => $amount, 'release' => true]),
                ], ...$this->cancelRows()]);
                break;
            case 'admin_settlement_amount':
                $this->requirePermission('settlement');
                $amount = Amount::parse($text);
                Queue::connection('database')->push(new ProposeTelegramSettlement($this->actor, $amount, $this->inboxId));
                $this->clearState();
                $this->reply('Sandbox settlement proposal queued. No funds move without a different Owner approval and the execution delay.');
                break;
            default: $this->clearState();
                $this->reply('No input is expected. Open /menu.');
        }
    }

    private function transaction(array $transfer): void
    {
        $status = match ($transfer['status']) {
            'completed' => 'Completed', 'reserved', 'queued' => 'Processing · funds reserved',
            'submitting' => 'Processing · submission started; funds reserved',
            'submitted', 'broadcast' => 'Broadcast · awaiting finality',
            'unknown', 'manual_review' => 'Manual review · outcome uncertain; do not retry payment',
            'failed' => 'Failed · check balances and contact support before retrying',
            default => (string) $transfer['status'],
        };
        $settled = '';
        if ($transfer['status'] === 'completed' && $transfer['kind'] === 'withdrawal') {
            $maximum = (string) $transfer['network_fee'];
            $actual = (string) $transfer['actual_network_fee'];
            $charged = bccomp($actual, $maximum, 0) > 0 ? $maximum : $actual;
            $deduction = bcadd(bcadd((string) $transfer['amount'], (string) $transfer['service_fee'], 0), $charged, 0);
            $settled = "\nNetwork cost charged: ".Amount::format($charged)."\nFinal sender deduction: ".Amount::format($deduction)."\nUnused reserve released: ".Amount::format(bcsub($maximum, $charged, 0));
        }
        $this->reply("Transaction {$transfer['id']}\nStatus: {$status}\nRecipient amount: ".Amount::format((string) $transfer['amount'])."\nService fee: ".Amount::format((string) $transfer['service_fee'])."\nNetwork cost authorized: ".Amount::format((string) $transfer['network_fee'])."\nTotal authorized: ".Amount::format((string) $transfer['total']).$settled."\nChain transaction: ".($transfer['txid'] ?? 'not available'), [
            [$this->button('Refresh status', 'detail', ['id' => $transfer['id']])],
            [$this->button('History', 'history'), $this->button('Menu', 'menu')],
        ]);
    }

    private function history(int $page): void
    {
        $page = max(1, $page);
        $transfers = DB::table('transfers')->selectRaw("id, status, amount, created_at, 'transfer' as entry_type")
            ->where(fn ($query) => $query->where('user_id', $this->user['id'])->orWhere('recipient_user_id', $this->user['id']));
        $deposits = DB::table('deposits')->selectRaw("id, status, amount, created_at, 'deposit' as entry_type")->where('user_id', $this->user['id']);
        $entries = DB::query()->fromSub($transfers->unionAll($deposits), 'entries')->orderByDesc('created_at')->orderBy('entry_type')->orderBy('id')->offset(($page - 1) * 5)->limit(6)->get();
        $hasMore = $entries->count() > 5;
        $entries = $entries->take(5);
        $text = 'Transaction history · page '.$page;
        $rows = [];
        foreach ($entries as $entry) {
            $label = ($entry->entry_type === 'deposit' ? 'Deposit #' : 'Transfer ').substr((string) $entry->id, 0, 8);
            $text .= "\n".$label.' · '.$entry->status.' · '.Amount::format((string) $entry->amount);
            $rows[] = [$this->button($label, $entry->entry_type === 'deposit' ? 'deposit_detail' : 'detail', ['id' => (string) $entry->id])];
        }
        if ($entries->isEmpty()) {
            $text .= "\nNo transactions yet.";
        }
        $navigation = [];
        if ($page > 1) {
            $navigation[] = $this->button('Previous', 'history', ['page' => $page - 1]);
        }
        if ($hasMore) {
            $navigation[] = $this->button('Next', 'history', ['page' => $page + 1]);
        }
        if ($navigation) {
            $rows[] = $navigation;
        }
        $rows[] = [$this->button('Back to menu', 'menu')];
        $this->reply($text, $rows);
    }

    private function deposit(int $id): void
    {
        $deposit = DB::table('deposits')->where('id', $id)->where('user_id', $this->user['id'])->first();
        if (! $deposit) {
            throw new DomainException('Deposit unavailable.');
        }
        $this->reply("Deposit #{$id}\nNetwork: {$deposit->network}\nAsset: {$deposit->asset}\nAmount: ".Amount::format((string) $deposit->amount)."\nStatus: {$deposit->status}\nConfirmations: {$deposit->confirmations} / ".config('wallet.confirmations')."\nAddress: {$deposit->address}\nChain transaction: {$deposit->txid}\nPending funds are not spendable. A reorganization can trigger a freeze and manual review.", [
            [$this->button('Refresh deposit', 'deposit_detail', ['id' => $id])],
            [$this->button('History', 'history'), $this->button('Menu', 'menu')],
        ]);
    }

    private function fees(): void
    {
        $text = 'SANDBOX · Fee policy '.config('wallet.fees.version', 'v1');
        foreach (['internal', 'withdrawal'] as $kind) {
            $p = config('wallet.fees.'.$kind, []);
            $text .= "\n{$kind}: fixed ".Amount::format((string) ($p['fixed'] ?? '0')).' + '.($p['bps'] ?? 0).' basis points; minimum '.Amount::format((string) ($p['min'] ?? '0')).'; maximum '.(isset($p['max']) ? Amount::format((string) $p['max']) : 'uncapped');
        }
        $text .= "\nMaximum per transfer: ".Amount::format((string) config('wallet.limits.transaction_max', '0'))."\nDaily deduction limit: ".Amount::format((string) config('wallet.limits.daily_max', '0'))."\nNetwork cost is quoted separately. Service fees accrue for approved batch settlement, with separate approval and delay. Exact authorized deduction appears before confirmation.";
        $this->reply($text, [[$this->button('Back to menu', 'menu')]]);
    }

    private function support(): void
    {
        $rows = [[$this->button('Open a ticket', 'ticket_new')]];
        foreach (DB::table('support_tickets')->where('telegram_id', $this->actor)->orderByDesc('id')->limit(10)->get() as $ticket) {
            $rows[] = [$this->button('Ticket #'.$ticket->id.' · '.$ticket->status, 'ticket', ['id' => $ticket->id])];
        }
        $rows[] = [$this->button('Back to menu', 'menu')];
        $this->reply('Support · latest 10 tickets. Staff never need your private keys or recovery phrases. Replies appear here; response times are not guaranteed in the sandbox.', $rows);
    }

    private function ticket(int $id, bool $admin): void
    {
        if ($admin) {
            $this->requirePermission('support');
        }
        $query = DB::table('support_tickets')->where('id', $id);
        if (! $admin) {
            $query->where('telegram_id', $this->actor);
        }
        $ticket = $query->first();
        if (! $ticket) {
            throw new DomainException('Ticket not found.');
        }
        $text = "Ticket #{$id} · {$ticket->status}\nLatest 3 messages:";
        foreach (DB::table('support_messages')->where('support_ticket_id', $id)->orderByDesc('id')->limit(3)->get()->reverse() as $message) {
            $text .= "\n".($message->actor === $ticket->telegram_id ? 'You' : 'Support').': '.$message->body;
        }
        $this->reply($text, [[$this->button('Reply', $admin ? 'admin_ticket_reply' : 'ticket_reply', ['id' => $id])], [$this->button('Back', $admin ? 'admin_support' : 'support')]]);
    }

    private function supportInput(string $state, array $context, string $text): void
    {
        if ($text === '' || mb_strlen($text) > 1000) {
            throw new DomainException('Use 1–1000 characters.');
        }
        $admin = $state === 'admin_ticket_reply';
        if ($admin) {
            $this->requirePermission('support');
        }
        if ($state === 'ticket_new') {
            $id = DB::table('support_tickets')->insertGetId(['telegram_id' => $this->actor, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        } else {
            $id = (int) $context['id'];
            $query = DB::table('support_tickets')->where('id', $id);
            if (! $admin) {
                $query->where('telegram_id', $this->actor);
            }
            if (! $query->exists()) {
                throw new DomainException('Ticket not found.');
            }
        }
        DB::table('support_messages')->insert(['support_ticket_id' => $id, 'actor' => $this->actor, 'body' => $text, 'created_at' => now()]);
        DB::table('support_tickets')->where('id', $id)->update(['updated_at' => now()]);
        if ($admin) {
            $this->audit('support.replied', (string) $id);
            $owner = DB::table('support_tickets')->where('id', $id)->value('telegram_id');
            Messages::send($owner, "Support replied to ticket #{$id}. Open /support to read it.", 'telegram:support:'.$this->inboxId);
        }
        $this->clearState();
        $this->reply("Message saved to ticket #{$id}.", [[$this->button('View ticket', $admin ? 'admin_ticket' : 'ticket', ['id' => $id])]]);
    }

    private function admin(string $action, array $context): void
    {
        $permission = match ($action) {
            'admin' => 'status', 'admin_accounts' => 'accounts', 'admin_transaction', 'admin_review' => 'review', 'admin_alerts' => 'alerts', 'admin_freeze', 'admin_freeze_start' => 'freeze',
            'admin_hold', 'admin_hold_start' => 'hold', 'admin_pause', 'admin_pause_menu' => 'pause',
            'admin_reconcile' => 'reconcile', 'admin_revenue' => 'revenue',
            'admin_support', 'admin_ticket', 'admin_ticket_reply' => 'support', default => 'none',
            'admin_settlement_amount', 'admin_settlement_list', 'admin_settlement_preview' => 'settlement',
            'admin_settlement_approve' => 'settlement_approve',
        };
        $this->requirePermission($permission);
        switch ($action) {
            case 'admin':
                $rows = [];
                foreach ([['Accounts', 'admin_accounts', 'accounts'], ['Transaction review', 'admin_review', 'review'], ['Alerts', 'admin_alerts', 'alerts'], ['Freeze account', 'admin_freeze_start', 'freeze'], ['Hold/release funds', 'admin_hold_start', 'hold'], ['Emergency pauses', 'admin_pause_menu', 'pause'], ['Run reconciliation', 'admin_reconcile', 'reconcile'], ['Fee revenue', 'admin_revenue', 'revenue'], ['Support cases', 'admin_support', 'support'], ['Propose fee settlement', 'admin_settlement_amount', 'settlement'], ['Fee settlements', 'admin_settlement_list', 'settlement']] as [$label, $next, $capability]) {
                    if ($this->auth->allows($this->actor, $capability)) {
                        $rows[] = [$this->button($label, $next)];
                    }
                }
                $status = DB::table('controls')->pluck('value', 'key')->all();
                $this->reply('SANDBOX · Admin status'."\n".json_encode($status)."\nFee-policy/limit and payout destination changes require reviewed deployment configuration; no mutation buttons exist. Sandbox fee settlements require a different Owner approval, backing checks and at least 24 hours delay.", $rows);
                break;
            case 'admin_accounts': case 'admin_freeze_start': case 'admin_hold_start':
                $this->state('admin_target', ['permission' => $permission]);
                $this->reply('Enter the stable wallet code to review/control.', $this->cancelRows());
                break;
            case 'admin_transaction':
                $t = $this->wallet->details((int) $context['target'], $context['id']);
                $this->reply("Transaction {$t['id']}\nRecorded provider status: {$t['status']}\nKind: {$t['kind']}\nRecipient user: ".($t['recipient_user_id'] ?? 'external')."\nDestination: ".($t['destination'] ?? 'internal')."\nAmount: ".Amount::format((string) $t['amount'])."\nService fee: ".Amount::format((string) $t['service_fee'])."\nNetwork authorization: ".Amount::format((string) $t['network_fee'])."\nProvider reference: ".($t['provider_reference'] ?? 'unavailable')."\nChain transaction: ".($t['txid'] ?? 'unavailable')."\nUpdated: {$t['updated_at']} UTC\nProvider polling updates this record; refresh does not resubmit payment.", [[$this->button('Refresh review', 'admin_transaction', $context), $this->button('Admin menu', 'admin')]]);
                break;
            case 'admin_review':
                $rows = [];
                foreach (DB::table('transfers')->orderByRaw("CASE WHEN status IN ('unknown','manual_review') THEN 0 ELSE 1 END")->orderByDesc('updated_at')->limit(10)->get() as $t) {
                    $rows[] = [$this->button(substr($t->id, 0, 8).' · '.$t->status, 'admin_transaction', ['target' => $t->user_id, 'id' => $t->id])];
                }
                $this->reply('Transaction review · latest 10, uncertain outcomes first. Never resend an uncertain payment under a new key.', [...$rows, [$this->button('Admin menu', 'admin')]]);
                break;
            case 'admin_alerts':
                $unknown = DB::table('transfers')->where('status', 'unknown')->count();
                $review = DB::table('transfers')->where('status', 'manual_review')->count();
                $pending = DB::table('outbox')->whereNull('delivered_at')->count();
                $failed = DB::table('failed_jobs')->count();
                $r = DB::table('reconciliations')->orderByDesc('id')->first();
                $this->reply("Alerts\nUnknown withdrawals: {$unknown}\nManual review: {$review}\nPending outbox: {$pending}\nFailed jobs: {$failed}\nLatest reconciliation: ".($r ? $r->created_at.' · '.($r->balanced ? 'balanced' : 'DISCREPANCY') : 'not run'), [[$this->button('Refresh alerts', 'admin_alerts'), $this->button('Admin menu', 'admin')]]);
                break;
            case 'admin_freeze':
                $this->wallet->setFrozen((int) $context['target'], (bool) $context['freeze'], (int) $this->actor);
                $this->reply('Account freeze control saved.');
                break;
            case 'admin_hold':
                $this->wallet->hold((int) $context['target'], $context['amount'], (bool) $context['release'], (int) $this->actor);
                $this->reply('Hold control saved.');
                break;
            case 'admin_pause_menu':
                $rows = [];
                foreach (['transfers', 'withdrawals'] as $scope) {
                    $rows[] = [$this->button('Pause '.$scope, 'admin_pause', ['scope' => $scope, 'pause' => true]), $this->button('Resume '.$scope, 'admin_pause', ['scope' => $scope, 'pause' => false])];
                }
                $this->reply('Emergency controls · Each button immediately changes the named pause. Resume only after incident review.', [...$rows, ...$this->cancelRows()]);
                break;
            case 'admin_pause':
                $this->wallet->setPaused($context['scope'], (bool) $context['pause'], (int) $this->actor);
                $this->reply('Pause control saved.');
                break;
            case 'admin_reconcile':
                Queue::connection('database')->push(new RunTelegramReconciliation($this->actor, $this->inboxId));
                $this->reply('Reconciliation queued. Custody availability may delay the result.');
                break;
            case 'admin_revenue':
                $r = DB::table('reconciliations')->orderByDesc('id')->first();
                $this->reply($r ? "Latest reconciliation: {$r->created_at}\nEarned service fees: ".Amount::format((string) $r->earned_fees)."\nBalanced: ".($r->balanced ? 'yes' : 'no')."\nUse /settlements for sandbox approved batches." : 'No reconciliation yet. Run reconciliation before reviewing fee availability.');
                break;
            case 'admin_support':
                $rows = [];
                foreach (DB::table('support_tickets')->orderByDesc('updated_at')->limit(10)->get() as $ticket) {
                    $rows[] = [$this->button('Ticket #'.$ticket->id, 'admin_ticket', ['id' => $ticket->id])];
                }
                $this->reply('Latest 10 support cases.', [...$rows, [$this->button('Admin menu', 'admin')]]);
                break;
            case 'admin_ticket': $this->ticket((int) $context['id'], true);
                break;
            case 'admin_ticket_reply':
                $this->state('admin_ticket_reply', ['id' => $context['id']]);
                $this->reply('Enter the support reply (1–1000 characters).', $this->cancelRows());
                break;
            case 'admin_settlement_amount':
                $this->state('admin_settlement_amount');
                $this->reply('SANDBOX · Enter ETH service-fee revenue to propose for settlement. Only reconciled earned fees may fund an approved batch.', $this->cancelRows());
                break;
            case 'admin_settlement_list':
                $rows = [];
                foreach (app(FeeSettlementService::class)->list() as $settlement) {
                    $rows[] = [$this->button(substr($settlement['id'], 0, 8).' · '.$settlement['status'], 'admin_settlement_preview', ['id' => $settlement['id']])];
                }
                $this->reply('SANDBOX · Latest 10 fee settlements. Destination changes are blocked in Telegram.', [...$rows, [$this->button('Admin menu', 'admin')]]);
                break;
            case 'admin_settlement_preview':
                $s = DB::table('fee_settlements')->where('id', $context['id'])->first();
                if (! $s) {
                    throw new DomainException('Settlement unavailable.');
                }
                $rows = [[$this->button('Back to settlements', 'admin_settlement_list')]];
                if ($s->status === 'proposed' && $this->auth->allows($this->actor, 'settlement_approve') && $s->proposed_by !== $this->actor) {
                    array_unshift($rows, [$this->button('Approve this payout · wait 24h+', 'admin_settlement_approve', ['id' => $s->id])]);
                }
                $this->reply("SANDBOX · Settlement {$s->id}\nStatus: {$s->status}\nDestination: {$s->destination}\nOwner receives: ".Amount::format((string) $s->amount)."\nMaximum network cost: ".Amount::format((string) $s->network_fee)."\nTotal earned-fee deduction: ".Amount::format((string) $s->total)."\nProposer: {$s->proposed_by}\nApprover: ".($s->approved_by ?? 'none')."\nEarliest execution: ".($s->eligible_at ?? 'at least 24 hours after distinct Owner approval'), $rows);
                break;
            case 'admin_settlement_approve':
                $s = app(FeeSettlementService::class)->approve($this->actor, $context['id']);
                $this->reply("Sandbox payout approved. Earliest execution: {$s['eligible_at']} UTC. Reconciliation and backing checks still apply.", [[$this->button('Settlement status', 'admin_settlement_preview', ['id' => $s['id']])]]);
                break;
        }
    }

    private function requirePermission(string $permission): void
    {
        if (! $this->auth->allows($this->actor, $permission)) {
            $this->audit('admin.denied', $permission);
            throw new DomainException('Permission denied.');
        }
    }

    private function audit(string $action, string $subject): void
    {
        DB::table('audit_logs')->insert(['actor' => $this->actor, 'action' => $action, 'subject' => $subject, 'metadata' => '{}', 'created_at' => now()]);
    }

    private function state(string $state, array $context = []): void
    {
        DB::table('telegram_conversations')->updateOrInsert(['telegram_id' => $this->actor], ['state' => $state, 'context' => json_encode($context), 'expires_at' => now()->addMinutes(10)]);
    }

    private function clearState(): void
    {
        DB::table('telegram_conversations')->where('telegram_id', $this->actor)->delete();
    }

    private function cancelRows(): array
    {
        return [[$this->button('Cancel', 'cancel')]];
    }

    private function button(string $label, string $action, array $context = []): array
    {
        return Messages::button($this->actor, $label, $action, $context);
    }

    private function reply(string $text, array $rows = []): void
    {
        Messages::send($this->actor, $text, 'telegram:'.$this->inboxId.':'.($this->response++), $rows);
    }
}
