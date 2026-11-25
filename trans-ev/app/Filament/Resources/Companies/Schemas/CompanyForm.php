<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Support\IndianStates;
use App\Support\Tenancy;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        $upper = fn (?string $state) => $state ? Str::upper($state) : null;
        // Secrets are never sent back to the browser; leaving a secret field empty keeps the saved value.
        $secret = fn (string $name, string $label) => TextInput::make($name)->label($label)->password()->revealable()
            ->dehydrated(fn (?string $state) => filled($state))
            ->formatStateUsing(fn () => null)
            ->placeholder(fn ($record) => $record && filled($record->getRawOriginal($name)) ? '•••••••• saved — type to replace' : 'Not set');

        return $schema->components([
            Section::make('Application status')->icon('heroicon-o-clipboard-document-check')->columnSpanFull()
                ->visible(fn ($record) => $record && $record->status !== 'active')
                ->schema([
                    TextInput::make('status_display')->label('Status')->disabled()->dehydrated(false)
                        ->formatStateUsing(fn ($record) => \App\Models\Company::STATUSES[$record?->status] ?? $record?->status),
                    Textarea::make('review_note_display')->label('Message from Trans EV')->disabled()->dehydrated(false)->rows(2)
                        ->formatStateUsing(fn ($record) => $record?->review_note)->visible(fn ($record) => filled($record?->review_note)),
                ]),
            Section::make('Owner account')->description('An invitation to set a password is emailed to this person. They can then add the rest of the company’s staff.')
                ->columns(2)->columnSpanFull()->visibleOn('create')
                ->schema([
                    TextInput::make('owner_name')->label('Owner name')->required()->maxLength(120)->dehydrated(false),
                    TextInput::make('owner_email')->label('Owner email')->email()->required()->unique('users', 'email')->dehydrated(false),
                ]),
            Section::make('Legal details (printed on tax invoices)')->columns(3)->columnSpanFull()->schema([
                TextInput::make('legal_name')->required()->maxLength(255)->columnSpan(2)->notRegex('/[<>]/')->validationMessages(['not_regex' => 'The characters < and > are not allowed.']),
                TextInput::make('trade_name')->label('Brand / trade name')->required()->maxLength(255)->notRegex('/[<>]/')->validationMessages(['not_regex' => 'The characters < and > are not allowed.'])
                    ->helperText('Shown to customers as “Operated by …”.'),
                TextInput::make('gstin')->label('GSTIN')->maxLength(15)
                    ->regex('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/')->rule(new \App\Rules\ValidGstin)->dehydrateStateUsing($upper),
                TextInput::make('pan')->label('PAN')->maxLength(10)->regex('/^[A-Z]{5}[0-9]{4}[A-Z]$/')->dehydrateStateUsing($upper),
                TextInput::make('cin')->label('CIN')->maxLength(21)->dehydrateStateUsing($upper),
                TextInput::make('invoice_prefix')->required()->maxLength(8)->alphaNum()->default('INV')
                    ->helperText('Invoice numbers look like PREFIX/25-26/000123'),
                FileUpload::make('logo_path')->label('Logo')->image()->disk('public')->directory('company-logos')->visibility('public')->maxSize(1024)->columnSpan(2),
            ]),
            Section::make('Registered address')->columns(3)->columnSpanFull()->schema([
                TextInput::make('address_line1')->required()->columnSpan(2),
                TextInput::make('address_line2'),
                TextInput::make('city')->required(),
                Select::make('state_code')->label('State / UT')->options(IndianStates::options())->searchable()->required()->live()
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('state', IndianStates::name($state))),
                TextInput::make('state')->required()->readOnly(),
                TextInput::make('pincode')->required()->length(6)->regex('/^[1-9][0-9]{5}$/'),
            ]),
            Section::make('Contact')->columns(3)->columnSpanFull()->schema([
                TextInput::make('email')->email(),
                TextInput::make('phone')->tel(),
                TextInput::make('website')->url(),
                TextInput::make('support_email')->label('Customer support email')->email(),
                TextInput::make('support_phone')->label('Customer support phone')->tel(),
            ]),
            Section::make('Plan & features')->icon('heroicon-o-sparkles')->columns(2)->columnSpanFull()
                ->visible(fn ($record) => $record && ! $record->is_platform)
                ->schema([
                    Select::make('plan_id')->label('Plan')->relationship('plan', 'name', fn ($query) => $query->where('is_active', true)->orderBy('sort'))
                        ->disabled(fn () => ! Tenancy::platformCan('plans.manage'))
                        ->helperText(fn () => Tenancy::platformCan('plans.manage') ? 'Changing the plan switches features on or off immediately.' : 'Contact Trans EV to upgrade your plan.'),
                    TextInput::make('commission_percent')->label('Commission override')->numeric()->minValue(0)->maxValue(50)->suffix('%')
                        ->placeholder('Plan rate')->visible(fn () => Tenancy::platformCan('plans.manage'))->dehydrated(fn () => Tenancy::platformCan('plans.manage')),
                    TextInput::make('per_booking_fee')->label('Per-booking fee override')->numeric()->minValue(0)->maxValue(1000)->prefix('₹')
                        ->placeholder('Plan rate')->visible(fn () => Tenancy::platformCan('plans.manage'))->dehydrated(fn () => Tenancy::platformCan('plans.manage')),
                    \Filament\Forms\Components\Select::make('billing_mode')->label('Platform-fee collection')->options(['wallet' => 'Prepaid wallet', 'invoice' => 'Monthly invoice'])
                        ->visible(fn () => Tenancy::platformCan('plans.manage'))->dehydrated(fn () => Tenancy::platformCan('plans.manage')),
                    Textarea::make('fee_terms_note')->label('Commission terms agreed')->rows(2)->maxLength(500)->columnSpanFull()
                        ->visible(fn () => Tenancy::platformCan('plans.manage'))->dehydrated(fn () => Tenancy::platformCan('plans.manage'))
                        ->helperText(fn ($record) => $record?->fee_terms_agreed_at ? 'Recorded '.$record->fee_terms_agreed_at->setTimezone(\App\Models\Station::TIMEZONE)->format('d M Y') : null),
                    \Filament\Infolists\Components\TextEntry::make('fee_rates')->label('Platform fees')
                        ->state(fn ($record) => $record ? \App\Models\Company::feeRatesLabel($record) : null),
                    \Filament\Infolists\Components\TextEntry::make('plan_features')->label('Included features')
                        ->state(fn ($record) => collect(\App\Models\Plan::FEATURES)->only(($record?->plan ?? \App\Models\Plan::default())?->features ?? [])->implode(' · ') ?: '—'),
                ]),
            Section::make('Payment gateway')->icon('heroicon-o-banknotes')->columnSpanFull()
                ->description('Choose which gateway collects customer payments for this company’s stations. Money goes straight to the company’s own merchant account.')
                ->schema([
                    \Filament\Forms\Components\ToggleButtons::make('payment_gateway')->label('Gateway')->inline()->live()->required()->default(fn () => \App\Support\Settings::get('payments.default_gateway') ?: 'sabpaisa')
                        ->options(\App\Services\Payments\GatewayFactory::GATEWAYS)
                        ->helperText('Payments already taken keep using the gateway that processed them (for refunds and status checks).'),
                ]),
            Section::make('Online payments (SabPaisa)')->icon('heroicon-o-credit-card')->columns(2)->columnSpanFull()
                ->visible(fn ($get) => ($get('payment_gateway') ?: 'sabpaisa') === 'sabpaisa')
                ->description('Customer payments for this company’s stations go straight to this SabPaisa merchant account. Keys are stored encrypted and never shown again.')
                ->schema([
                    Select::make('sabpaisa_env')->label('Environment')->options(['staging' => 'Staging (test)', 'production' => 'Production (live)'])->default('staging')->required(),
                    TextInput::make('sabpaisa_merchant_id')->label('Merchant ID / client code')->maxLength(50),
                    $secret('sabpaisa_api_key', 'API key'),
                    $secret('sabpaisa_secret_key', 'Secret key'),
                    $secret('sabpaisa_webhook_secret', 'Webhook secret'),
                    TextInput::make('webhook_url')->label('Webhook URL to give SabPaisa')->dehydrated(false)->readOnly()->columnSpanFull()
                        ->formatStateUsing(fn ($record) => $record ? route('payments.webhook', $record->slug) : 'Shown after the company is created')
                        ->helperText('Send this URL to the SabPaisa integration team so payment updates reach this company. Must be HTTPS in production.'),
                ]),
            Section::make('Online payments (Razorpay)')->icon('heroicon-o-credit-card')->columns(2)->columnSpanFull()
                ->visible(fn ($get) => $get('payment_gateway') === 'razorpay')
                ->description('Uses Razorpay Payment Links. A key starting rzp_test_ runs in test mode, rzp_live_ takes real money. Secrets are stored encrypted and never shown again.')
                ->schema([
                    TextInput::make('razorpay_key_id')->label('Key ID')->maxLength(60)->regex('/^rzp_(test|live)_[A-Za-z0-9]+$/')
                        ->validationMessages(['regex' => 'Razorpay key IDs start with rzp_test_ or rzp_live_.']),
                    $secret('razorpay_key_secret', 'Key secret'),
                    $secret('razorpay_webhook_secret', 'Webhook secret'),
                    TextInput::make('razorpay_webhook_url')->label('Webhook URL for the Razorpay dashboard')->dehydrated(false)->readOnly()->columnSpanFull()
                        ->formatStateUsing(fn ($record) => $record ? route('payments.webhook.razorpay', $record->slug) : 'Shown after the company is created')
                        ->helperText('In Razorpay → Settings → Webhooks, add this URL with the same webhook secret and tick payment_link.paid, payment_link.expired and refund.processed.'),
                ]),
            Section::make('Website footer & invoice')->columnSpanFull()->collapsed()
                ->visible(fn ($record) => $record?->is_platform || Tenancy::isPlatformAdmin())
                ->schema([
                    KeyValue::make('social_links')->keyLabel('Network (facebook, instagram, x, linkedin, youtube, whatsapp)')->valueLabel('URL')
                        ->visible(fn ($record) => (bool) $record?->is_platform),
                    KeyValue::make('bank_details')->keyLabel('Field (bank, account_name, account_no, ifsc, branch)')->valueLabel('Value'),
                    Textarea::make('invoice_terms')->rows(3),
                ]),
        ]);
    }
}
