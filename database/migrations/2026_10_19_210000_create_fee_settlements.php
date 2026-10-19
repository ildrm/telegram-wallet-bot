<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_settlements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('proposed_by', 20);
            $t->string('approved_by', 20)->nullable();
            $t->string('request_key')->nullable()->unique();
            $t->string('destination');
            foreach (['amount', 'network_fee', 'total', 'actual_network_fee'] as $name) {
                if (DB::getDriverName() === 'sqlite') {
                    $t->text($name)->default('0');
                } else {
                    $t->decimal($name, 65, 0)->default(0);
                }
            }
            $t->string('status')->index();
            $t->timestamp('eligible_at')->nullable();
            $t->string('provider_reference')->nullable()->unique();
            $t->string('txid')->nullable();
            $t->timestamps();
        });
        DB::table('ledger_accounts')->insert(['code' => 'system:fee_payable', 'kind' => 'fee_payable', 'created_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_settlements');
    }
};
