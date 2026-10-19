# Production go/no-go

Decision: **NO-GO for real customer assets.** The reference executable is a fake-custody sandbox. Checked sandbox items record automated engineering evidence only. Independent production approvals below remain open until the accountable human supplies reviewed evidence and signs; do not infer approval from tests or this document.

## Sandbox release gate

- [ ] Fresh checkout installs from the lockfile and runs documented setup without secret material.
- [x] PHP/Laravel/BCMath runtime and MySQL 8.4/InnoDB migrations exercised by the full automated suites: SQLite: 51 tests/274 assertions and MySQL: 53 tests/395 assertions tests/assertions. [Evidence](acceptance.md#execution-record).
- [x] Fake-only custody and live-enable rejection tested; no real adapter is available. [CustodyTest](../tests/Feature/CustodyTest.php).
- [x] Private sender/chat checks and exactly two infrastructure POST callback routes tested; no website or frontend API is exposed. [TelegramTest](../tests/Feature/TelegramTest.php), [DeliveryTest](../tests/Feature/DeliveryTest.php).
- [ ] All applicable rows in [acceptance.md](acceptance.md) have current evidence; outstanding failures are disclosed.
- [x] Automated sandbox cases for exact money, idempotency, state guards, stale callbacks, roles and notification retries pass; untested failure boundaries remain listed. [Traceability](acceptance.md#trace-from-acceptance-to-executed-tests).
- [x] Twenty simultaneous MySQL processes with distinct connections and a common release barrier pass 77 assertions. [MySqlConcurrencyTest](../tests/Integration/MySqlConcurrencyTest.php).
- [x] Actual isolated MySQL dump/restore, immutable triggers, authenticated simulated-event replay and lookup-only uncertain withdrawal recovery pass 44 assertions. [MySqlRestoreTest](../tests/Integration/MySqlRestoreTest.php).
- [x] Documentation identifies simulated custody/finality/addresses, deferred features and live blockers. [Review](review.md), [acceptance limitations](acceptance.md).
- [x] Composer strict validation, Pint formatting and locked-dependency audit pass; no published advisories or abandoned packages reported. [Evidence](acceptance.md#execution-record).
- [ ] Docker image build/runtime smoke test succeeds; current deployment-image build validation remains pending.

The unchecked full-acceptance and fresh-checkout/deployment gates prevent describing this sandbox as fully deployment-verified. No checked sandbox item closes a production gate below.

## Required independent legal and privacy approval

- [ ] Operator incorporation, management, customer residence, service locations and restricted markets explicitly identified; timezone is not evidence.
- [ ] Qualified counsel signs licensing/registration, AML/KYC, sanctions, transaction monitoring, reporting, recordkeeping, complaints and Travel Rule analysis for each applicable jurisdiction.
- [ ] Telegram-only onboarding, authentication and compliance are demonstrably lawful and adequate. If mandatory identity/Travel Rule handling needs another interface, change product scope or remain NO-GO.
- [ ] Approved terms, risk disclosures, eligibility, fee disclosures, privacy notice, retention schedule, data processing agreements and international-transfer basis exist.
- [ ] Data subject requests and deletion exceptions, incident reporting and regulator contacts are exercised.

## Required independent custody approval

- [ ] A real provider/product/entity is selected after documented due diligence; a developer API alone is insufficient.
- [ ] Executed contract establishes permitted business/customer/geographic use, asset title, segregation, insolvency treatment, recovery, SLA and fee model.
- [ ] Provider-managed signing, quorum/allowlists, limited credentials, withdrawal caps, hot exposure, owner destination governance and emergency revocation are reviewed.
- [ ] A verified real adapter implements signed callbacks, allocation, fees, submit/status/balance retrieval, external-ID lookup, replay, reconciliation and reorg policy.
- [ ] Independent sandbox/provider tests establish unknown-outcome recovery without duplicate broadcast, terminal failure semantics and actual incurred network costs.
- [ ] Finality and unique native ETH event identity are verified against the canonical chain; simulation thresholds are replaced by an approved production policy.
- [ ] Owner distributions can spend only reconciled revenue after customer backing, reserves and all network costs are funded.

## Required independent security approval

- [ ] Independent application/custody penetration test and code review completed; critical/high findings resolved with retest evidence.
- [ ] Telegram authentication risk and additional controls approved for the actual funds at risk; bot-secret validation is not a customer second factor.
- [ ] Production keys generated/held through approved ceremonies and secret manager; least-privilege credentials, rotation and revocation tested.
- [ ] TLS, webhook verification/replay, private sender checks, rate limits, logging redaction and dependency/container scans pass.
- [ ] DB role separation, journal immutability, audit export integrity, network isolation and backup encryption verified.
- [ ] Two distinct approvers, activation delay and expiry govern owner destination changes; same-person approval cannot satisfy quorum.

## Required finance and operations approval

- [ ] Finance approves opening balance/capital, liability definition, revenue/cost accounting, daily independent reconciliation and discrepancy freeze rules.
- [ ] Restore to isolated environment, point-in-time recovery and authoritative provider/chain replay demonstrated; no outbound processing until reconciliation closes.
- [ ] Queue starvation, deadlocks, provider outage, duplicate/reordered callbacks, notification outage and stale reconciliation alerts exercised.
- [ ] Emergency pause/freeze/credential compromise/reorg/insolvency runbooks exercised with named on-call owners and escalation contacts.
- [ ] Support access and customer dispute procedures meet least privilege; staff cannot edit balances, request secrets or disclose unrelated accounts.
- [ ] Monitoring, durable alert delivery, job retries/dead letters, retention and capacity/load tests meet approved RPO/RTO/SLO targets.
- [ ] Owner, Security, Custody, Legal/Compliance, Finance and Operations record dated signed approval of the exact release hash, deployment configuration and initial limits.

No environment flag or admin callback constitutes any of these approvals. A live enablement change requires a separate reviewed implementation and explicit authorized production release.
