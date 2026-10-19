<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fake_custody_balances', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('amount', 66)->default('0');
        });
        Schema::create('fake_custody_payments', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->primary();
            $table->string('destination');
            $table->string('amount', 66);
            $table->string('network_fee', 66);
            $table->string('status');
            $table->string('txid')->nullable();
            $table->boolean('balance_applied')->default(false);
            $table->timestamp('created_at');
        });
        Schema::create('fake_custody_deposits', function (Blueprint $table) {
            $table->string('event_key')->primary();
            $table->string('amount', 66);
            $table->boolean('balance_applied')->default(false);
            $table->boolean('removed')->default(false);
            $table->string('block_hash')->nullable();
        });
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->json('payload');
            $table->string('payload_hash', 64);
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['provider_events', 'fake_custody_deposits', 'fake_custody_payments', 'fake_custody_balances'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
