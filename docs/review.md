# Architecture and independent review

Review date: 2026-10-19. This is an engineering sandbox review, not a custody, security, or legal approval. Twelve specialist perspectives were applied across the implementation and challenge work; this does not represent twelve independent people or external sign-offs.

## 1. Executive architecture recommendation

Use a Laravel 13 modular monolith, PHP BCMath string integers, MySQL 8.4/InnoDB, database-backed queues, durable inbox/outbox records, and Telegram private bot conversations. The reference asset is native ETH (18 decimals), with `ethereum-sepolia` as the sandbox network label. The executable custody adapter is fake: its addresses, balances, finality and transaction hashes are simulations. It does not connect to Sepolia, allocate usable deposit addresses, sign, broadcast, or custody funds. Real-money operation must fail closed.

Use unique provider-allocated deposit addresses, immutable numeric Telegram IDs, generated stable wallet codes, custodial omnibus backing with individual ledger liabilities, atomically reserved withdrawals, and periodic owner fee settlements. Finalized provider balances and chain events must reconcile to the ledger before any owner distribution. No end-user or admin website is needed; webhook routes are infrastructure only.

## 2. Assumptions, tradeoffs and unresolved issues

| ID | Decision / assumption | Rationale and remaining condition |
|---|---|---|
| A01 | Custodial reference model | Internal transfers can settle in the ledger. Customer assets create operational, insolvency and regulatory obligations; third-party custody does not remove the operator's obligations. |
| A02 | Provider-managed signing | Avoid bot-host private keys. Reject self-managed signing for the MVP. A real custody contract and permission model remain unresolved. |
| A03 | Native ETH only | Avoid token-contract, issuer, freezing, decimal and separate gas-asset complexity. ETH value and gas fluctuate; never describe it as stable savings. |
| A04 | Unique addresses | Reduce attribution ambiguity; shared addresses without enforced memo attribution are excluded. Provider ETH address/sweep/gas costs must be verified before choosing this topology live. |
| A05 | Internal transfer = ledger movement | No chain network fee; platform fee is shown before confirmation. It must not be described as an on-chain payment. |
| A06 | External transfer = asynchronous saga | Reserve first, submit outside the DB transaction, reconcile uncertainty using the original provider identifier. A timeout never establishes failure. |
| A07 | Batch fee settlement | Keep revenue separate from network costs and customer liabilities. No claim of immediate on-chain forwarding of every service fee. |
| A08 | Telegram private conversations only | Numeric sender ID authenticates the Telegram account; it is not proof of legal identity or a second factor. Telegram compromise remains a major live risk. |
| A09 | Jurisdiction and audience unknown | Do not infer residence, citizenship, incorporation or sanctions exposure from timezone, language, machine location or IP. Qualified counsel must define operator/customer jurisdictions and exclusions. |
| A10 | Sandbox confirmation policy | Simulated thresholds exercise pending/finalized/reorg paths. They do not prove Ethereum finality. A real adapter must map provider statuses to canonical-chain finality, chain ID and event identity. |
| A11 | No exchange activity | Buy/sell/swap, staking, lending, fiat, multi-asset and multi-chain are out of scope. This does not establish regulatory exemption. |
| A12 | Database is one trust boundary | Immutable journals protect application history; DBA/root can still alter storage. Live controls need restricted DB roles, externally retained audit evidence and independent reconciliation. |

## 3. Expert responsibilities and challenges

| Perspective | Independent challenge | Concrete decision / required evidence |
|---|---|---|
| Product Owner | A polished bot can conceal fake custody or launch blockers. | Mark sandbox on onboarding, balances and receiving; publish explicit MVP/deferred scope and acceptance evidence. |
| Solution Architect | A queue retry can execute money movement twice. | Durable inbox/outbox, immutable intent, provider reference, recoverable states and separate notification jobs. |
| Senior PHP/Laravel Engineer | Casting exact values or bypassing services can corrupt money. | BCMath strings throughout, guarded domain mutations, validated configuration, no float conversions. |
| MySQL Financial Ledger Engineer | Read-then-write balance checks overspend under concurrency. | One DB transaction, deterministic account locks, exact balanced entries, unique posting keys, bounded retry. |
| Blockchain/Custody Integration Engineer | A successful HTTP response or confirmation count is not finality. | Fake adapter only; real mapping, signature verification, retrieval by original identifier and reorg evidence required. |
| Telegram UX Designer | Stale buttons and usernames can redirect payments. | Persist sender-bound state and expiring callbacks; stable wallet code and explicit recipient/fee preview. |
| Application Security Engineer | A forwarded admin callback or forged webhook can spend funds. | Private sender/chat checks, secret verification, per-action RBAC, replay guards, rate limits and durable audit. |
| Compliance/Privacy Specialist | Telegram-only identity collection may not meet legal or privacy obligations. | No live onboarding until counsel approves KYC, sanctions, Travel Rule and processing model; do not collect identity documents in this demo. |
| QA Automation Engineer | Sequential tests cannot establish withdrawal concurrency safety. | Twenty independent MySQL connections/processes, failure injection, invariant assertions, explicit run/pending evidence. |
| DevOps/SRE Engineer | A restored DB can rebroadcast payments already sent. | Restore in quarantine, disable outbound actions, replay/reconcile authoritative events before resuming. |
| Financial Reconciliation Specialist | Balanced journals can still be undercollateralized. | Compare finalized custody assets with all customer liabilities and in-flight items; freeze on unexplained difference. |
| Operations/Support Lead | Support agents can accidentally become finance administrators. | Least-privilege tickets and redacted transaction views; no balance edits or secrets requested through support. |

## 4. Critical risk register and mitigation

Severity is assessed before mitigation. Open live gates are not waived by sandbox tests.

| Risk | Severity | Mitigation / safe failure | Accountable viewpoint |
|---|---|---|---|
| Unlicensed/unsupported operation or sanctions exposure | Critical | Legal scope, customer eligibility and provider approval before live enablement | Compliance |
| Custody credentials/insider compromise | Critical | Separate read/spend credentials, provider policies, capped exposure, dual approvals, key rotation and emergency disable | Security/Custody |
| Telegram account takeover or destination substitution | Critical | Preview immutable destination; limits, cooling periods, risk holds; independent live authentication assessment | Security/UX |
| Concurrent reserve or duplicate confirmation | Critical | Locked accounts, unique intent/posting keys, replay tests and actual MySQL process test | Ledger/QA |
| Unknown withdrawal outcome | Critical | Keep funds reserved; lookup original identifier; no new payment until positively resolved | Custody/SRE |
| Fraudulent or reorganized deposit | Critical | Verified event source, network/asset/address binding, event uniqueness, finality policy; reversal and freeze if spent | Custody/Reconciliation |
| Owner payout invades customer backing | Critical | Revenue-only cap, fresh reconciliation, approved destination, separate approval and payout cost budget | Finance |
| Journal/storage tampering | Critical | Append-only application path, DB protections, signed/exported audit, independent restore/replay exercise | Ledger/SRE |
| Fee rise or delayed execution | High | Refuse submission above the authorized cap; uncertain acceptance retains reserve. After finality, charge customer at most the cap, expense actual platform overrun and pause withdrawals; refund unused reserve only at final resolution | Product/Custody |
| Unsupported network deposit or wrong withdrawal address | High | Single network everywhere; reject unsupported asset/network; shape validation cannot prove network ownership | UX/Custody |
| Dependency or webhook exploit | High | TLS, independent webhook secrets, raw-body signature verification, replay defense, dependency audit and independent penetration test | Security |
| Sensitive data exposure through chat/logs/backups | High | Minimize stored fields, redact credentials, restrictive staff views, retention/access/deletion policy and encrypted backups | Privacy/SRE |
| Notification outage after a successful posting | Medium | Durable notification retry with stable receipt; never repeat the financial operation to resend a message | Engineering |

## 5. MVP and deferred scope

Sandbox MVP acceptance includes onboarding and consent, private menus, simulated unique receive address, exact available/pending/held balances, stable-code internal transfers, preview/confirm withdrawal, paginated history/details, fees/limits/terms/privacy, support tickets, RBAC administration, holds/pauses, fake-provider event handling, reconciliation, fee settlement controls, and automated failure tests. The fake-only batch fee settlement implements a distinct authorized proposer and Owner approver, a minimum 24-hour post-approval delay, fresh clean reconciliation at approval, revenue/cost/backing checks, immutable configured destination, and original-ID recovery. Destination changes remain blocked pending reviewed change governance. None of these controls grants real-money approval. See [acceptance.md](acceptance.md) for evidence, not assumptions of completion.

Deferred beyond this reference build: real custody integration; real chain monitoring and finality; production identity/sanctions/Travel Rule tooling; jurisdiction-specific legal terms; externally approved independent security/custody review; high-availability infrastructure and encrypted off-host/PITR production restore; organizational custody ceremonies and signed go-live approval. A real MySQL dump/restore and authenticated simulated-chain event replay drill passed; this does not validate recovery from a real provider. Optional username lookup is convenience-only and must resolve a current registered immutable account before preview. Multi-chain, ERC-20 tokens, trading, fiat, staking and web interfaces are excluded.

## 6. Provider due diligence: research, not selection

Official documentation was reviewed on 2026-10-19. Neither candidate is selected, contracted, tested here or approved for the intended operator/customer population. API availability is not proof of regulated custody, entitlement, insurance, recovery, segregation, suitability, or legal permission. There is no real provider adapter in this build.

**Fireblocks:** its [idempotency documentation](https://developers.fireblocks.com/reference/api-idempotency) describes a 24-hour request response cache and recommends transaction `externalTxId`. Its [transaction guide](https://developers.fireblocks.com/reference/create-transactions) documents native ETH transfers, one-time destinations and external identifiers. These support evaluating the abstraction, but the API product/key-control topology and contracting entity still need custody review. Never rotate the transaction's external identifier merely because a request cache expires. The [webhook overview](https://developers.fireblocks.com/reference/webhooks-overview) is a discovery source; the exact chosen webhook version's verification and rotation protocol must be pinned and independently tested before implementation.

**BitGo:** its [wallet API](https://developers.bitgo.com/reference/v2walletrequestaccess) distinguishes custodial from self-custodial types and restricts custodial creation to eligible trust customers. The [send reference](https://developers.bitgo.com/reference/expresswalletsendcoins) documents `sequenceId` for duplicate-send protection; the precise custodial product's transaction workflow still requires validation rather than assuming the Express signing flow applies. Its [webhook guide](https://developers.bitgo.com/docs/webhooks-overview) documents HMAC signatures and retries. Test raw-body authentication, replay, delayed delivery and polling reconciliation against the contracted product.

Required procurement evidence: authorized jurisdictions and customer types; contracting/custody entity and license; ETH/sepolia availability; customer asset title and insolvency segregation; policy/quorum and key recovery; immutable external ID retention/lookup; address allocation/sweeping costs; finality/reorg semantics; webhook authenticity/replay and version support; exact fee charging and failure costs; withdrawal limits/SLA; reconciliation exports; audited controls and penetration reports; incident notification; data residency/subprocessors/DPA; sanctions/Travel Rule integration; termination and asset recovery. Obtain written legal, custody and security approvals before coding/enabling a live adapter.

## 7. Compliance and privacy blockers

FATF's [virtual asset guidance](https://www.fatf-gafi.org/en/topics/virtual-assets.html) calls for licensing/supervision and preventive measures including customer due diligence, recordkeeping and transfer information. It is an international standard; applicable local law and thresholds require qualified jurisdiction-specific advice. No jurisdictional approval is asserted here.

The Telegram-only constraint may conflict with lawful identity verification, secure document handling, consent, retention, recovery, required disclosures, sanctions screening and Travel Rule exchanges. These require a documented legal and privacy assessment plus technical feasibility proof. If a mandatory process requires an external identity interface, the product constraint and live launch are incompatible until the user changes scope or counsel approves a lawful Telegram-only design. A Telegram username, numeric ID, self-declared age or risk acceptance is not KYC. Never ask users for private keys or seed phrases.

Before live use define data controller/processors, legal bases, international transfers, retention schedule, access rights/erasure exceptions, breach response and a privacy notice. Telegram chat records and copies on user devices cannot be treated as centrally erasable. Store only operationally necessary identifiers and support text in the sandbox; no identity documents or biometrics.

## 8. Phase review disposition

| Phases | Decision / risk | Artifact | Still required |
|---|---|---|---|
| 1–2 | Minimal custodial Telegram sandbox; identity and stale controls are critical | This review; UX specification; Telegram: 16 tests/111 assertions | Connected-Telegram human UX walkthrough and jurisdiction-specific legal review |
| 3 | Double-entry exact units with reserves; MySQL migration/immutability/mixed-asset checks and actual 20-process concurrency passed | Ledger: 20 tests/99 assertions, migrations, concurrency: 77 assertions | Forced-deadlock/process-kill injection, independent financial review |
| 4–5 | Fake integration; capped network charge; reconciled batch settlement with two-person approval and delay | Custody: 5 tests; FeeSettlement: 5 tests/26 assertions | Real provider selection/approved adapter and contract suite; canonical-chain finality evidence |
| 6–7 | Private RBAC, pauses, audit and webhook forgery tests; no real customer eligibility | Telegram/admin services, risk register and clean published dependency audit | Independent penetration test, security/custody/legal approval; timed stale-alert validation |
| 8–9 | Full SQLite: 51 tests/274 assertions and MySQL: 53 tests/395 assertions passed; actual concurrency and isolated dump/restore included | Source, JUnit report and [acceptance.md](acceptance.md) | Public TLS/connected-Telegram deployment, explicit crash/deadlock/load tests, real-provider/off-host/PITR restore |
| 10 | Production decision remains NO-GO; sandbox engineering gates checked only to tested scope | [production-checklist.md](production-checklist.md), operations runbook | Named accountable human sign-offs, operational exercises and all production gates |
