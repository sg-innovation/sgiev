<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Tickets\TicketResource;
use App\Models\TicketCategory;
use App\Models\TicketPriority;
use App\Services\TicketService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/** Staff (including partners) raise a ticket to Trans EV support. */
class CreateTicket extends CreateRecord
{
    protected static string $resource = TicketResource::class;

    protected static ?string $title = 'New support ticket';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                Select::make('category_id')->label('Topic')->required()
                    ->options(fn () => TicketCategory::query()->for(auth()->user())->where('handled_by', 'platform')->pluck('name', 'id')->all()),
                Select::make('priority_id')->label('Priority')
                    ->options(fn () => TicketPriority::query()->orderBy('sort')->get()->mapWithKeys(fn ($p) => [$p->id => $p->name.' · reply within '.TicketPriority::humanMinutes($p->first_response_minutes)])->all())
                    ->placeholder('Default for the topic'),
                TextInput::make('subject')->required()->minLength(5)->maxLength(160)->columnSpanFull(),
                Textarea::make('body')->label('Describe the issue')->required()->minLength(10)->maxLength(5000)->rows(6)->columnSpanFull(),
                FileUpload::make('files')->label('Attachments')->multiple()->maxFiles(TicketService::maxFiles())->maxSize(TicketService::maxKb())
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->storeFiles(false)->columnSpanFull(),
            ]),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(TicketService::class)->open(
            auth()->user(), TicketCategory::findOrFail($data['category_id']), $data['subject'], $data['body'],
            filled($data['priority_id'] ?? null) ? TicketPriority::find($data['priority_id']) : null, null, array_values($data['files'] ?? []),
        );
    }

    protected function getRedirectUrl(): string
    {
        return TicketResource::getUrl('view', ['record' => $this->record]);
    }
}
