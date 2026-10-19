# Telegram private-conversation specification

This executable sandbox uses fake custody, native ETH precision (18 decimals), and the `ethereum-sepolia` network label. It sends no chain transactions. Every user and admin screen is a Telegram private bot message. The only HTTP surfaces are infrastructure webhooks. Text below uses `{field}` for runtime values; the implementation in `app/Telegram/Bot.php` and quote/address jobs is the canonical copy.

## Identity and message lifecycle

Authenticate the immutable numeric Telegram `from.id`; require a private `chat.id` equal to it. A callback uses `callback_query.from.id`, never the bot-authored message sender. Usernames are display metadata and never recipient/account authorization. Wallet codes such as `WABCD…` identify internal recipients.

The webhook atomically writes a durable `telegram_updates` inbox and database job. Repeated identifiers with unchanged payloads have one effect; a changed payload returns HTTP 409. Each callback produces a durable `answerCallbackQuery` acknowledgment and user reply. Conversation input lasts 10 minutes. Callback data is an opaque random token, stored hashed, bound to one actor, expiring and consumed once. Financial confirmation and callback consumption commit together. Independent user messages serialize through an actor row without changing financial lock order. The outbox retries notifications without replaying payments. Telegram delivery itself is at least once: a network timeout after Telegram accepts a message can produce a duplicate message, never another financial effect.

## Commands and screens

`/start` before acceptance:

```text
SANDBOX · ETH wallet
Fake custody on ethereum-sepolia. Do not send real assets.
Telegram account access controls this custodial wallet. Transfers can be irreversible.
By accepting, you confirm you are 18+, eligible in your jurisdiction, and accept sandbox terms/privacy v1.
```

Rows: `[Terms] [Privacy]`, `[I am eligible · Accept v1]`. Acceptance records a timestamp and terms version. Repeating `/start` after acceptance opens the menu. Self-declared eligibility is adequate only for this sandbox, not a regulated customer onboarding solution.

`/terms` text:

> Sandbox terms v1: fake custody only; no real assets, no buying or investment services. Telegram account access controls your wallet. Blockchain transfers may be irreversible. Eligibility: 18+ and permitted jurisdiction. Real-money service remains unavailable pending independent security, custody and legal approval.

`/privacy` text:

> Sandbox privacy v1: we retain your Telegram numeric ID, current username, wallet, transaction and support records for operation and audit. Telegram and the hosting operator process messages. Do not send identity documents, private keys or recovery phrases here. Retention, data subject procedures and jurisdictional disclosures require legal review before launch.

Both have `[Back]`, returning to onboarding or the menu according to acceptance.

`/menu`:

```text
SANDBOX · ETH / ethereum-sepolia
Wallet: {wallet_code}
Available: {available} ETH
Pending deposits: {pending} ETH
Reserved: {reserved} ETH
Held: {held} ETH
```

A frozen account adds `Account frozen — contact support.` Rows: `[Receive] [Send internally]`, `[Withdraw] [History]`, `[Fees & limits] [Support]`, `[Terms] [Privacy]`. Reserved and held amounts are not spendable. `/cancel` removes conversation state, invalidates all outstanding buttons and pending quote requests, then creates a fresh menu. All input steps include `[Cancel]`; menu/history/detail screens include labeled return controls.

`/receive` first says `Preparing your sandbox deposit address. No real assets are supported.` A durable job allocates outside a database transaction, verifies the address and stores one unique address per wallet. The result is:

```text
SANDBOX · Receive ETH
Network: ethereum-sepolia only
Address: {address}
Fake custody address: do not send real assets.
Deposits remain pending until the configured confirmation policy is met. Delays and reorganizations can require review.
```

Rows: `[Copy address]` (Telegram `copy_text`, containing the full address), `[Back to menu]`. Allocation exhaustion: `Address allocation is delayed. Open /receive to retry or /support for help. Do not use an address from another person.`

`/send`:

1. `Send internally · Enter the recipient wallet code (W…). Use their stable code; usernames are not accepted.`
2. `Recipient wallet: {wallet_code}` then `Enter the ETH amount the recipient should receive.`
3. On valid amount: `Preparing an expiring quote. Wait for the fee preview, then explicitly confirm.`

`/withdraw`:

1. `Withdraw sandbox ETH · Paste ethereum-sepolia: followed by the external 0x address. Check the full address and network. Example: ethereum-sepolia:0x…`
2. `Enter the ETH amount the external recipient should receive.`
3. Same preparing-quote message. The adapter currently accepts lowercase Ethereum addresses only and rejects the zero address; mixed-case/checksummed validation awaits a real adapter. Bot-owned deposit destinations are rejected by the domain service; use internal transfer instead.

The asynchronously prepared preview is:

```text
SANDBOX · Review transfer
Network: ethereum-sepolia
Recipient: {wallet_code_or_full_destination}
Recipient gets: {amount} ETH
Service fee: {service_fee} ETH
Maximum network cost: {network_fee} ETH
Total deduction: {total} ETH
Expires: {expires_at} UTC
Only Confirm authorizes this deduction.
```

Rows: `[Confirm transfer]`, `[Cancel]`. The confirm token expires with the quote. A cancel that arrives while the provider quote is being prepared prevents creation of a later confirm button. The domain rechecks recipient, ownership, pause/freeze, balance, fee policy, limits and expiry at confirmation. An internal transfer posts atomically; a withdrawal reserves funds and queues submission.

Quote provider exhaustion: `The custody quote is unavailable. No transfer was authorized. Open /menu to try again.` Business validation errors append `Open /menu to start again.`

Transaction detail:

```text
Transaction {id}
Status: {status_label}
Recipient amount: {amount} ETH
Service fee: {service_fee} ETH
Network cost authorized: {network_fee} ETH
Total authorized: {total} ETH
Chain transaction: {txid_or_not_available}
```

Rows: `[Refresh status]`, `[History] [Menu]`. These amounts describe the original transfer authorization, including when viewed by its recipient. Completed withdrawals additionally display `Network cost charged: {charged}`, `Final sender deduction: {deduction}` and `Unused reserve released: {refund}` before the chain transaction line. Charged network cost is capped at authorization; the final deduction excludes unused reserve. Status labels: `Completed`; `Processing · funds reserved`; `Processing · submission started; funds reserved`; `Broadcast · awaiting finality`; `Manual review · outcome uncertain; do not retry payment`; `Failed · check balances and contact support before retrying`. Unknown domain statuses are displayed literally, not translated to success. The provider's finality policy controls completion, not HTTP submission success.

`/history` shows `Transaction history · page {page}` with at most five owned outgoing/incoming transfers and deposits, each showing identifier, status, amount and a detail button. Rows end with `[Previous]` and/or `[Next]` and `[Back to menu]`. Empty text: `No transactions yet.` The current implementation uses offset pagination; newly inserted transactions can move between pages, so refresh page one when checking recent activity.

Deposit detail:

```text
Deposit #{id}
Network: {network}
Asset: {asset}
Amount: {amount} ETH
Status: {status}
Confirmations: {count} / {required}
Address: {address}
Chain transaction: {txid}
Pending funds are not spendable. A reorganization can trigger a freeze and manual review.
```

Rows: `[Refresh deposit]`, `[History] [Menu]`. Deposit/transfer details independently check ownership, including inbound transfers.

`/fees` and `/limits` show the current policy version, fixed fee, basis points, minimum, maximum, transaction maximum and daily deduction maximum. The exact template is generated from configuration:

```text
SANDBOX · Fee policy {version}
{internal|withdrawal}: fixed {fixed} ETH + {bps} basis points; minimum {min} ETH; maximum {max_or_uncapped}
Maximum per transfer: {max} ETH
Daily deduction limit: {daily_max} ETH
Network cost is quoted separately. Service fees accrue for approved batch settlement, with separate approval and delay. Exact authorized deduction appears before confirmation.
```

Button: `[Back to menu]`.

## Support

`/support`: `Support · latest 10 tickets. Staff never need your private keys or recovery phrases. Replies appear here; response times are not guaranteed in the sandbox.` Rows: `[Open a ticket]`, one `[Ticket #{id} · {status}]` per latest ten tickets, `[Back to menu]`.

New ticket prompt: `Describe the issue in 1–1000 characters. Never include a private key or recovery phrase.` Follow-up prompt: `Enter your follow-up (1–1000 characters).` Ticket screen: `Ticket #{id} · {status}`, `Latest 3 messages:`, `You: {text}` / `Support: {text}`; rows `[Reply]`, `[Back]`. Saving: `Message saved to ticket #{id}.` with `[View ticket]`. A staff reply sends the customer `Support replied to ticket #{id}. Open /support to read it.`

All support reads/writes check owner or the fresh Support permission. This is deliberately a minimal latest-ten queue/latest-three-message view; full transcript pagination, ticket closure and SLA management remain deferred. Never send verification documents to this sandbox bot.

## Administrator conversations

`/admin` requires an accepted sandbox user plus configured numeric-ID roles. Fresh authorization is checked for the command, every button, every subsequent input and queued reconciliation/settlement proposal. Admin buttons expire and are single use. Denials and mutations are audited.

| Action/button | Roles | Executable flow |
|---|---|---|
| Status | Owner, Operations, Compliance, Finance, Support | Display current pause flags and implementation restrictions |
| Accounts | Owner, Operations, Compliance | Enter wallet code; view freeze/balances and last five transfers; review/refresh each transaction |
| Transaction review | Owner, Operations, Compliance, Finance | Latest ten records, uncertain outcomes first; full identifier, recorded provider status/reference, destination, amounts, chain ID and update timestamp; refresh never resubmits |
| Alerts | Owner, Operations, Compliance, Finance | Counts of unknown/manual-review withdrawals, pending outbox, failed jobs and latest reconciliation; refresh button |
| Freeze account | Owner, Compliance | Enter wallet code; `[Freeze] [Unfreeze]` explicitly applies named control |
| Hold/release funds | Owner, Compliance | Enter wallet code and ETH amount; `[Hold funds] [Release hold]` applies named ledger control |
| Emergency pauses | Owner, Operations | `[Pause transfers] [Resume transfers]`, `[Pause withdrawals] [Resume withdrawals]`; each button immediately applies the named audited control |
| Run reconciliation | Owner, Finance | Queue provider balance fetch outside DB transaction; report balanced/discrepancy and exact custody, ledger, liabilities, earned fees in base units |
| Fee revenue | Owner, Finance | Read the latest reconciliation with its timestamp; it is a snapshot, not authorization to pay |
| Support cases | Owner, Support | Latest ten cases, thread, staff reply; recheck access before saving |
| Propose fee settlement; `/settle` | Owner, Finance | Ask ETH amount, queue idempotent fake-custody proposal; show full destination, owner receipt, maximum network cost, total earned-fee debit and ID |
| Fee settlements; `/settlements` | Owner, Finance | Latest ten proposals/statuses; review full destination/amount/fees, proposer, approver and earliest execution |
| Approve this payout · wait 24h+ | A distinct Owner | Fresh owned callback; domain verifies distinct proposer/approver, current roles, reconciliation, revenue and backing; reserve platform earnings, then wait at least 24h |

Settlement preview text starts `SANDBOX · Settlement {id}` and names `Status`, `Destination`, `Owner receives`, `Maximum network cost`, `Total earned-fee deduction`, `Proposer`, `Approver`, `Earliest execution`. Approval success: `Sandbox payout approved. Earliest execution: {eligible_at} UTC. Reconciliation and backing checks still apply.` Only an eligible distinct Owner sees its approval button. No admin messaging is sent automatically to other administrators; they independently open `/settlements`.

Payout destination mutation has no bot command. Fee/limit mutation similarly requires reviewed deployment configuration. Real settlement is unavailable: the application permits only the fake adapter. Infrastructure database/queue health, proactive broad alert routing, complete administrative search and high-risk configuration governance remain operator/deployment work, not hidden bot features. The bot's Alerts screen exposes the operational counts above.

## Validation and recovery copy

| Condition | Exact text/recovery |
|---|---|
| Expired/used/foreign token | `This button expired, was already used, or belongs to another account. Open /menu for fresh buttons.` |
| No active/expired input state | `This conversation has expired or no input is expected. Open /menu to continue.` |
| Unknown command | `Unknown command. Open /menu for available actions.` |
| Invalid wallet | `Recipient not found or is your own wallet. Enter another registered wallet code.` |
| Invalid destination/network | `Invalid destination or network. Paste ethereum-sepolia:0x followed by the complete supported address.` |
| Invalid decimal | `Enter a positive ETH amount using at most 18 decimal places. Example: 0.01` |
| Zero amount | `The amount must be greater than zero.` |
| Invalid support length | `Use 1–1000 characters.` |
| Missing owned ticket | `Ticket not found.` |
| Missing owned deposit | `Deposit unavailable.` |
| Admin authorization | `Permission denied.` |
| Unknown target wallet | `Wallet code not found.` |

Financial service failures such as `Insufficient available balance including fees.`, `Amount exceeds the transaction limit.`, or `This quote expired. Create a new preview.` are returned verbatim with a fresh `[Back to menu]` control. The source domain service defines the exact current wording; no error is silently turned into a completed transfer.

## Automated evidence and deferred validation

`tests/Feature/TelegramTest.php` covers webhook secret/private binding, changed duplicate payloads, duplicate updates, immutable IDs despite username changes, onboarding, owned/expired/replayed callbacks, durable callback acknowledgment, internal fee preview/one confirmation, pending quote cancellation, wrong network/decimal validation/conversation timeout, deposit address/copy idempotency, deposit pagination/details, role removal before admin callback, and support ownership. Full Telegram device rendering, accessibility, actual Bot API copy-button behavior, provider integration and human support rehearsals are launch checks, not claimed by local feature tests.
