<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_actors', function (Blueprint $table) {
            $table->string('telegram_id', 20)->primary();
        });
        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->id();
            $table->string('update_id', 20)->unique();
            $table->string('telegram_id', 20)->index();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamp('created_at');
        });
        Schema::create('telegram_conversations', function (Blueprint $table) {
            $table->string('telegram_id', 20)->primary();
            $table->string('state', 40);
            $table->json('context');
            $table->timestamp('expires_at');
        });
        Schema::create('telegram_callbacks', function (Blueprint $table) {
            $table->string('token', 64)->primary();
            $table->string('telegram_id', 20)->index();
            $table->string('action', 40);
            $table->json('context');
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
        });
        Schema::create('telegram_quote_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('inbox_id')->primary();
            $table->string('telegram_id', 20)->index();
            $table->string('status', 20)->default('queued');
        });
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_id', 20)->index();
            $table->string('status', 20)->default('open');
            $table->timestamps();
        });
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->restrictOnDelete();
            $table->string('actor', 20);
            $table->text('body');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('telegram_quote_requests');
        Schema::dropIfExists('telegram_callbacks');
        Schema::dropIfExists('telegram_conversations');
        Schema::dropIfExists('telegram_updates');
        Schema::dropIfExists('telegram_actors');
    }
};
