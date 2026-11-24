<?php

namespace App\Filament\Resources\MessageTemplates;

use App\Models\MessageTemplate;
use App\Support\Messages;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Admin → Content → Message templates: wording of every email and SMS the platform sends. */
class MessageTemplateResource extends Resource
{
    protected static ?string $model = MessageTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Message templates';

    protected static ?int $navigationSort = 2;

    private static function allowed(): Response
    {
        return Tenancy::platformCan('content.manage') ? Response::allow() : Response::deny();
    }

    public static function getViewAnyAuthorizationResponse(): Response
    {
        return static::allowed();
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return Response::deny(); // every message is defined by the platform; edit its wording here
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return static::allowed();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function label(MessageTemplate $t): string
    {
        return Messages::TEMPLATES[$t->key]['label'] ?? $t->key;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make()->columnSpan(2)->schema([
                TextInput::make('subject')->label(fn (?MessageTemplate $record) => $record?->channel === 'push' ? 'Notification title' : 'Subject')->maxLength(fn (?MessageTemplate $record) => $record?->channel === 'push' ? 65 : 200)
                    ->visible(fn (Get $get, ?MessageTemplate $record) => in_array($record?->channel, ['mail', 'push'], true))->required(fn (?MessageTemplate $record) => $record?->channel === 'mail'),
                Textarea::make('body')->required()->rows(10)->maxLength(fn (?MessageTemplate $record) => match ($record?->channel) { 'sms' => 480, 'push' => 240, 'whatsapp' => 1024, default => 5000 })
                    ->helperText(fn (?MessageTemplate $record) => match ($record?->channel) {
                        'mail' => 'Separate paragraphs with a blank line. Start a paragraph with “# ” to make it the greeting.',
                        'sms' => 'Plain text. Must match the DLT-registered template word for word; MSG91 flow variables use the same names (##booking_code##…).',
                        'whatsapp' => 'Preview of the approved Meta template. The {{variables}} here, in order, fill the template’s {{1}}, {{2}}…',
                        default => 'Short text shown on the phone’s lock screen.',
                    }),
                TextInput::make('action_label')->label('Button text')->maxLength(60)->visible(fn (?MessageTemplate $record) => $record?->channel === 'mail'),
                TextInput::make('dlt_template_id')->label(fn (?MessageTemplate $record) => $record?->channel === 'whatsapp' ? 'Approved WhatsApp template name' : 'DLT template ID (MSG91 flow ID)')
                    ->maxLength(40)->visible(fn (?MessageTemplate $record) => in_array($record?->channel, ['sms', 'whatsapp'], true)),
                Toggle::make('is_active')->label('Send this message')->helperText('Turned off: this channel is not used for this message.'),
            ]),
            Section::make('Variables')->columnSpan(1)->schema([
                TextEntry::make('vars')->hiddenLabel()->state(fn (?MessageTemplate $record) => new HtmlString(
                    collect(Messages::TEMPLATES[$record?->key]['vars'] ?? [])->map(fn ($d, $k) => '<div style="margin-bottom:6px"><code>{{'.e($k).'}}</code><br><span style="opacity:.7">'.e($d).'</span></div>')->implode('')
                )),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('key')
            ->columns([
                TextColumn::make('key')->label('Message')->formatStateUsing(fn ($state, MessageTemplate $record) => static::label($record))->description(fn (MessageTemplate $record) => $record->subject)->wrap()->searchable(),
                TextColumn::make('channel')->badge()->formatStateUsing(fn (string $state) => \App\Models\NotificationDelivery::CHANNELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { 'mail' => 'info', 'sms' => 'warning', 'whatsapp' => 'success', default => 'gray' })
                    ->description(fn (MessageTemplate $record) => in_array($record->channel, ['sms', 'whatsapp'], true) && blank($record->dlt_template_id) ? 'Template ID needed before going live' : null),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('updated_at')->label('Last changed')->since(),
            ])
            ->filters([SelectFilter::make('channel')->options(\App\Models\NotificationDelivery::CHANNELS)])
            ->recordActions([
                EditAction::make(),
                Action::make('test')->label('Send test')->icon('heroicon-m-paper-airplane')->color('gray')
                    ->modalDescription(fn (MessageTemplate $record) => 'Sends this '.(\App\Models\NotificationDelivery::CHANNELS[$record->channel] ?? $record->channel).' message to you with sample values. Check the result in the Notification log.')
                    ->action(function (MessageTemplate $record) {
                        $sample = collect(Messages::TEMPLATES[$record->key]['vars'] ?? [])->mapWithKeys(fn ($d, $k) => [$k => $d])->all();
                        $n = new \App\Notifications\TemplateTest($record->key, $record->channel, $sample);
                        auth()->user()->notifyNow($n);
                        \Filament\Notifications\Notification::make()->success()->title('Test sent')->body('See Settings → Notification log for the result.')->send();
                    }),
                Action::make('preview')->icon('heroicon-m-eye')->color('gray')->modalSubmitAction(false)
                    ->visible(fn (MessageTemplate $record) => $record->channel === 'mail')
                    ->modalContent(function (MessageTemplate $record) {
                        $sample = collect(Messages::TEMPLATES[$record->key]['vars'] ?? [])->mapWithKeys(fn ($d, $k) => [$k => '['.$d.']'])->all();

                        return new HtmlString((string) Messages::mail($record->key, $sample, url('/'))->render());
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMessageTemplates::route('/'),
            'edit' => Pages\EditMessageTemplate::route('/{record}/edit'),
        ];
    }
}
