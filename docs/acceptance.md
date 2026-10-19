# Acceptance and failure-injection evidence

This matrix is an acceptance contract. **Required** means an expected result, not a claim that a test ran. Evidence is recorded below after execution. SQLite tests cannot prove MySQL locking, constraints, deadlock recovery or concurrency.

## Financial invariants

For each test, inspect journal balance per asset, unique posting keys, nonnegative spendable/held balances, unchanged immutable history, and the relationship of custody assets to customer liabilities plus explicitly explained in-flight reconciliation items. A successful bot response is insufficient evidence.

| ID | Scenario | Required outcome / evidence |
|---|---|---|
| F01 | Repeat `/start` / concurrent account creation | One account per numeric Telegram ID; stable wallet code; no duplicate opening accounts. |
| F02 | Duplicate finalized deposit and different notification IDs for same event | One credit keyed by chain/asset/transaction/event identity; duplicate body cannot increase balances. |
| F03 | Duplicate Telegram update; failure after inbox insert | Replaying the same update is safe and recovers unfinished processing; no second financial effect. |
| F04 | Double-click confirm / duplicate HTTP delivery | Exactly one immutable intent and posting; repeat returns the original result or stale-button message. |
| F05 | Twenty simultaneous withdrawals | Twenty OS processes/connections start behind a barrier against one MySQL-funded account; successful reservations fit the balance, failures are safe; no negative available value and sum of available+reserved remains correct. Record workers, start barrier, exit results and journal sums. |
| F06 | Insufficient balance incl. all fees | Reject without provider call or journal mutation; error offers lower amount or cancel. |
| F07 | Expired quote / altered fee policy | Reject expired/stale intent and require new preview; user cannot authorize more than shown. |
| F08 | Invalid address, zero address, wrong network/asset, extra decimals | Reject before reserve. ETH address shape does not prove correct network or ownership; do not claim otherwise. |
| F09 | Pending deposit / confirmation delay | Show pending only, never spendable; finalize once when policy met. |
| F10 | Reorg before/after credit, including already spent funds | No early credit; one compensating reversal after credit; freeze/incident if customer cannot cover; immutable original journal remains. |
| F11 | Provider downtime before acceptance | Safe retry or guarded failure; funds never released merely because a request timed out. |
| F12 | Unknown outcome after provider acceptance | Preserve original external ID and reserved funds; retrieve status; no second broadcast or fresh identifier. |
| F13 | Provider definitive failure / delayed withdrawal | Release reserve only on authoritative terminal failure; delayed remains processing/held; no premature completed receipt. |
| F14 | Network fee increases beyond authorization | Before submission, refuse a provider quote above the cap; preserve reserved funds when acceptance is uncertain. After confirmed finality, cap the customer's charge at authorization, record the actual overrun as platform network expense and pause withdrawals; never pretend to undo a finalized payment. Lower actual fee releases unused reserve; costs do not become service revenue. |
| F15 | Notification fails after ledger commit | Durable outbox retries notification, receipt remains discoverable, financial posting does not repeat. |
| F16 | Username changed/reassigned | Sender identity remains numeric ID; stable code still selects correct recipient; username lookup never transfers without immutable-account preview. |
| F17 | Invalid/removed admin permission and cross-user callback | Reject each privileged action using current role and sender binding; no mutation or sensitive disclosure. |
| F18 | Missing/wrong Telegram or custody secret, stale signature/replay | Reject before accepting event; raw signed body is verified with provider-specific protocol for a real adapter. |
| F19 | Unauthorized owner destination, self-approval, activation delay | Payout/change rejected; independent roles, delay, approved address and durable audit enforced. |
| F20 | Custody discrepancy / stale reconciliation | Pause outbound financial actions and owner settlement; persist discrepancy and alert until reviewed resolution. |
| F21 | Restore backup and replay chain/provider events | In quarantine with outbound disabled, restore and replay produce no duplicate deposits or withdrawals; reconcile original identifiers before enabling queues. Requires real backup artifact and drill log. |
| F22 | Frozen account / global pause / funds held | All financial entry points including old confirm callbacks enforce holds and pause inside transaction. |
| F23 | Journal immutability / compensating record | Attempts to update/delete posted records fail at the supported DB boundary; authorized corrections append balanced journals. |
| F24 | Owner settlement amount / backing | Payout cannot exceed reconciled earned revenue net of paid fees/costs or reduce custody below customer liabilities and commitments. |
| F25 | Decimal precision / percentage boundaries | BCMath integer strings; no floating conversion; exact documented rounding at min/max/18-decimal boundaries. |
| F26 | Deadlock / retry / worker crash | Bounded retry leaves one posting and one external intent. Crash before/after provider submission resumes original identifier. |
| F27 | Duplicate event body with conflicting amount/address/asset | Reject/quarantine immutable event conflict; never overwrite accepted event or credit a different account. |
| F28 | Provider callback reordered after terminal state | Terminal state cannot regress; unexpected contradiction quarantined for reconciliation. |

## Telegram acceptance

| ID | Flow | Required outcome |
|---|---|---|
| U01 | Onboarding | Sandbox/risk/eligibility/terms disclosure before enabled wallet actions; versioned consent; never request seed phrase/private key. |
| U02 | Menu / receive | Available, pending and held separately; native ETH and ethereum-sepolia label; fake-address warning; copy control or selectable code; pending explanation. |
| U03 | Internal send | Stable recipient code, amount validation, recipient amount/service fee/no chain cost/total preview, explicit confirm and Back/Cancel. |
| U04 | Withdrawal | Network/address/amount validation, recipient amount/service fee/network cap/total preview, expiry; statuses distinguish processing, unknown/manual review, failed and completed. |
| U05 | History/details | Paginated own records only, stable receipts, exact fees and status; other-account IDs cannot disclose data. |
| U06 | Terms/privacy/fees/limits | Current in-chat disclosure; sandbox fees and limits match enforcement; no external website needed. |
| U07 | Support | Ticket creation, own ticket follow-up, authorized support reply; privacy warning; no secrets requested. |
| U08 | Admin | Owner/Operations/Compliance/Finance/Support permission checks; private chat only; durable audit; safe status/review/hold/fees/reconciliation/alert/support/policy/limit/pause flows. |
| U09 | State recovery | Restart retains state; unrelated messages rejected; expired/wrong-user buttons rejected; `/menu` and Cancel give recovery. |
| U10 | Transport restrictions | Only infrastructure webhooks exposed; groups/channels/inline contexts cannot create/operate wallets; Telegram sends use HTTPS and controlled bot token. |

## Execution record

Evidence is specific to the commands and assertions below. All rows without such evidence remain pending. A real-provider contract suite, live chain finality review, independent security test, encrypted off-host backup/PITR and full production-environment restoration remain pending even when fake-provider feature tests pass.

| Run | Environment / command | Result | Covered IDs / limitations |
|---|---|---|---|
| 2026-10-19 | `php artisan test --compact`; SQLite in memory | PASS: 51 tests, 274 assertions, 1176 ms | Unit/feature sandbox cases mapped below; HTTP transport is simulated and custody is fake. SQLite evidence does not establish MySQL behavior. |
| 2026-10-19 | `vendor/bin/phpunit -c phpunit.mysql.xml --display-all-issues --log-junit /private/tmp/telegram-wallet-mysql-junit.xml`; MySQL 8.4/InnoDB | PASS: 53 tests, 395 assertions, 19336 ms, exit 0 | Full Unit + Feature + Integration suites. Includes actual 20-process concurrency and real dump/restore below. MySQL JSON object-key reordering has a regression test; duplicate payload comparison preserves array order and scalar types. |
| 2026-10-19 | `/opt/homebrew/bin/php vendor/bin/phpunit -c phpunit.mysql.xml --filter MySqlConcurrencyTest`; isolated local MySQL 8.4/InnoDB | PASS: 1 test, 77 assertions, 2186 ms | F05: twenty OS workers, twenty distinct connection IDs, all waiting before shared release; exactly 5 successful reserves and 15 insufficient rejections from funds for 5.5 deductions; 14 exact balanced postings, no provider submission. F26 deadlock/crash injection remains pending. |
| 2026-10-19 | `/opt/homebrew/bin/php vendor/bin/phpunit -c phpunit.mysql.xml --filter MySqlRestoreTest`; isolated MySQL 8.4 source/restored databases | PASS: 1 test, 44 assertions, 3022 ms | F21 sandbox drill: actual `mysqldump`/`mysql` restoration includes immutable triggers; journal/posting/balance equality; authenticated HTTP replay of original event and new delivery ID credits once; original uncertain withdrawal recovery explicitly expects one original-ID status lookup and forbids submission; clean backing and both pauses retained. Temporary dump deleted and only drill databases dropped. Real-provider recovery and encrypted off-host/PITR remain unverified. |
| 2026-10-19 | `composer validate --strict`; `vendor/bin/pint --test`; `composer audit --locked --format=json` | PASS; audit: `advisories: []`, `abandoned: []` | Dependency metadata, formatting and published Composer advisory checks. This is not an independent security audit or container vulnerability scan. |
| 2026-10-19 | `docker build -t telegram-wallet-sandbox:local .`; isolated MySQL runtime smoke; `docker compose config --quiet` | PASS | PHP 8.5 image builds as nonroot runtime; fresh MySQL migrations, balanced zero-opening reconciliation, queue one-shot startup, callback-only route listing, status and `php-fpm -t` pass. Separate temporary smoke database removed. Compose validates with a dedicated one-shot migrator; public TLS/connected Telegram and full orchestration are untested. |

## Trace from acceptance to executed tests

All suites below passed on both SQLite and MySQL unless explicitly marked Integration. These mappings identify exercised behavior, not blanket completion of every condition in a row.

| Acceptance | Executed evidence | Scope and limits |
|---|---|---|
| F01/F16; U03 | [LedgerTest](../tests/Feature/LedgerTest.php) `test_registration_is_idempotent_and_username_never_authenticates`; [TelegramTest](../tests/Feature/TelegramTest.php) `test_username_is_never_a_recipient_or_authorization_identifier` | Repeated registration and username misuse covered; simultaneous account-creation stress is pending. |
| F02/F09/F27/F28 | Ledger `test_deposit_confirmation_delay_duplicate_and_replay`; [CustodyTest](../tests/Feature/CustodyTest.php) authenticated-duplicate and out-of-order-confirmation tests; [MySqlRestoreTest](../tests/Integration/MySqlRestoreTest.php) | One credit per event, pending confirmation, conflicting payload and re-delivery exercised with fake-provider events. Real canonical-chain reordering/finality remains pending. |
| F03/F04/F07/F17/F18; U09/U10 | Telegram duplicate-update, reordered-nested-key, callback-single-use, cancellation and stale-admin-role tests; Custody forged/expired-signature tests; Ledger stale-quote/changed-policy tests | Distinct scalar types conflict; reordered JSON object keys do not. Crash between durable inbox/job writes is addressed structurally, but explicit process-kill injection is pending. |
| F05/F06 | [MySqlConcurrencyTest](../tests/Integration/MySqlConcurrencyTest.php), 77 assertions; Ledger competing-quotes test | Actual 20-process MySQL reservation contention, exact funds, one posting per accepted transfer and insufficient-balance rejections. SQLite competing quotes are supplementary sequential evidence. |
| F08/F25; U02/U04 | [AmountTest](../tests/Unit/AmountTest.php), Ledger invalid-network/address and fee-rounding tests; Telegram invalid-input, smallest-unit and copyable-address tests | Exact base units/rounding and single-network syntax exercised; no real address ownership/network attestation or chain delivery verified. |
| F10/F22/F23 | Ledger reorg-after-internal-spend, held-funds, journal-immutability and mixed-asset rejection tests | Compensating reversal, freeze/pause, append-only DB triggers and asset separation covered. Production reorg monitoring and pre-finality re-inclusion recovery remain pending. |
| F11/F12/F13/F14 | Ledger provider-outage, unknown-submission, definitive-failure, failure-with-chain-cost and fee-overrun tests; Custody timeout-after-submit test | Uncertain funds retained; original request reconciled; failure with network cost held for review; user fee cap protected. Real-provider failure semantics remain pending. |
| F15 | [DeliveryTest](../tests/Feature/DeliveryTest.php) `test_notification_failure_leaves_ledger_untouched_and_can_retry` | Bot HTTP failure preserves the committed ledger and retry succeeds with a mocked response. Live Telegram delivery is untested. |
| F19/F24; U08 | [FeeSettlementTest](../tests/Feature/FeeSettlementTest.php), 5 tests/26 assertions; Telegram settlement-approval and Finance-permission tests | Distinct approver, delay, destination substitution, insufficient revenue/customer backing and unknown-owner-payment recovery covered. Destination-change workflow remains disabled; no live approval implied. |
| F20 | Ledger discrepancy and failed-reconciliation-before-submit tests; fee-settlement recent-clean-reconciliation check | Failed reconciliation pauses/restricts money movement. Fully timed stale-snapshot detection and durable alert delivery are not independently exercised. |
| F21 | MySQL restore integration, 44 assertions | Actual dump/import, immutable triggers, authenticated simulated event replay and lookup-only uncertain withdrawal recovery passed. Off-host encryption/PITR/real-provider drill remains pending. |
| F26 | Reservation contention and original-ID unknown/restore recovery | Explicit forced-deadlock retry exhaustion, OS crash at each financial boundary and queue starvation/load tests remain pending. |
| U01/U03/U05/U07/U08/U09/U10 | Telegram suite, 16 tests/111 assertions; Delivery `test_web_backend_exposes_only_two_post_callbacks` | Private sender checks, onboarding-driven transfer flow, fee preview, owner-scoped deposit history, support ownership, role rechecks, stale state and two infrastructure POST routes covered. No connected Telegram device or external bot session was used. |
| U06 | Current in-chat terms/privacy/fees/limits implementation; exact fee policy assertions in Ledger suite | Complete human UX walkthrough, accessibility/localization and jurisdiction-specific legal wording remain pending. |

Remaining engineering validation includes full TLS/connected-Telegram deployment, explicit deadlock/process-kill/load injection, timed stale reconciliation/alert delivery, off-host backup/PITR and real-provider integration/finality. Independent security/custody/legal approvals remain separate launch blockers regardless of these test results.
