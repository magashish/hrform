<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\SignatureTransaction;
use App\Services\Signatures\SignatureProviderManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Provider webhooks. 404 while no provider is configured. Authenticity is
 * verified before the body is parsed, and duplicate event IDs are ignored.
 */
class EsignWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, SignatureProviderManager $manager): Response
    {
        $adapter = $manager->provider();
        abort_unless($adapter->isAvailable() && hash_equals($adapter->name(), $provider), 404);
        abort_unless($adapter->verifyWebhook($request), 401);

        $event = $adapter->parseWebhook($request);
        abort_if($event['event_id'] === '' || $event['reference'] === '', 422);

        $inserted = DB::table('signature_webhook_events')->insertOrIgnore([
            'provider' => $adapter->name(),
            'event_id' => mb_substr($event['event_id'], 0, 200),
            'payload_sha256' => hash('sha256', $request->getContent()),
            'received_at' => now(),
        ]);
        if ($inserted === 0) {
            return response('', 200); // already processed (idempotent)
        }

        $transaction = SignatureTransaction::where('provider', $adapter->name())
            ->where('provider_reference', $event['reference'])->first();

        if ($transaction) {
            $transaction->forceFill([
                'status' => mb_substr($event['status'], 0, 40),
                'signed_at' => $event['signed_at'] ?? $transaction->signed_at,
                'document_sha256' => $event['document_sha256'] ?? $transaction->document_sha256,
                'last_webhook_hash' => hash('sha256', $request->getContent()),
            ])->save();
        }

        return response('', 200);
    }
}
