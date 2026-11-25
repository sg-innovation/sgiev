<?php

namespace App\Support;

/**
 * The API reference shown in the developer portal (and exported as OpenAPI 3). One place describes every endpoint:
 * method, path, whether a token is needed, parameters with examples, a request body example and a response example.
 * The portal's console sends real requests, so developers see actual responses next to these examples.
 */
final class ApiDocs
{
    /** @return list<array{name: string, description: string, endpoints: list<array>}> */
    public static function groups(): array
    {
        $p = fn (string $name, string $in, string $type, bool $required, mixed $example, string $desc) => compact('name', 'in', 'type', 'required', 'example') + ['description' => $desc];
        $slug = $p('slug', 'path', 'string', true, 'trans-ev-bailey-road-patna', 'Station slug from the station list');
        $code = $p('code', 'path', 'string', true, 'TEV-261125-ABCDE', 'Booking code');
        $bookingExample = ['code' => 'TEV-261125-ABCDE', 'status' => 'confirmed', 'statusLabel' => 'Confirmed', 'station' => ['name' => 'Trans EV – Bailey Road', 'city' => 'Patna'],
            'charger' => ['code' => 'PAT-BLY-DC01', 'type' => 'DC', 'powerKw' => 60], 'start' => '2026-11-25T12:00:00+05:30', 'end' => '2026-11-25T13:00:00+05:30',
            'amounts' => ['energy' => 486.0, 'sessionFee' => 50.0, 'discount' => 0, 'taxable' => 536.0, 'gst' => 96.48, 'total' => 632.48], 'canPay' => false, 'canCancel' => true, '…' => 'more fields: payment, refunds, policy, final bill, live session, review'];

        return [
            [
                'name' => 'Getting started',
                'description' => "Base URL: **{base}/api/v1**. Send `Accept: application/json`. After signing in, send `Authorization: Bearer <token>` on every call. Times are ISO 8601 (IST shown with +05:30), amounts are rupees. Errors: 401 (no/expired token), 403 (not allowed), 404, 422 (validation: `{message, errors: {field: [..]}}`), 429 (too many requests: wait and retry).",
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/config', 'title' => 'App configuration', 'auth' => false, 'description' => 'Bookable dates, hold minutes, connector types, EV models, review tags, support contacts and legal links. Call at app start.',
                        'params' => [], 'response' => ['app' => ['name' => 'Trans EV', 'api_version' => 'v1', 'timezone' => 'Asia/Kolkata', 'currency' => 'INR'], 'booking' => ['bookable_dates' => ['2026-11-25', '2026-11-26'], 'hold_minutes' => 10], 'connector_types' => [['value' => 'ccs2', 'label' => 'CCS2']]]],
                ],
            ],
            [
                'name' => 'Sign in',
                'description' => 'Mobile OTP is the main sign-in: ask for a code, then verify it. New numbers also send name and email (creates the account). If the account has two-step login on, verify returns `two_factor_required` and a `challenge_token`; send the authenticator code to /auth/two-factor. In test environments the OTP response includes `debug_code`; a fixed test number can also be set by Trans EV.',
                'endpoints' => [
                    ['method' => 'POST', 'path' => '/auth/otp', 'title' => 'Send login code', 'auth' => false, 'description' => 'Sends a 6-digit code by SMS. Resend allowed after `resend_in` seconds.',
                        'params' => [], 'body' => ['phone' => '9876543210'], 'response' => ['message' => 'Code sent by SMS.', 'phone' => '9876543210', 'is_registered' => true, 'expires_in' => 300, 'resend_in' => 30]],
                    ['method' => 'POST', 'path' => '/auth/otp/verify', 'title' => 'Verify code (log in / sign up)', 'auth' => false, 'description' => 'Returns a token. For a new number include `name` and `email`. 201 when the account was created.',
                        'params' => [], 'body' => ['phone' => '9876543210', 'code' => '123456', 'device_name' => 'Pixel 8', 'name' => 'Asha Kumar', 'email' => 'asha@example.com'],
                        'response' => ['token' => '12|Xy…', 'token_type' => 'Bearer', 'expires_at' => '2027-02-23T10:00:00+00:00', 'is_new' => false, 'user' => ['id' => 42, 'name' => 'Asha Kumar', 'phone' => '9876543210', 'two_factor_enabled' => false]]],
                    ['method' => 'POST', 'path' => '/auth/login', 'title' => 'Log in with email and password', 'auth' => false, 'description' => 'Alternative to OTP.',
                        'params' => [], 'body' => ['email' => 'asha@example.com', 'password' => 'secret', 'device_name' => 'iPhone 15'], 'response' => ['token' => '13|Ab…', 'token_type' => 'Bearer']],
                    ['method' => 'POST', 'path' => '/auth/two-factor', 'title' => 'Two-step login code', 'auth' => false, 'description' => 'Finish sign-in for accounts with an authenticator app (or a recovery code). The challenge is valid for 5 minutes.',
                        'params' => [], 'body' => ['challenge_token' => 'eyJpdiI6…', 'code' => '492817'], 'response' => ['token' => '14|Cd…', 'token_type' => 'Bearer']],
                    ['method' => 'POST', 'path' => '/auth/logout', 'title' => 'Log out', 'auth' => true, 'description' => 'Revokes the token used for this call.', 'params' => [], 'response' => ['message' => 'Logged out.']],
                ],
            ],
            [
                'name' => 'Account',
                'description' => 'Profile, photo, notification choices, push devices and saved vehicles.',
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/me', 'title' => 'Me', 'auth' => true, 'params' => [], 'response' => ['data' => ['id' => 42, 'name' => 'Asha Kumar', 'email' => 'asha@example.com', 'phone' => '9876543210', 'city' => 'Pune', 'avatar_url' => null, 'email_verified' => true, 'phone_verified' => true, 'two_factor_enabled' => false, 'notifications' => ['sms' => true, 'whatsapp' => false, 'push' => true]]]],
                    ['method' => 'PATCH', 'path' => '/me', 'title' => 'Update profile', 'auth' => true, 'params' => [], 'body' => ['name' => 'Asha K', 'city' => 'Pune'], 'response' => ['data' => ['name' => 'Asha K', 'city' => 'Pune']]],
                    ['method' => 'POST', 'path' => '/me/avatar', 'title' => 'Upload profile photo', 'auth' => true, 'description' => 'multipart/form-data with field `photo` (JPG/PNG/WebP, max 4 MB). Cropped to a square.', 'multipart' => true, 'params' => [], 'response' => ['data' => ['avatar_url' => 'https://…/storage/avatars/42-abc.jpg']]],
                    ['method' => 'GET', 'path' => '/me/notifications', 'title' => 'Notification choices', 'auth' => true, 'params' => [], 'response' => ['data' => ['sms' => true, 'whatsapp' => false, 'push' => true]]],
                    ['method' => 'PUT', 'path' => '/me/notifications', 'title' => 'Change notification choices', 'auth' => true, 'params' => [], 'body' => ['sms' => true, 'whatsapp' => true, 'push' => true], 'response' => ['data' => ['sms' => true, 'whatsapp' => true, 'push' => true]]],
                    ['method' => 'POST', 'path' => '/me/devices', 'title' => 'Register push token', 'auth' => true, 'description' => 'Firebase Cloud Messaging token of this phone. Call after login and when the token refreshes.', 'params' => [], 'body' => ['token' => 'fcm-token…', 'platform' => 'android'], 'response' => ['message' => 'Device registered.']],
                    ['method' => 'DELETE', 'path' => '/me/devices', 'title' => 'Remove push token', 'auth' => true, 'params' => [], 'body' => ['token' => 'fcm-token…'], 'response' => ['message' => 'Device removed.']],
                    ['method' => 'GET', 'path' => '/vehicles', 'title' => 'My vehicles', 'auth' => true, 'params' => [], 'response' => ['data' => [['id' => 7, 'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'MH12AB1234', 'battery_kwh' => 40.5, 'connector' => 'ccs2', 'is_default' => true]]]],
                    ['method' => 'POST', 'path' => '/vehicles', 'title' => 'Add vehicle', 'auth' => true, 'params' => [], 'body' => ['make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'MH12AB1234', 'battery_kwh' => 40.5, 'connector' => 'ccs2', 'is_default' => true], 'response' => ['data' => ['id' => 7]]],
                    ['method' => 'PATCH', 'path' => '/vehicles/{id}', 'title' => 'Edit vehicle', 'auth' => true, 'params' => [$p('id', 'path', 'integer', true, 7, 'Vehicle id')], 'body' => ['make' => 'Tata', 'model' => 'Nexon EV Max', 'registration_no' => 'MH12AB1234', 'battery_kwh' => 40.5, 'connector' => 'ccs2'], 'response' => ['data' => ['id' => 7]]],
                    ['method' => 'DELETE', 'path' => '/vehicles/{id}', 'title' => 'Remove vehicle', 'auth' => true, 'params' => [$p('id', 'path', 'integer', true, 7, 'Vehicle id')], 'response' => ['message' => 'Vehicle removed.']],
                ],
            ],
            [
                'name' => 'Stations',
                'description' => 'Search, details, reviews, free slots and the price estimate. Slots and estimates use the same rules as the website.',
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/stations', 'title' => 'Search stations', 'auth' => false, 'description' => 'Nearest first when lat/lng are sent.',
                        'params' => [$p('lat', 'query', 'number', false, 18.5204, 'Latitude'), $p('lng', 'query', 'number', false, 73.8567, 'Longitude'), $p('radius', 'query', 'number', false, 25, 'km'),
                            $p('q', 'query', 'string', false, 'Pune', 'Name, city, address or PIN'), $p('type', 'query', 'string', false, 'DC', 'AC or DC'), $p('connector', 'query', 'string', false, 'ccs2', 'Connector type'),
                            $p('min_kw', 'query', 'number', false, 30, 'Minimum power'), $p('open_now', 'query', 'boolean', false, 1, 'Open now only'), $p('available', 'query', 'boolean', false, 1, 'Bookable only')],
                        'response' => ['data' => [['slug' => 'trans-ev-bailey-road-patna', 'name' => 'Trans EV – Bailey Road', 'city' => 'Patna', 'distanceKm' => 2.4, 'isOpen' => true, 'bookable' => true, 'rating' => 4.6, 'fromRate' => 18]]]],
                    ['method' => 'GET', 'path' => '/stations/{slug}', 'title' => 'Station details', 'auth' => false, 'description' => 'Chargers and connectors with live status, tariffs (time bands, parking), hours, amenities, rules to accept (`rules.id` → `rule_set_id` when booking), cancellation policy, rating.',
                        'params' => [$slug], 'response' => ['data' => ['slug' => 'trans-ev-bailey-road-patna', 'chargers' => [['id' => 33, 'code' => 'PAT-BLY-DC01', 'type' => 'DC', 'powerKw' => 60]], 'rules' => ['id' => 3, 'title' => 'Station rules', 'version' => 2], 'cancellation_policy' => ['tiers' => []], 'rating' => ['average' => 4.6, 'count' => 18]]]],
                    ['method' => 'GET', 'path' => '/stations/{slug}/reviews', 'title' => 'Reviews', 'auth' => false, 'params' => [$slug], 'response' => ['data' => [['stars' => 5, 'author' => 'Asha K.', 'body' => 'Fast and clean.', 'reply' => null]]]],
                    ['method' => 'GET', 'path' => '/stations/{slug}/availability', 'title' => 'Free slots', 'auth' => true, 'description' => 'Free start times per charger/connector for a day and duration.',
                        'params' => [$slug, $p('date', 'query', 'string', true, now('Asia/Kolkata')->addDay()->toDateString(), 'YYYY-MM-DD (from /config bookable_dates)'), $p('duration', 'query', 'integer', true, 60, 'Minutes'), $p('charger_id', 'query', 'integer', false, 33, 'One charger only')],
                        'response' => ['date' => '2026-11-26', 'chargers' => [['id' => 33, 'slots' => [['start' => '2026-11-26T09:00:00+05:30', 'free' => true]]]]]],
                    ['method' => 'POST', 'path' => '/stations/{slug}/estimate', 'title' => 'Price estimate', 'auth' => true, 'description' => 'Energy needed, time bands, fees, coupon and GST before booking.',
                        'params' => [$slug], 'body' => ['charger_id' => 33, 'battery_kwh' => 40.5, 'battery_from' => 20, 'battery_to' => 80, 'duration' => 60, 'start' => now('Asia/Kolkata')->addDay()->setTime(12, 0)->toIso8601String(), 'coupon_code' => null],
                        'response' => ['kwh' => 27.0, 'lines' => [['label' => 'Energy 27 kWh × ₹18', 'amount' => 486.0]], 'gst' => 96.48, 'total' => 632.48]],
                ],
            ],
            [
                'name' => 'Bookings',
                'description' => 'Reserve → pay → check in (QR pass) → charge (live) → final bill. A held booking must be paid within the hold time or it expires. Payment opens a hosted checkout URL (SabPaisa or Razorpay, depending on the station operator); open it in a web view/browser, then call `payment-status`.',
                'endpoints' => [
                    ['method' => 'POST', 'path' => '/stations/{slug}/bookings', 'title' => 'Reserve a slot', 'auth' => true, 'description' => 'Holds the slot. `rule_set_id` comes from the station details (`rules.id`); `accept_rules` must be true.',
                        'params' => [$slug], 'body' => ['charger_id' => 33, 'connector_id' => null, 'start' => now('Asia/Kolkata')->addDay()->setTime(12, 0)->toIso8601String(), 'duration' => 60, 'make' => 'Tata', 'model' => 'Nexon EV',
                            'registration_no' => 'MH12AB1234', 'battery_kwh' => 40.5, 'connector' => 'ccs2', 'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => true, 'name' => 'Asha Kumar',
                            'email' => 'asha@example.com', 'phone' => '9876543210', 'gstin' => null, 'state_code' => '27', 'rule_set_id' => 3, 'accept_rules' => true, 'coupon_code' => null],
                        'response' => ['data' => ['code' => 'TEV-261125-ABCDE', 'status' => 'held', 'canPay' => true, 'holdExpiresAt' => '2026-11-25T10:10:00+05:30'], 'message' => 'Slot reserved. Pay to confirm.']],
                    ['method' => 'GET', 'path' => '/bookings', 'title' => 'My bookings', 'auth' => true, 'params' => [$p('tab', 'query', 'string', false, 'upcoming', 'upcoming | past | cancelled | all'), $p('page', 'query', 'integer', false, 1, 'Page')],
                        'response' => ['data' => [['code' => 'TEV-261125-ABCDE', 'status' => 'confirmed', 'station' => 'Trans EV – Bailey Road']], 'meta' => ['tab' => 'upcoming', 'current_page' => 1, 'last_page' => 1, 'total' => 1]]],
                    ['method' => 'GET', 'path' => '/bookings/{code}', 'title' => 'Booking details', 'auth' => true, 'params' => [$code], 'response' => ['data' => $bookingExample]],
                    ['method' => 'POST', 'path' => '/bookings/{code}/pay', 'title' => 'Start payment', 'auth' => true, 'params' => [$code], 'response' => ['url' => 'https://checkout…', 'message' => 'Open this URL to pay.']],
                    ['method' => 'POST', 'path' => '/bookings/{code}/payment-status', 'title' => 'Check payment', 'auth' => true, 'description' => 'Asks the gateway again; returns the booking (status `confirmed` once paid).', 'params' => [$code], 'response' => ['data' => ['code' => 'TEV-261125-ABCDE', 'status' => 'confirmed']]],
                    ['method' => 'POST', 'path' => '/bookings/{code}/cancel', 'title' => 'Cancel', 'auth' => true, 'description' => 'Refund follows the cancellation policy shown in the booking (`cancelQuote`).', 'params' => [$code], 'response' => ['data' => ['status' => 'cancelled'], 'refund' => ['amount' => 632.48, 'status' => 'initiated']]],
                    ['method' => 'GET', 'path' => '/bookings/{code}/reschedule-options', 'title' => 'Reschedule options', 'auth' => true, 'params' => [$code, $p('date', 'query', 'string', true, now('Asia/Kolkata')->addDay()->toDateString(), 'Day')], 'response' => ['blocker' => null, 'connectors' => [['id' => 51, 'slots' => []]]]],
                    ['method' => 'POST', 'path' => '/bookings/{code}/reschedule', 'title' => 'Reschedule', 'auth' => true, 'params' => [$code], 'body' => ['connector_id' => 51, 'start' => now('Asia/Kolkata')->addDay()->setTime(15, 0)->toIso8601String()], 'response' => ['data' => ['code' => 'TEV-261125-ABCDE']]],
                    ['method' => 'POST', 'path' => '/bookings/{code}/running-late', 'title' => 'Running late', 'auth' => true, 'params' => [$code], 'body' => ['minutes' => 15, 'note' => 'Traffic'], 'response' => ['message' => 'The station knows you are 15 minutes late.']],
                    ['method' => 'GET', 'path' => '/bookings/{code}/pass', 'title' => 'Check-in QR pass', 'auth' => true, 'description' => 'Show `url` as a QR code; refresh before `expires_at` (it rotates).', 'params' => [$code], 'response' => ['url' => 'https://…/checkin/eyJ…', 'expires_at' => '2026-11-25T11:55:00+05:30']],
                    ['method' => 'GET', 'path' => '/bookings/{code}/live', 'title' => 'Live charging', 'auth' => true, 'description' => 'Poll every 5 s while charging: kWh, cost so far, power, battery %, estimated finish.', 'params' => [$code], 'response' => ['state' => 'charging', 'kwh' => 6.2, 'costSoFar' => 168.4, 'powerKw' => 48.0, 'soc' => 61]],
                    ['method' => 'POST', 'path' => '/bookings/{code}/stop', 'title' => 'Stop charging', 'auth' => true, 'description' => 'OCPP chargers stop remotely; manual chargers alert the staff.', 'params' => [$code], 'response' => ['message' => 'Stop requested.']],
                    ['method' => 'POST', 'path' => '/bookings/{code}/review', 'title' => 'Rate the charge', 'auth' => true, 'params' => [$code], 'body' => ['station_rating' => 5, 'charger_rating' => 5, 'tags' => ['Fast charging'], 'body' => 'Great!'], 'response' => ['status' => 'published']],
                    ['method' => 'GET', 'path' => '/bookings/{code}/documents', 'title' => 'GST invoice and credit notes', 'auth' => true, 'description' => 'PDF links valid for 30 minutes (no token needed to open them).', 'params' => [$code], 'response' => ['data' => ['invoice' => ['number' => 'TEV/2627/000123', 'pdf_url' => 'https://…/api/v1/documents/invoices/…?signature=…'], 'credit_notes' => []]]],
                ],
            ],
            [
                'name' => 'Waiting list',
                'description' => 'Join when no slot suits; an offer (held booking) arrives by push/SMS and must be paid before it expires.',
                'endpoints' => [
                    ['method' => 'POST', 'path' => '/stations/{slug}/waitlist', 'title' => 'Join', 'auth' => true, 'params' => [$slug], 'body' => ['date' => now('Asia/Kolkata')->addDay()->toDateString(), 'window_from' => '09:00', 'window_to' => '13:00', 'duration' => 60, 'connector' => 'ccs2', 'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'MH12AB1234', 'battery_kwh' => 40.5, 'battery_from' => 20, 'battery_to' => 80, 'name' => 'Asha Kumar', 'email' => 'asha@example.com', 'phone' => '9876543210', 'state_code' => '27', 'accept_rules' => true], 'response' => ['data' => ['uuid' => '…', 'status' => 'waiting', 'position' => 2]]],
                    ['method' => 'GET', 'path' => '/waitlist', 'title' => 'My waiting lists', 'auth' => true, 'params' => [], 'response' => ['data' => [['uuid' => '…', 'status' => 'waiting', 'position' => 2, 'estimated_at' => '2026-11-26T11:30:00+05:30']]]],
                    ['method' => 'DELETE', 'path' => '/waitlist/{uuid}', 'title' => 'Leave', 'auth' => true, 'params' => [$p('uuid', 'path', 'string', true, '…', 'Entry id')], 'response' => ['message' => 'You have left the waiting list.']],
                ],
            ],
            [
                'name' => 'Support',
                'description' => 'Threaded support tickets with attachments (multipart `files[]`, JPG/PNG/PDF).',
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/support/options', 'title' => 'Topics and priorities', 'auth' => true, 'params' => [], 'response' => ['data' => ['categories' => [['id' => 1, 'name' => 'Payment or refund', 'requires_booking' => false]], 'priorities' => []]]],
                    ['method' => 'GET', 'path' => '/support/tickets', 'title' => 'My tickets', 'auth' => true, 'params' => [], 'response' => ['data' => [['uuid' => '…', 'number' => 'TKT-000012', 'status' => 'waiting_on_customer']]]],
                    ['method' => 'POST', 'path' => '/support/tickets', 'title' => 'New ticket', 'auth' => true, 'params' => [], 'body' => ['category_id' => 1, 'subject' => 'Refund not received', 'body' => 'I cancelled yesterday and have no refund yet.', 'booking_code' => null], 'response' => ['data' => ['number' => 'TKT-000013', 'messages' => []]]],
                    ['method' => 'GET', 'path' => '/support/tickets/{uuid}', 'title' => 'Ticket thread', 'auth' => true, 'params' => [$p('uuid', 'path', 'string', true, '…', 'Ticket id')], 'response' => ['data' => ['number' => 'TKT-000013', 'messages' => [['mine' => true, 'author' => 'You', 'body' => '…', 'files' => []]]]]],
                    ['method' => 'POST', 'path' => '/support/tickets/{uuid}/reply', 'title' => 'Reply', 'auth' => true, 'params' => [$p('uuid', 'path', 'string', true, '…', 'Ticket id')], 'body' => ['body' => 'Thanks, any update?'], 'response' => ['data' => ['messages' => []]]],
                    ['method' => 'POST', 'path' => '/support/tickets/{uuid}/status', 'title' => 'Solved / reopen / rate', 'auth' => true, 'params' => [$p('uuid', 'path', 'string', true, '…', 'Ticket id')], 'body' => ['status' => 'closed', 'rating' => 5], 'response' => ['data' => ['status' => 'closed']]],
                ],
            ],
        ];
    }

    /** OpenAPI 3.0 document of the same endpoints (for Postman / code generators). */
    public static function openApi(): array
    {
        $paths = [];
        foreach (self::groups() as $g) {
            foreach ($g['endpoints'] as $e) {
                $op = [
                    'tags' => [$g['name']], 'summary' => $e['title'], 'description' => $e['description'] ?? '',
                    'parameters' => collect($e['params'] ?? [])->map(fn ($p) => ['name' => $p['name'], 'in' => $p['in'], 'required' => $p['required'], 'description' => $p['description'],
                        'schema' => ['type' => $p['type']], 'example' => $p['example']])->values()->all(),
                    'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['example' => $e['response'] ?? new \stdClass]]],
                        '401' => ['description' => 'Not signed in'], '422' => ['description' => 'Validation error']],
                ];
                if (isset($e['body'])) {
                    $op['requestBody'] = ['content' => [($e['multipart'] ?? false) ? 'multipart/form-data' : 'application/json' => ['example' => $e['body']]]];
                }
                if ($e['auth'] ?? false) {
                    $op['security'] = [['bearerAuth' => []]];
                }
                $paths[$e['path']][strtolower($e['method'])] = $op;
            }
        }

        return [
            'openapi' => '3.0.3',
            'info' => ['title' => config('app.name').' API', 'version' => '1.0.0', 'description' => 'REST API for the '.config('app.name').' Android and iOS apps.'],
            'servers' => [['url' => url('/api/v1')]],
            'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]],
            'paths' => $paths,
        ];
    }
}
