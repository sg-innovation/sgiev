<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Models\CompanyDocument;
use App\Models\Station;
use App\Support\Tenancy;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/** KYC documents: owners upload, platform admins verify or reject. Files are private. */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'KYC documents';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->formatStateUsing(fn (string $state) => CompanyDocument::typeLabel($state))->weight('medium')
                    ->description(fn (CompanyDocument $record) => $record->original_name),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) { 'verified' => 'success', 'rejected' => 'danger', default => 'warning' })
                    ->description(fn (CompanyDocument $record) => $record->review_note),
                TextColumn::make('size')->formatStateUsing(fn ($state) => number_format($state / 1024, 0).' KB')->color('gray'),
                TextColumn::make('created_at')->label('Uploaded')->dateTime('d M Y', Station::TIMEZONE),
            ])
            ->headerActions([
                Action::make('upload')->label('Upload document')->icon('heroicon-m-arrow-up-tray')
                    ->visible(fn () => auth()->user()->can('update', $this->getOwnerRecord()))
                    ->schema([
                        Select::make('type')->options(fn () => CompanyDocument::types())->required(),
                        FileUpload::make('file')->required()->disk('local')->directory('kyc-tmp')->visibility('private')
                            ->acceptedFileTypes(CompanyDocument::ALLOWED_MIMES)->maxSize(CompanyDocument::maxKb())->storeFiles(false),
                    ])
                    ->action(function (array $data) {
                        $file = $data['file'];
                        if ($file instanceof TemporaryUploadedFile || $file instanceof UploadedFile) {
                            CompanyDocument::storeFor($this->getOwnerRecord(), $data['type'], $file, auth()->id());
                            Notification::make()->success()->title('Document uploaded')->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('open')->label('View')->icon('heroicon-m-eye')->color('gray')
                    ->url(fn (CompanyDocument $record) => route('kyc.show', $record), shouldOpenInNewTab: true),
                Action::make('verify')->icon('heroicon-m-check-badge')->color('success')
                    ->visible(fn (CompanyDocument $record) => Tenancy::platformCan('partners.review') && $record->status !== 'verified')
                    ->action(function (CompanyDocument $record) {
                        $record->update(['status' => 'verified', 'review_note' => null, 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
                    }),
                Action::make('reject')->icon('heroicon-m-x-circle')->color('danger')
                    ->visible(fn (CompanyDocument $record) => Tenancy::platformCan('partners.review') && $record->status !== 'rejected')
                    ->schema([TextInput::make('note')->label('Reason (shown to the partner)')->required()->maxLength(300)])
                    ->action(function (CompanyDocument $record, array $data) {
                        $record->update(['status' => 'rejected', 'review_note' => $data['note'], 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
                    }),
            ]);
    }
}
