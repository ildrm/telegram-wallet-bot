<?php

use App\Support\CanonicalJson;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_updates', fn (Blueprint $t) => $t->char('payload_hash', 64)->nullable());
        DB::table('telegram_updates')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::table('telegram_updates')->where('id', $row->id)->update([
                    'payload_hash' => hash('sha256', CanonicalJson::encode(json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR))),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('telegram_updates', fn (Blueprint $t) => $t->dropColumn('payload_hash'));
    }
};
