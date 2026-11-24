<?php

namespace App\Filament\Resources\RuleSets\Tables;

use App\Filament\Resources\RuleSets\Schemas\RuleSetForm;
use App\Models\RuleSet;
use App\Models\Station;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RuleSetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->weight('medium')->searchable(),
                TextColumn::make('company.trade_name')->label('Company')->placeholder('Platform default'),
                TextColumn::make('station.name')->label('Station')->placeholder('All stations'),
                TextColumn::make('version')->prefix('v')->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('published_at')->dateTime('d M Y, h:i A', Station::TIMEZONE)->placeholder('Draft'),
            ])
            ->recordActions([
                Action::make('publish')->icon('heroicon-m-megaphone')->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Publishing makes this version active. Customers must accept it on their next booking. Published versions cannot be edited.')
                    ->visible(fn (RuleSet $r) => $r->published_at === null && auth()->user()?->can('publish', $r))
                    ->action(function (RuleSet $r) {
                        $r->publish();
                        Notification::make()->success()->title("Version {$r->version} published")->send();
                    }),
                Action::make('newVersion')->label('New version')->icon('heroicon-m-document-duplicate')->color('gray')
                    ->visible(fn (RuleSet $r) => $r->published_at !== null && auth()->user()?->can('publish', $r))
                    ->action(function (RuleSet $r) {
                        $copy = RuleSet::create(RuleSetForm::withVersion([
                            'company_id' => $r->company_id,
                            'station_id' => $r->station_id,
                            'title' => $r->title,
                            'content' => $r->content,
                        ]));
                        Notification::make()->success()->title("Draft v{$copy->version} created")->send();
                    }),
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
