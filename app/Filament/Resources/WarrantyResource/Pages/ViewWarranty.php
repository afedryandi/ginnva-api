<?php

namespace App\Filament\Resources\WarrantyResource\Pages;

use App\Filament\Resources\WarrantyResource;
use App\Models\Warranty;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewWarranty extends ViewRecord
{
    protected static string $resource = WarrantyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => auth()->user()?->hasRole('super_admin') && $this->record->review_status === 'pending_review')
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->update([
                        'review_status'    => 'approved',
                        'rejection_reason' => null,
                        'reviewed_by'      => auth()->id(),
                        'reviewed_at'      => now(),
                    ]);

                    Notification::make()->title('Garansi disetujui')->success()->send();
                    $this->refreshFormData(['review_status', 'reviewed_at', 'rejection_reason']);
                }),

            Actions\Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => auth()->user()?->hasRole('super_admin') && $this->record->review_status === 'pending_review')
                ->form([
                    Forms\Components\Textarea::make('rejection_reason')
                        ->label('Alasan Reject')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $this->record->update([
                        'review_status'    => 'rejected',
                        'rejection_reason' => $data['rejection_reason'],
                        'reviewed_by'      => auth()->id(),
                        'reviewed_at'      => now(),
                    ]);

                    Notification::make()->title('Garansi ditolak')->warning()->send();
                    $this->refreshFormData(['review_status', 'reviewed_at', 'rejection_reason']);
                }),

            Actions\Action::make('extend')
                ->label('Perpanjang Garansi')
                ->icon('heroicon-o-calendar-days')
                ->color('info')
                ->visible(fn () => auth()->user()?->hasRole('super_admin')
                    && $this->record->review_status === 'approved')
                ->form([
                    Forms\Components\Select::make('years')
                        ->label('Perpanjang')
                        ->options([
                            1 => '+ 1 Tahun',
                            2 => '+ 2 Tahun',
                        ])
                        ->required(),
                ])
                ->action(function (array $data) {
                    $warranty = $this->record;

                    if (! $warranty->original_expiry_date) {
                        $warranty->original_expiry_date = $warranty->expiry_date;
                    }

                    $warranty->extension_years += $data['years'];
                    $warranty->expiry_date = Carbon::parse($warranty->original_expiry_date)
                        ->addYears($warranty->extension_years);
                    $warranty->save();

                    Notification::make()
                        ->title("Garansi diperpanjang +{$data['years']} tahun")
                        ->success()
                        ->send();

                    $this->refreshFormData(['expiry_date', 'extension_years']);
                }),

            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
