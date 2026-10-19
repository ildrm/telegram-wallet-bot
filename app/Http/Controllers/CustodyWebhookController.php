<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCustodyEvent;
use App\Support\CanonicalJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Fake adapter callback contract only: HMAC(timestamp + '.' + exact request bytes). */
class CustodyWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 16384, 413);
        $secret = (string) config('wallet.custody_webhook_secret');
        $timestamp = $request->header('X-Custody-Timestamp', '');
        $signature = $request->header('X-Custody-Signature', '');
        abort_unless($secret !== '' && ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $signature), 403);
        $event = Validator::make($request->json()->all(), [
            'event_id' => ['required', 'string', 'max:100', 'regex:/\A[a-zA-Z0-9_:-]+\z/D'],
            'asset' => ['required', 'string', 'max:20'], 'network' => ['required', 'string', 'max:50'],
            'txid' => ['required', 'regex:/\A0x[0-9a-f]{64}\z/D'],
            'index' => ['required', 'integer', 'min:0', 'max:1000000'],
            'address' => ['required', 'regex:/\A0x[0-9a-f]{40}\z/D'],
            'amount' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,35}\z/D'],
            'confirmations' => ['required', 'integer', 'min:0', 'max:100000000'],
            'removed' => ['required', 'boolean'], 'block_hash' => ['sometimes', 'regex:/\A0x[0-9a-f]{64}\z/D'],
        ])->validate();
        $hash = hash('sha256', CanonicalJson::encode($event));
        DB::transaction(function () use ($event, $hash) {
            DB::table('provider_events')->insertOrIgnore([
                'event_id' => $event['event_id'], 'payload' => json_encode($event, JSON_THROW_ON_ERROR),
                'payload_hash' => $hash, 'created_at' => now(),
            ]);
            $row = DB::table('provider_events')->where('event_id', $event['event_id'])->lockForUpdate()->first();
            abort_unless(hash_equals($row->payload_hash, $hash), 409, 'Event identifier has conflicting content.');
            if (! $row->processed_at) {
                // Same database connection as inbox insert: a committed event always has a durable job.
                ProcessCustodyEvent::dispatch($row->id)->onConnection('database');
            }
        }, 3);

        return response()->json(['accepted' => true], 202);
    }
}
