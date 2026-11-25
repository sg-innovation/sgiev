<?php

namespace App\Services\Messaging\Providers;

use App\Support\Settings;
use Illuminate\Support\Facades\Http;

/**
 * SGI Connects WhatsApp API (Meta WABA). Every call carries LicenseNumber + APIKey.
 *  - accountvalidity.php        → check credentials (used by "Test connection")
 *  - conversationvalidity.php   → is a 24-hour conversation window open for a contact?
 *  - sendtemplate.php           → approved template by name or ID, Param = comma-separated values
 *  - sendtextmessage.php        → free text (only delivered inside an open conversation window)
 * Contacts are country-code numbers without "+" (e.g. 9198xxxxxxxx). Success = {"status":"success","wam_id":…}.
 */
class SgiConnects
{
    private static function call(string $endpoint, array $params): array
    {
        $res = Http::timeout(15)->acceptJson()->asJson()->post(rtrim((string) Settings::get('whatsapp.sgi_base_url'), '/').'/'.$endpoint, [
            'LicenseNumber' => (string) Settings::get('whatsapp.sgi_license'),
            'APIKey' => (string) Settings::get('whatsapp.sgi_api_key'),
        ] + $params);
        $json = (array) ($res->json() ?? []);
        $ok = $res->successful() && strtolower((string) ($json['status'] ?? '')) === 'success';

        return ['ok' => $ok, 'json' => $json, 'error' => $ok ? null : mb_substr((string) ($json['message'] ?? $json['error'] ?? 'HTTP '.$res->status()), 0, 250)];
    }

    /** @return array{ok: bool, id: ?string, error: ?string} */
    public static function sendTemplate(string $msisdn, string $template, array $params): array
    {
        // Param is comma-separated, so commas inside a value would split it: replace them.
        $param = implode(',', array_map(fn ($p) => trim(str_replace([',', "\n", "\r"], [' ', ' ', ''], mb_substr((string) $p, 0, 200))), $params));
        $r = self::call('sendtemplate.php', array_filter(['Contact' => $msisdn, 'Template' => $template, 'Param' => $param], fn ($v) => $v !== ''));

        return ['ok' => $r['ok'], 'id' => $r['json']['wam_id'] ?? null, 'error' => $r['error']];
    }

    /** @return array{ok: bool, id: ?string, error: ?string} */
    public static function sendText(string $msisdn, string $text): array
    {
        $r = self::call('sendtextmessage.php', ['Contact' => $msisdn, 'Message' => mb_substr($text, 0, 4000)]);

        return ['ok' => $r['ok'], 'id' => $r['json']['wam_id'] ?? null, 'error' => $r['error']];
    }

    public static function conversationOpen(string $msisdn): bool
    {
        $r = self::call('conversationvalidity.php', ['Contact' => $msisdn]);

        return $r['ok'] && ($r['json']['exists'] ?? false) && strtolower((string) ($r['json']['conversation']['status'] ?? 'open')) === 'open';
    }

    /** @return array{ok: bool, message: string} */
    public static function checkAccount(): array
    {
        $r = self::call('accountvalidity.php', []);

        return ['ok' => $r['ok'], 'message' => $r['ok']
            ? 'Account is valid'.(isset($r['json']['data']['phone_number_id']) ? ' (phone number ID '.$r['json']['data']['phone_number_id'].')' : '')
            : ($r['error'] ?? 'Invalid credentials')];
    }
}
