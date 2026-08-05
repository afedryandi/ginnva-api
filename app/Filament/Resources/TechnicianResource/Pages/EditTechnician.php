<?php

namespace App\Filament\Resources\TechnicianResource\Pages;

use App\Filament\Resources\TechnicianResource;
use App\Models\Technician;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditTechnician extends EditRecord
{
    protected static string $resource = TechnicianResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('approve')
                ->label('Aktifkan')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => auth()->user()?->isFullAccess()
                    && $this->record->status === 'pending_review')
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->update(['status' => 'active']);
                    Notification::make()->title('Teknisi diaktifkan')->success()->send();
                    $this->refreshFormData(['status']);
                }),

            Actions\Action::make('deactivate')
                ->label('Nonaktifkan')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => auth()->user()?->isFullAccess()
                    && $this->record->status === 'active')
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->update(['status' => 'inactive']);
                    Notification::make()->title('Teknisi dinonaktifkan')->warning()->send();
                    $this->refreshFormData(['status']);
                }),

            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! auth()->user()?->isFullAccess()) {
            $data['store_id'] = auth()->user()->store_id;

            // status field disabled()+dehydrated() di form, tapi itu cuma
            // pengaman UI — paksa tetap ke nilai asli di sini supaya non-
            // super-admin tidak bisa self-approve/nonaktifkan teknisi lewat
            // form biasa. Approve/deactivate resmi cuma lewat tombol
            // khusus di header yang sudah dikunci isFullAccess().
            $data['status'] = $this->record->status;
        }

        return $data;
    }
}
