<?php

namespace App\Filament\Pages;

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
                    Section::make('MSG91')->description('Used for login OTPs and booking SMS. Template IDs must be DLT-registered.')->columns(2)->schema([
                        $this->secret('sms.msg91_auth_key', 'Auth key'),
                        $this->text('sms.msg91_sender_id', 'Sender ID (6 letters)')->maxLength(6),
                        $this->text('sms.msg91_otp_template_id', 'OTP template ID'),
                        $this->text('sms.msg91_booking_template_id', 'Booking confirmation template ID'),
                    ]),
                ]),
                Tab::make('WhatsApp')->icon('heroicon-m-chat-bubble-left-right')->schema([
                    Section::make('WhatsApp Cloud API (Meta)')->columns(2)->schema([
                        $this->secret('whatsapp.access_token', 'Permanent access token'),
                        $this->text('whatsapp.phone_number_id', 'Phone number ID'),
                        $this->text('whatsapp.business_account_id', 'WhatsApp business account ID'),
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
                    Section::make('SabPaisa endpoints')
                        ->description('Each operating company enters its own SabPaisa merchant keys on its company page. Change these only if SabPaisa gives you new endpoints.')
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
        ];
    }
}
