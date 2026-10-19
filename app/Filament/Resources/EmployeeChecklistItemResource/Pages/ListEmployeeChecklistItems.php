<?php

namespace App\Filament\Resources\EmployeeChecklistItemResource\Pages;

use App\Filament\Resources\EmployeeChecklistItemResource;
use App\Models\ChecklistTemplate;
use App\Models\EmployeeChecklistItem;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeChecklistItems extends ListRecords
{
    protected static string $resource = EmployeeChecklistItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('start')
                ->label('Mulai Checklist Karyawan')
                ->icon('heroicon-o-clipboard-document-check')
                ->visible(fn () => EmployeeChecklistItemResource::canCreate())
                ->form([
                    Forms\Components\Select::make('user_id')
                        ->label('Karyawan')
                        ->options(fn () => User::where('is_active', true)
                            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
                            ->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                    Forms\Components\Select::make('kind')
                        ->label('Jenis')
                        ->options(ChecklistTemplate::KINDS)
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data) {
                    $user = User::findOrFail($data['user_id']);
                    $added = EmployeeChecklistItem::startFor($user, $data['kind']);

                    Notification::make()
                        ->title($added > 0 ? "{$added} butir checklist ditambahkan untuk {$user->name}" : "Checklist {$user->name} sudah lengkap, tidak ada butir baru")
                        ->success()
                        ->send();
                }),
        ];
    }
}
