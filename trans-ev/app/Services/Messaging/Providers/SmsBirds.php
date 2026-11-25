<?php

namespace App\Services\Messaging\Providers;

use App\Support\Settings;
use Illuminate\Support\Facades\Http;

/**
 * SMS Birds HTTP API (smsbirds.in → SMS panel → HTTP API). One request per message:
 * username, apikey, apirequest (Text | Unicode), sender (6-letter DLT header), mobile (10 digits), message,
 * route (TRANS | PROMO), TemplateID (DLT template ID) and format=JSON. The message must match the DLT template text.
 * The base URL is a setting because the panel's HTTPS certificate does not match its domain.
 */
class SmsBirds
{
    /** @return array{ok: bool, id: ?string, error: ?string} */
    public static function send(string $msisdn, string $text, ?string $dltTemplateId): array
    {
        $unicode = preg_match('/[^\x00-\x7F]/', $text) === 1;
        $res = Http::timeout(15)->acceptJson()->asForm()->post((string) Settings::get('sms.smsbirds_url'), array_filter([
            'username' => (string) Settings::get('sms.smsbirds_username'),
            'apikey' => (string) Settings::get('sms.smsbirds_api_key'),
            'apirequest' => $unicode ? 'Unicode' : 'Text',
            'sender' => (string) Settings::get('sms.smsbirds_sender'),
            'mobile' => substr($msisdn, -10),
            'message' => $text,
            'route' => (string) (Settings::get('sms.smsbirds_route') ?: 'TRANS'),
            'TemplateID' => $dltTemplateId,
            'format' => 'JSON',
        ], fn ($v) => $v !== null && $v !== ''));

        return self::result($res->status(), $res->body(), (array) ($res->json() ?? []));
    }

    /**
     * The panel's response format is not documented: treat a 2xx without an error flag as accepted and keep
     * whatever id it returns. Anything mentioning error / invalid / failed is a failure.
     *
     * @return array{ok: bool, id: ?string, error: ?string}
     */
    public static function result(int $status, string $body, array $json): array
    {
        $flag = strtolower((string) ($json['status'] ?? $json['Status'] ?? $json['type'] ?? ''));
        $message = $json['message'] ?? $json['Message'] ?? $json['error'] ?? $json['description'] ?? null;
        $failed = $status >= 400
            || in_array($flag, ['error', 'failed', 'failure', 'fail', 'false', '0'], true)
            || ($json === [] && preg_match('/error|invalid|fail|insufficient/i', $body) === 1);
        $id = $json['message_id'] ?? $json['msgid'] ?? $json['MessageId'] ?? $json['data']['message_id'] ?? $json['batch_id'] ?? null;

        return [
            'ok' => ! $failed,
            'id' => $id !== null ? (string) $id : null,
            'error' => $failed ? mb_substr((string) ($message ?? trim(strip_tags($body)) ?: "HTTP {$status}"), 0, 250) : null,
        ];
    }
}
