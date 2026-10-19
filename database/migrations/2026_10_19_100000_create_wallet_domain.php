<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $units = static function (Blueprint $t, string $name) {
            if (DB::getDriverName() === 'sqlite') {
                $t->text($name);
            } else {
                $t->decimal($name, 65, 0);
            }
        };
        Schema::create('wallet_users', function (Blueprint $t) {
            $t->id();
            $t->string('telegram_id', 20)->unique();
            $t->string('wallet_code', 24)->unique();
            $t->string('username')->nullable()->index();
            $t->timestamp('accepted_at')->nullable();
            $t->string('terms_version')->nullable();
            $t->boolean('frozen')->default(false);
            $t->timestamps();
        });
        Schema::create('ledger_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->foreignId('wallet_user_id')->nullable()->constrained('wallet_users')->restrictOnDelete();
            $t->string('kind');
            $t->string('asset')->default('ETH');
            $t->timestamp('created_at');
        });
        Schema::create('journals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('reference')->unique();
            $t->string('kind');
            $t->json('metadata');
            $t->timestamp('created_at');
        });
        Schema::create('postings', function (Blueprint $t) use ($units) {
            $t->id();
            $t->foreignUuid('journal_id')->constrained('journals')->restrictOnDelete();
            $t->foreignId('account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $units($t, 'amount');
            $t->timestamp('created_at');
            $t->unique(['journal_id', 'account_id']);
            $t->index(['account_id', 'id']);
        });
        Schema::create('quotes', function (Blueprint $t) use ($units) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained('wallet_users')->restrictOnDelete();
            $t->string('kind');
            $t->foreignId('recipient_user_id')->nullable()->constrained('wallet_users')->restrictOnDelete();
            $t->string('destination')->nullable();
            foreach (['amount', 'service_fee', 'network_fee', 'total'] as $field) {
                $units($t, $field);
            }
            $t->string('policy_version');
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('transfers', function (Blueprint $t) use ($units) {
            $t->uuid('id')->primary();
            $t->foreignUuid('quote_id')->unique()->constrained('quotes')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('wallet_users')->restrictOnDelete();
            $t->foreignId('recipient_user_id')->nullable()->constrained('wallet_users')->restrictOnDelete();
            $t->string('kind');
            $t->string('status')->index();
            $t->string('destination')->nullable();
            foreach (['amount', 'service_fee', 'network_fee', 'total'] as $field) {
                $units($t, $field);
            }
            if (DB::getDriverName() === 'sqlite') {
                $t->text('actual_network_fee')->default('0');
            } else {
                $t->decimal('actual_network_fee', 65, 0)->default('0');
            } $t->string('provider_reference')->nullable()->unique();
            $t->string('txid')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });
        Schema::create('deposit_addresses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('wallet_user_id')->unique()->constrained('wallet_users')->restrictOnDelete();
            $t->string('address')->unique();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('deposits', function (Blueprint $t) use ($units) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('wallet_users')->restrictOnDelete();
            $t->string('network');
            $t->string('asset');
            $t->string('txid');
            $t->unsignedInteger('event_index');
            $t->string('address');
            $units($t, 'amount');
            $t->unsignedInteger('confirmations');
            $t->string('status');
            $t->string('block_hash')->nullable();
            $t->timestamps();
            $t->unique(['network', 'txid', 'event_index']);
        });
        Schema::create('outbox', function (Blueprint $t) {
            $t->id();
            $t->string('type');
            $t->string('dedupe_key')->nullable()->unique();
            $t->json('payload');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('available_at');
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('created_at');
            $t->index(['delivered_at', 'available_at']);
        });
        Schema::create('controls', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('value');
            $t->timestamp('updated_at');
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->string('actor');
            $t->string('action');
            $t->string('subject');
            $t->json('metadata');
            $t->timestamp('created_at');
        });
        Schema::create('reconciliations', function (Blueprint $t) use ($units) {
            $t->id();
            foreach (['custody_balance', 'ledger_custody', 'liabilities', 'earned_fees'] as $f) {
                $units($t, $f);
            }
            $t->boolean('balanced');
            $t->timestamp('created_at');
        });
        foreach (['transfers_paused', 'withdrawals_paused'] as $key) {
            DB::table('controls')->insert(['key' => $key, 'value' => 'false', 'updated_at' => now()]);
        }
        foreach (['custody', 'revenue', 'network_expense', 'network_recovery'] as $kind) {
            DB::table('ledger_accounts')->insert(['code' => 'system:'.$kind, 'kind' => $kind, 'created_at' => now()]);
        }
        // Append-only at the database boundary, including accidental raw SQL writes.
        foreach (['journals', 'postings', 'audit_logs'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = $table.'_immutable_'.strtolower($operation);
                if (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER $name BEFORE $operation ON $table BEGIN SELECT RAISE(ABORT, 'append-only table'); END");
                } elseif (DB::getDriverName() === 'mysql') {
                    DB::unprepared("CREATE TRIGGER $name BEFORE $operation ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only table'");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['reconciliations', 'audit_logs', 'controls', 'outbox', 'deposits', 'deposit_addresses', 'transfers', 'quotes', 'postings', 'journals', 'ledger_accounts', 'wallet_users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
