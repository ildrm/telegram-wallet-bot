# Custody contract and platform fees

## Explicit sandbox boundary

Only `FakeCustodyProvider` is implemented. It allocates deterministic, syntactically valid ETH-shaped addresses, persists simulated provider balances/payments and can inject failures. It owns no keys and cannot receive or send ETH on Sepolia or any other chain. `WALLET_LIVE_ENABLED=true`, a driver other than `fake`, or another network/asset fails closed during application boot and adapter resolution. Changing an environment flag cannot enable real-money service.

Reference asset is native ETH, 18 decimal places, with the network label `ethereum-sepolia`. The fake confirmation threshold is 12. **This threshold simulates a policy; it is not a claim of Ethereum consensus finality.** A future vetted real adapter must independently verify chain identity, native transfer semantics, canonical block inclusion and the agreed finalized checkpoint/provider finality before exposing funds or declaring a withdrawal complete.

## Adapter responsibilities

`app/Custody/CustodyProvider.php` defines these methods. All amounts are exact nonnegative integer strings in wei.

| Method | Required semantics |
|---|---|
| `allocateAddress(walletCode)` | Same customer allocation on retry; exact network/asset scope; independently verify returned address belongs to the provider-controlled custody account. No signing material is returned. |
| `validateAddress(destination)` | Reject unsupported address formats. Fake accepts a conservative lowercase nonzero 40-hex ETH address. A same-format address does not prove network, ownership, contract behavior or recipient identity. |
| `quoteWithdrawal(destination,amount)` | Return maximum authorized network/provider cost and expiry; adapter defines guarantees, fee components and expiry. Do not invent a real-provider quote API. |
| `submitWithdrawal(key,destination,amount,maxNetworkFee)` | Permanent logical idempotency and identical-parameter checks; an accepted call may still time out. Never silently substitute destination, network, amount or exceed authorized deduction. |
| `withdrawalStatus(key)` | Query the original external identifier; `null` means authoritative absence, errors throw. Current domain conservatively retains funds even after absence once submission was claimed. `confirmed` must mean final under reviewed policy and include actual charged cost; `failed` is safe to refund only with explicit zero charged cost. |
| `balance()` | Confirmed spendable provider assets matching the ledger's custody scope, excluding unrelated accounts/assets; a real implementation needs timestamp, in-flight reconciliation items and event watermark. |

Provider transport remains outside financial database transactions. Withdrawal reservation and an outbox intent commit together; state changes from `reserved` to `submitting` before a call. A process crash between this state claim and the call can retain funds without a send; this conservative liveness limitation requires independent operational recovery before live launch. Resubmitting a second payment is never the repair path.

The [official Fireblocks idempotency documentation](https://developers.fireblocks.com/reference/api-idempotency) is relevant due diligence, including its finite HTTP idempotency retention. A real adapter must verify durable external transaction identity and retrieval beyond that retention; it cannot infer permanent safety from a short-lived request header. Candidate research and non-selection are documented in [review.md](review.md). No provider is recommended as approved, licensed for this operator, or technically integrated.

## Authenticated fake deposit callback

`POST /webhooks/custody` is **our simulator contract**, not an invented Fireblocks/BitGo API. Headers:

- `X-Custody-Timestamp`: UTC Unix seconds, within 300 seconds.
- `X-Custody-Signature`: lowercase HMAC-SHA256 hex of `timestamp + "." + exact raw request body`, keyed by `CUSTODY_WEBHOOK_SECRET`.

Required JSON example:

```json
{"event_id":"provider-notification-1","asset":"ETH","network":"ethereum-sepolia","txid":"0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","index":0,"address":"0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","amount":"1000000000000000000","confirmations":12,"removed":false}
```

Optional `block_hash` identifies the canonical block. Send a fresh notification ID when confirmations/removal changes; immutable chain identity is `(network,txid,index)`. A notification ID reused with a different body gets HTTP 409. A chain event reused with a changed amount/address/asset is rejected and retained for manual investigation. Unsupported assets/networks are quarantined and never credited. Unknown addresses remain unrecognized; custody balance divergence triggers reconciliation. Reordered lower confirmation counts cannot re-credit. A removal or changed canonical block after credit appends a compensating journal, freezes the wallet and pauses transfers. Re-inclusion recovery is a live blocker requiring reviewed procedures.

Inbox jobs and notification outbox records persist in MySQL. After exhausted retries, retained records need operator investigation, not deletion or a new event identity. A future provider has its own documented authentication/canonicalization/replay scheme; the simulator HMAC must never be presumed compatible.

## Fees and settlement

Policies snapshot a version, fixed wei fee, integer basis points, minimum and optional maximum. Percentage fee rounds upward to a whole base unit; no PHP float, MySQL approximate type or decimal-as-float JSON is used. Preview shows recipient amount, service fee, maximum network/provider cost and total authorized deduction. A policy version change invalidates outstanding quotes. Quote expiry does not extend on callback retries.

Internal transfers recognize platform revenue immediately as part of the balanced recipient transfer. Withdrawals reserve recipient amount+service fee+maximum network cost; revenue recognizes only upon final completion. Actual cost below cap refunds unused reserved wei. An overrun is a platform expense and pauses withdrawals; it never increases customer authorization. A definitive free failure releases reserve once. A charged on-chain failure enters manual review with reserve retained pending reviewed accounting. Network expense and cost recovery use separate ledger accounts from service-fee revenue.

Owner settlement is periodic **batch settlement**, not a separate blockchain send for each customer fee. `FeeSettlementService` requires a recent balanced reconciliation, revenue net of network expense/recovery, full customer backing, no unresolved outbound requests, a configured external destination, a Finance/Owner proposal, a different Owner approval and a delay of at least 24 hours. Platform equity is reserved in `system:fee_payable`. Execution rechecks roles, destination, pauses and provider/ledger balances. Once claimed, uncertain results reconcile the original `settlement:UUID` only. Settlement network costs are paid by the platform. A changed destination causes manual review; this release deliberately has no destination-change UI. Actual mainnet payout support and human approval remain absent.

## Suitability work still required

Before selecting custody, independently review legal entity/jurisdiction and prohibited-country policies, segregation and beneficial ownership, insolvency treatment, key management/MPC and signing policy, independent assurance reports and scope, incident history, admin authentication, subcustodians, insurance exclusions, address allocation/ownership, network finality/reorg behavior, idempotency retention, webhook authenticity, delivery ordering, withdrawal approval policy, asset/balance scope, gas-charge failures, limits, maintenance/incident response, exports and reconciliation watermarks. Execute a real provider sandbox contract/chaos suite and independently approve the custody agreement. No live adapter code should be inferred from this document.
