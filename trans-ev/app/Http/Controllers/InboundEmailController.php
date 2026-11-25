<?php

namespace App\Http\Controllers;

use App\Services\Support\InboundEmailService;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Inbound email webhook: the mail provider posts replies to ticket emails here. The secret token in the URL is set in
 * Settings → Integrations → Support email. Processed or ignored emails both return 200 so the provider stops retrying.
 */
class InboundEmailController extends Controller
{
    public function __invoke(Request $request, string $token, InboundEmailService $inbound): JsonResponse
    {
        $expected = (string) Settings::get('support.inbound_token');
        if (! InboundEmailService::enabled() || ! hash_equals($expected, $token)) {
            return response()->json(['status' => 'forbidden'], 403);
        }
        $payload = $request->isJson() ? (array) $request->json()->all() : $request->all();
        $files = collect($request->allFiles())->flatten()->filter(fn ($f) => $f instanceof UploadedFile)->values()->all();

        return response()->json($inbound->handle($payload, $files));
    }
}
