# Sandbox deployment and operations

This release manages **simulated assets only**. Fake addresses resemble Ethereum addresses but are not controlled deposit addresses. Never fund them on any chain. No real custody adapter exists. All customer and administrative operations use private Telegram bot messages; infrastructure commands below are for deployment, monitoring and recovery.

## Prerequisites and setup

- PHP 8.4.1+ (tested 8.5.8; the lockfile includes Symfony 8.1), Composer 2, BCMath, PDO MySQL, mbstring, XML/DOM, OpenSSL, curl, pcntl for concurrency tests.
- MySQL 8.4 InnoDB with strict SQL mode; encrypted storage and backups; UTC database/application clocks.
- Separate sandbox Telegram bot; HTTPS hostname with valid TLS. Never expose PHP-FPM or MySQL publicly.

```sh
composer install --no-interaction
cp .env.example .env
php artisan key:generate
# Fill DB connection and sandbox Telegram credentials locally. Never commit .env.
php artisan migrate --force
php artisan wallet:reconcile
php artisan route:list
php artisan queue:work --sleep=1 --tries=5 --timeout=45
# Separate supervised process:
php artisan schedule:work
```

Only `POST /webhooks/telegram` and `POST /webhooks/custody` are registered. There is no homepage, dashboard, public REST API, or file-serving route. Send only allowed callback paths through the TLS ingress. `.env` initially created by development work uses local SQLite; `.env.example` and Docker use MySQL. SQLite tests verify logic; MySQL tests verify actual InnoDB behavior.

Set `TELEGRAM_ADMIN_ROLES` to a JSON map such as `{"123456":["Finance"],"789012":["Owner"],"345678":["Support"]}` using verified numeric IDs. Use separate humans/accounts for proposal and approval. Do not use this illustrative ID map unchanged. Role changes require controlled configuration rollout; revocation is checked again when an action runs. Leave `OWNER_PAYOUT_ADDRESS` empty until a reviewed sandbox destination is configured. No payout-address change command exists.

For webhook registration, invoke Telegram `setWebhook` from a secure operator environment with `url=https://YOUR-HOST/webhooks/telegram`, a random 32+ byte `secret_token`, and `allowed_updates=["message","callback_query"]`. The same secret belongs in `TELEGRAM_WEBHOOK_SECRET`. Send credentials in request bodies; disable HTTP tracing and never paste tokens into shell command arguments, support messages, or logs. Registration is deliberately not executed by this repository. See the [official Bot API](https://core.telegram.org/bots/api#setwebhook).

## Container deployment

Add independently generated `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `APP_KEY`, and sandbox webhook secrets to the local `.env`; template values are empty. Run:

```sh
docker compose build
docker compose up -d mysql
docker compose run --rm migrate
docker compose up -d app queue scheduler nginx
docker compose exec app php artisan wallet:reconcile
```

The supplied Nginx listener is localhost port 8080. Configure a trusted TLS ingress in front of it. Use immutable image digests in a reviewed deployment. The one-shot `migrate` service receives the sandbox root credential so immutable triggers can be installed even with MySQL binary logging enabled; the app/queue/scheduler services never receive that root credential. The image initialization still grants the sandbox app user schema-wide privileges. Before any live deployment, use a restricted dedicated migrator, remove runtime DDL/trigger privileges, grant journals/postings/audit INSERT+SELECT only, and deny alteration of triggers. Runtime needs SELECT/INSERT/UPDATE/DELETE on queue/cache/conversation tables; domain tables have separate least-privilege grants. This sandbox compose file is not a hardened production custody deployment.

## Durable work and monitoring

`wallet:drain` dispatches at most 100 pending outbox records and rescans unprocessed Telegram/custody inboxes. It runs every minute. Financial effects and responses commit together; enqueue loss is repaired by the scan. Telegram jobs additionally enqueue dependent jobs inside the same database transaction. `wallet:poll` visits reserved/in-flight withdrawals and approved settlements each minute. Only `reserved`/newly approved payments can be submitted; uncertain identifiers are looked up, never resent. `wallet:reconcile` runs every five minutes and pauses transfers on a mismatch. `wallet:status` emits infrastructure JSON.

Supervise workers; queue `retry_after` (90s default) must exceed worker timeout (45s), and request timeouts must be shorter. Outbox notification leases last 120s. Delivery is at least once: a crash after Telegram accepted a message can duplicate the notification, never the journal. Callback acknowledgments may expire during an outage; this does not authorize a payment. Drain failures retain pending records with capped exponential backoff. Provider inboxes exhausted after five attempts retain `last_error` for review; do not silently delete them.

Alert on failed jobs, oldest unprocessed inbox/outbox age >2 minutes, unknown/manual-review withdrawals, reconciliation failures, negative customer available amounts, network charge overruns, pauses, unauthorized admin attempts, and worker/scheduler heartbeat absence. Forward infrastructure logs to a restricted log sink; alert to independently configured operational tooling. No external alert destination is configured or sent by this implementation. `/admin` exposes current pause/review counts and reconciliations in Telegram. Do not log bot tokens, webhook signatures, provider credentials, full request bodies or support content.

Useful operator commands:

```sh
php artisan wallet:status
php artisan queue:failed
php artisan wallet:drain
php artisan wallet:poll
php artisan wallet:reconcile
php artisan queue:restart
```

Review a failed payload before `queue:retry JOB_UUID`. Never create a new withdrawal identifier to repair an unknown original payment. Quotes and confirmation buttons expire; instruct users to obtain a new preview only when the original payment is conclusively failed/refunded. A simulator outage before submission conservatively retains reserved funds if state already reached `submitting`; lookup absence requires manual investigation.

## Backups and restore

Back up MySQL with an isolated account, an access-controlled MySQL option file, and encrypted storage. Example (no password on command line):

```sh
umask 077
mysqldump --defaults-extra-file=/run/secrets/mysql-backup.cnf \
  --single-transaction --quick --routines --triggers --events --hex-blob \
  --set-gtid-purged=OFF telegram_wallet > wallet-snapshot.sql
# Encrypt and upload through the organization's approved backup service; checksum and verify retention.
```

Maintain encrypted binlogs for point-in-time recovery. Baseline sandbox targets: RPO 5 minutes, RTO 60 minutes; these are targets requiring an exercised production plan. Keep APP_KEY separately recoverable; rotate custody and bot secrets after compromise, not blindly during restore. Exclude credentials from snapshots sent for debugging.

Restore into an isolated database while public ingress, queue workers and scheduler are stopped. Never attach restored workers to a live custody account until reconciliation and replay complete. Use a separate migration/admin credential and restore triggers:

```sh
mysql --defaults-extra-file=/run/secrets/mysql-restore.cnf -e 'CREATE DATABASE wallet_restore'
mysql --defaults-extra-file=/run/secrets/mysql-restore.cnf wallet_restore < wallet-snapshot.sql
# Point an isolated deployment at wallet_restore; do not run migrations:fresh against restored data.
php artisan migrate:status
php artisan wallet:status
php artisan wallet:reconcile
```

Check all journal sums, posting counts, stable wallet codes, inbox uniqueness, quote/transfer IDs, provider external IDs, and immutable triggers. Replay the complete provider event range covering the backup boundary; preserve original chain `(network,txid,index)` identities even if notification IDs differ. Query original withdrawal IDs to reconstruct uncertain outcomes. Resume in stages only after a clean independent reconciliation. Never delete journals to fix totals; post reviewed compensating entries. Automated restore-drill evidence is recorded in [acceptance.md](acceptance.md).

## Incidents and emergency controls

1. **Mismatch, reorg, suspicious deposit:** Operations pauses transfers in `/admin`; Compliance freezes affected wallets. Retain authenticated events, chain evidence and audit rows. The deposit service compensates a removed confirmed deposit and can create a negative available balance; it freezes the wallet and pauses transfers. Customer debt never offsets another customer's liability in backing checks.
2. **Unknown withdrawal:** Keep reserve. Query the original idempotency key at the provider, independently compare chain transfer and destination, and escalate to custody operations. Do not automatically refund, cancel or issue a new payment. Manual-review release/accounting tools beyond safe holds require a reviewed correction; this is an explicit live blocker.
3. **Credential compromise:** Pause transfers, block ingress if needed, revoke the affected bot/provider credential, provision replacement in a secret store, update webhook verification configuration, restart workers, verify callback authenticity, and reconcile all activity from the exposure window. A compromised Telegram account can authorize transfers; recovery cannot rely on username or a chat-only identity claim.
4. **Database corruption:** Stop writes and outbound submissions, preserve evidence/binlogs, restore isolated, verify ledger integrity and provider state, apply approved compensations, then independently authorize resumption.
5. **Destination/config change:** Keep owner settlements disabled. Require two independent approvers, an out-of-band destination verification, a reviewed deployment and a fresh delay. This release offers no destination mutation UI. Existing approved proposals whose destination differs enter manual review with platform funds retained.
6. **Provider down/fee change:** Keep original request IDs, allow bounded status lookup retries, and alert on age. An actual fee above authorization is charged to platform equity and pauses withdrawals; customers remain capped at the quoted maximum. A failed on-chain transaction with a gas charge enters manual review, not a full refund pretending zero cost.

Support staff can read/reply to support cases only; they cannot alter balances, approve payouts, unfreeze accounts or impersonate a recipient. Verify ownership by the original numeric Telegram ID. Never request private keys, seed phrases, identity documents or new credentials through support. Do not promise completion of uncertain payments. Escalate account takeover, sanctions/identity cases and legal/data requests to qualified staff before handling live customers.

## Validation and remaining gates

```sh
php artisan test
sh ops/test-mysql.sh
vendor/bin/pint --test
composer validate --strict
composer audit --locked
```

`sh ops/test-mysql.sh` creates a disposable localhost MySQL container with the public test-only credentials in `phpunit.mysql.xml`, runs unit/feature/concurrency/restore tests, then stops it. It fails safely if the named container or port already exists. For an already running isolated instance named `telegram-wallet-mysql-test`, use `vendor/bin/phpunit -c phpunit.mysql.xml` directly. Restore tests need Docker access and create/drop only unique `wallet_drill_*` databases. The TestCase and restore guards reject another target database. These test credentials are never production credentials.

The MySQL suite is destructive only to its explicitly isolated test databases. Never point it at customer databases. Tests must run before a release. TLS/cross-host failover, long-running load, real provider chaos, secret-store rotation and independent recovery-time drills remain release-specific checks. See [production-checklist.md](production-checklist.md) for the mandatory real-assets NO-GO gates.
