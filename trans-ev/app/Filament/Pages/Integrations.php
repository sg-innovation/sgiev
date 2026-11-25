<?php

namespace App\Filament\Pages;

use App\Notifications\TemplateTest;
use App\Services\Messaging\Channels;
use App\Services\Messaging\Providers\SgiConnects;
use App\Support\Settings;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Mail;
use Throwable;
use UnitEnum;

/**
 * Platform integrations & credentials (super admin only).
 * Secrets are encrypted, never sent back to the browser, and changes are audit-logged without their values.
 */
class Integrations extends Page
{
    protected string $view = 'filament.pages.integrations';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Integrations & keys';

    protected static ?string $title = 'Integrations & credentials';

    protected static ?int $navigationSort = 10;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Tenancy::platformCan('integrations.manage');
    }

    public function mount(): void
    {
        $this->form->fill(Settings::formValues());
    }

    /** Masked secret input: empty keeps the stored value. */
    private function secret(string $key, string $label, bool $multiline = false)
    {
        $name = str_replace('.', '__', $key);
        $saved = Settings::hasSaved($key);
        $field = $multiline ? Textarea::make($name)->rows(4) : TextInput::make($name)->password()->revealable();

        return $field->label($label)
            ->placeholder($saved ? '•••••••• saved — type a new value to replace' : 'Not set')
            ->helperText($saved ? 'Saved securely. Leave empty to keep it. Type __clear__ to remove it.' : null)
            ->autocomplete('new-password');
    }

    private function text(string $key, string $label)
    {
        return TextInput::make(str_replace('.', '__', $key))->label($label);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Tabs::make('integrations')->persistTabInQueryString()->tabs([
                Tab::make('Email')->icon('heroicon-m-envelope')->schema([
                    Section::make('Sending')->columns(3)->schema([
                        Select::make('mail__mailer')->label('Provider')->required()->live()
                            ->options(['smtp' => 'SMTP', 'ses' => 'Amazon SES', 'resend' => 'Resend', 'log' => 'Log only (development)']),
                        $this->text('mail.from_address', 'From address')->email()->required(),
                        $this->text('mail.from_name', 'From name')->required(),
                    ]),
                    Section::make('SMTP')->columns(3)->visible(fn (Get $get) => $get('mail__mailer') === 'smtp')->schema([
                        $this->text('mail.smtp_host', 'Host'),
                        $this->text('mail.smtp_port', 'Port')->numeric(),
                        Select::make('mail__smtp_scheme')->label('Encryption')->options(['smtp' => 'STARTTLS / none', 'smtps' => 'SSL/TLS']),
                        $this->text('mail.smtp_username', 'Username'),
                        $this->secret('mail.smtp_password', 'Password'),
                    ]),
                    Section::make('Amazon SES')->columns(3)->visible(fn (Get $get) => $get('mail__mailer') === 'ses')->schema([
                        $this->secret('mail.ses_key', 'Access key ID'),
                        $this->secret('mail.ses_secret', 'Secret access key'),
                        $this->text('mail.ses_region', 'Region')->placeholder('ap-south-1'),
                    ]),
                    Section::make('Resend')->visible(fn (Get $get) => $get('mail__mailer') === 'resend')->schema([
                        $this->secret('mail.resend_key', 'API key'),
                    ]),
                ]),
                Tab::make('SMS & OTP')->icon('heroicon-m-device-phone-mobile')->schema([
                    Section::make('Provider')->schema([
                        Select::make('sms__provider')->label('Send SMS through')->options(Channels::PROVIDERS['sms'])->default('msg91')->required()->live()
                            ->helperText('Every SMS (booking updates, reminders, OTPs) goes through this provider. The other provider’s keys stay saved.'),
                    ]),
                    Section::make('SMS Birds')->description('HTTP API from the SMS Birds panel. The message text must match the DLT template; put each template’s DLT ID in Message templates.')
                        ->columns(2)->visible(fn (Get $get) => $get('sms__provider') === 'smsbirds')->schema([
                            $this->text('sms.smsbirds_url', 'API URL')->url()->columnSpanFull()
                                ->helperText('Default http://smsbirds.in/sms-panel/api/http/index.php (the panel’s HTTPS certificate does not match its domain).'),
                            $this->text('sms.smsbirds_username', 'Username'),
                            $this->secret('sms.smsbirds_api_key', 'API key'),
                            $this->text('sms.smsbirds_sender', 'Sender ID (6 letters, DLT header)')->maxLength(6),
                            Select::make('sms__smsbirds_route')->label('Route')->options(['TRANS' => 'Transactional', 'PROMO' => 'Promotional'])->default('TRANS'),
                        ]),
                    Section::make('MSG91')->description('Used for login OTPs and booking SMS. Template IDs must be DLT-registered.')->columns(2)
                        ->visible(fn (Get $get) => ($get('sms__provider') ?: 'msg91') === 'msg91')->schema([
                        $this->secret('sms.msg91_auth_key', 'Auth key'),
                        $this->text('sms.msg91_sender_id', 'Sender ID (6 letters)')->maxLength(6),
                        $this->text('sms.msg91_otp_template_id', 'OTP template ID'),
                        $this->text('sms.msg91_booking_template_id', 'Booking confirmation template ID'),
                    ]),
                ]),
                Tab::make('WhatsApp')->icon('heroicon-m-chat-bubble-left-right')->schema([
                    Section::make('Provider')->schema([
                        Select::make('whatsapp__provider')->label('Send WhatsApp through')->options(Channels::PROVIDERS['whatsapp'])->default('meta')->required()->live()
                            ->helperText('Messages use the approved template name set on each Message template; its variables fill {{1}}, {{2}}… in order.'),
                    ]),
                    Section::make('SGI Connects')->description('WhatsApp Business API by SGI Connects. Use “Test WhatsApp” above to check the licence and key.')
                        ->columns(2)->visible(fn (Get $get) => $get('whatsapp__provider') === 'sgiconnects')->schema([
                            $this->text('whatsapp.sgi_base_url', 'API base URL')->url()->columnSpanFull(),
                            $this->text('whatsapp.sgi_license', 'Licence number'),
                            $this->secret('whatsapp.sgi_api_key', 'API key'),
                        ]),
                    Section::make('WhatsApp Cloud API (Meta)')->columns(2)->visible(fn (Get $get) => ($get('whatsapp__provider') ?: 'meta') === 'meta')->schema([
                        $this->secret('whatsapp.access_token', 'Permanent access token'),
                        $this->text('whatsapp.phone_number_id', 'Phone number ID'),
                        $this->text('whatsapp.business_account_id', 'WhatsApp business account ID'),
                    ]),
                ]),
                Tab::make('Support email')->icon('heroicon-m-inbox-arrow-down')->schema([
                    Section::make('Replies by email')
                        ->description('Ticket emails get a Reply-To like support+TKT-000123@yourdomain. When the customer (or an agent) replies, your mail provider forwards the email to the webhook below and it is added to the ticket thread. Only the requester and agents who can work the ticket are accepted; quoted history is removed.')
                        ->columns(2)->schema([
                            $this->text('support.inbound_address', 'Support inbox address')->email()->placeholder('support@tranzactaev.com')
                                ->helperText('Must receive plus-addressed mail (support+anything@…) at your provider.'),
                            $this->secret('support.inbound_token', 'Webhook secret')
                                ->helperText('Any long random text (32+ characters). It becomes part of the webhook URL.'),
                            \Filament\Infolists\Components\TextEntry::make('inbound_url')->label('Inbound webhook URL for the mail provider')->columnSpanFull()
                                ->state(fn () => url('/webhooks/inbound-email').'/{webhook-secret}')
                                ->helperText('Replace {webhook-secret} with the secret above. Postmark (Inbound stream), Mailgun (Routes → forward), SendGrid (Inbound Parse), or any service that posts from / to / subject / text works. Leave both fields empty to turn email replies off.'),
                        ]),
                ]),
                Tab::make('Chargers (OCPP)')->icon('heroicon-m-signal')->schema([
                    Section::make('OCPP central system')
                        ->description('Chargers connect to this address followed by their identity, e.g. wss://ocpp.yourdomain/ocpp/CP-PUNE-01, with OCPP 1.6J or 2.0.1 and Basic auth (password per charger in Network → Charger control). The server runs as `php artisan ocpp:serve` (see README → OCPP).')
                        ->schema([
                            $this->text('ocpp.public_url', 'Public charger URL (without the identity)')->placeholder('wss://ocpp.tranzactaev.com/ocpp')
                                ->rule('regex:/^wss?:\/\/[^\s]+$/')->validationMessages(['regex' => 'Use a ws:// or wss:// address (wss:// in production).']),
                        ]),
                ]),
                Tab::make('Push')->icon('heroicon-m-bell-alert')->schema([
                    Section::make('Firebase Cloud Messaging')->description('For the Android and iOS apps.')->schema([
                        $this->text('push.fcm_project_id', 'Firebase project ID'),
                        $this->secret('push.fcm_service_account', 'Service account JSON', true),
                    ]),
                ]),
                Tab::make('Maps')->icon('heroicon-m-map')->schema([
                    Section::make()->columns(2)->schema([
                        Select::make('maps__provider')->label('Website map provider')->options(['openfreemap' => 'OpenFreeMap (free)', 'google' => 'Google Maps'])->default('openfreemap'),
                        $this->text('maps.style_url', 'Map style URL (OpenFreeMap / MapLibre)')->url(),
                        $this->secret('maps.google_api_key', 'Google Maps API key'),
                    ]),
                ]),
                Tab::make('Payments')->icon('heroicon-m-credit-card')->schema([
                    Section::make('Payment gateways')
                        ->description('Customer money goes straight to each operating company’s own merchant account, so each company picks SabPaisa or Razorpay and enters its own keys on its company page (Network → Companies → Edit → Payment gateway).')
                        ->schema([
                            Select::make('payments__default_gateway')->label('Default gateway for new companies')
                                ->options(\App\Services\Payments\GatewayFactory::GATEWAYS)->default('sabpaisa')->required(),
                            \Filament\Infolists\Components\TextEntry::make('company_gateways')->label('Gateway used by each company')->html()
                                ->state(fn () => $this->companyGateways()),
                        ]),
                    Section::make('Razorpay')
                        ->description('Uses Razorpay Payment Links (UPI, cards, net banking, wallets). Keys come from Razorpay Dashboard → Account & Settings → API keys: rzp_test_… for test mode, rzp_live_… for real money.')
                        ->schema([
                            \Filament\Infolists\Components\TextEntry::make('razorpay_setup')->hiddenLabel()->html()->state(new \Illuminate\Support\HtmlString(
                                '<ol style="list-style:decimal;padding-inline-start:1.25rem;display:grid;gap:.25rem" class="text-sm">'
                                .'<li>Open the company’s page and choose <b>Razorpay</b> under Payment gateway.</li>'
                                .'<li>Enter the <b>Key ID</b> and <b>Key secret</b>, and make up a <b>Webhook secret</b>.</li>'
                                .'<li>In Razorpay → Settings → Webhooks, add the webhook URL shown on that page with the same secret, and tick <code>payment_link.paid</code>, <code>payment_link.expired</code> and <code>refund.processed</code>.</li>'
                                .'<li>Make a small test booking. Payments already taken keep using the gateway that processed them for refunds.</li></ol>')),
                        ]),
                    Section::make('SabPaisa endpoints')
                        ->description('Each operating company chooses SabPaisa or Razorpay and enters its own merchant keys on its company page. Change these only if SabPaisa gives you new endpoints.')
                        ->columns(2)->schema([
                            $this->text('payments.sabpaisa_staging_url', 'Staging API base URL')->url(),
                            $this->text('payments.sabpaisa_production_url', 'Production API base URL')->url(),
                        ]),
                ]),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $changed = Settings::save($this->form->getState(), auth()->id());

        if ($changed) {
            activity()->causedBy(auth()->user())->withProperties(['keys' => $changed])->log('integration settings updated');
        }
        $this->form->fill(Settings::formValues());
        Notification::make()->success()->title($changed ? count($changed).' setting(s) saved' : 'No changes')->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testEmail')->label('Send test email')->icon('heroicon-m-paper-airplane')->color('gray')
                ->schema([TextInput::make('to')->label('Send to')->email()->required()->default(fn () => auth()->user()->email)])
                ->action(function (array $data) {
                    try {
                        Mail::raw('This is a test email from Trans EV. Your email settings work.', fn ($m) => $m->to($data['to'])->subject('Trans EV test email'));
                        Notification::make()->success()->title('Test email sent to '.$data['to'])->send();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Email failed')->body(str($e->getMessage())->limit(200))->send();
                    }
                }),
            Action::make('testSms')->label('Test SMS')->icon('heroicon-m-device-phone-mobile')->color('gray')
                ->modalDescription('Save your settings first. Sends the booking confirmation template (sample values) through the selected SMS provider.')
                ->schema([TextInput::make('phone')->label('Mobile number')->tel()->required()->regex('/^(\+?91)?[6-9]\d{9}$/')->default(fn () => auth()->user()->phone)])
                ->action(fn (array $data) => $this->sendTest('sms', $data['phone'])),
            Action::make('testWhatsApp')->label('Test WhatsApp')->icon('heroicon-m-chat-bubble-left-right')->color('gray')
                ->modalDescription('Save your settings first. Checks the account (SGI Connects) and sends the booking confirmation template with sample values.')
                ->schema([TextInput::make('phone')->label('WhatsApp number')->tel()->required()->regex('/^(\+?91)?[6-9]\d{9}$/')->default(fn () => auth()->user()->phone)])
                ->action(fn (array $data) => $this->sendTest('whatsapp', $data['phone'])),
        ];
    }

    /** Every operating company with its chosen gateway and whether its keys are in place. */
    private function companyGateways(): \Illuminate\Support\HtmlString
    {
        $rows = \App\Models\Company::query()->where('is_platform', false)->orderBy('trade_name')->get()->map(function (\App\Models\Company $c) {
            $gateway = $c->payment_gateway ?: 'sabpaisa';
            $mode = $gateway === 'razorpay'
                ? (str_starts_with((string) $c->razorpay_key_id, 'rzp_live_') ? 'live' : 'test')
                : ($c->sabpaisa_env === 'production' ? 'live' : 'test');
            $status = $c->hasPaymentSetup()
                ? '<span style="color:var(--success-600)">● Connected ('.$mode.')</span>'
                : '<span style="color:var(--danger-600)">● Keys missing</span>';
            $url = \App\Filament\Resources\Companies\CompanyResource::getUrl('edit', ['record' => $c]);

            return '<tr class="border-t border-gray-200 dark:border-white/10"><td class="py-1.5 pe-4"><a class="text-primary-600 hover:underline" href="'.e($url).'">'.e($c->trade_name).'</a></td>'
                .'<td class="py-1.5 pe-4">'.e(\App\Services\Payments\GatewayFactory::GATEWAYS[$gateway] ?? $gateway).'</td><td class="py-1.5">'.$status.'</td></tr>';
        })->implode('');

        return new \Illuminate\Support\HtmlString($rows === '' ? '<p class="text-sm text-gray-500">No operating companies yet.</p>'
            : '<table class="w-full text-sm"><thead><tr class="text-start text-gray-500"><th class="pb-1 text-start font-medium">Company</th><th class="pb-1 text-start font-medium">Gateway</th><th class="pb-1 text-start font-medium">Status</th></tr></thead><tbody>'.$rows.'</tbody></table>');
    }

    /** Template used for provider tests: booking confirmation with sample values (SMS and WhatsApp both have it). */
    public const TEST_TEMPLATE = 'booking.confirmed';

    /** Send the test template to one number through the selected provider and report the logged result. */
    private function sendTest(string $channel, string $phone): void
    {
        abort_unless(static::canAccess(), 403);
        if ($channel === 'whatsapp' && Channels::provider('whatsapp') === 'sgiconnects' && Channels::configured('whatsapp')) {
            $check = SgiConnects::checkAccount();
            if (! $check['ok']) {
                Notification::make()->danger()->title('SGI Connects: '.$check['message'])->send();

                return;
            }
        }
        // A copy of the signed-in admin with the number typed in (never saved), so the log links to them.
        $target = clone auth()->user();
        $target->phone = $phone;
        $sample = collect(\App\Support\Messages::TEMPLATES[self::TEST_TEMPLATE]['vars'] ?? [])->all();
        $before = \App\Models\NotificationDelivery::query()->max('id') ?? 0;
        $target->notifyNow(new TemplateTest(self::TEST_TEMPLATE, $channel, $sample));
        $row = \App\Models\NotificationDelivery::query()->where('id', '>', $before)->where('channel', $channel)->latest('id')->first();
        $status = $row?->status ?? 'failed';
        Notification::make()->{$status === 'sent' ? 'success' : ($status === 'simulated' ? 'warning' : 'danger')}()
            ->title(match ($status) { 'sent' => 'Sent through '.(Channels::PROVIDERS[$channel][Channels::provider($channel)] ?? $channel), 'simulated' => 'Not sent: the provider is not fully set up (logged only)', default => 'Failed' })
            ->body($row?->error ? (string) str($row->error)->limit(200) : null)->send();
    }
}
