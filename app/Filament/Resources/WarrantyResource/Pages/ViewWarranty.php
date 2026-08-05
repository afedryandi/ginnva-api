<?php

namespace App\Filament\Resources\WarrantyResource\Pages;

use App\Filament\Resources\WarrantyResource;
use Filament\Actions;
use Filament\Forms;
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
                ->visible(fn () => auth()->user()?->isFullAccess() && $this->record->review_status === 'pending_review')
                ->requiresConfirmation()
                ->action(function () {
                    WarrantyResource::performApprove($this->record);
                    $this->refreshFormData(['review_status', 'reviewed_at', 'rejection_reason']);
                }),

            Actions\Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => auth()->user()?->isFullAccess() && $this->record->review_status === 'pending_review')
                ->form([
                    Forms\Components\Textarea::make('rejection_reason')
                        ->label('Alasan Reject')
                        ->required(),
                ])
                ->action(function (array $data) {
                    WarrantyResource::performReject($this->record, $data['rejection_reason']);
                    $this->refreshFormData(['review_status', 'reviewed_at', 'rejection_reason']);
                }),

            Actions\Action::make('extend')
                ->label('Perpanjang Garansi')
                ->icon('heroicon-o-calendar-days')
                ->color('info')
                ->visible(fn () => auth()->user()?->isFullAccess()
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
                    WarrantyResource::performExtend($this->record, (int) $data['years']);
                    $this->refreshFormData(['expiry_date', 'extension_years']);
                }),

            Actions\EditAction::make(),
            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
        ];
    }
}
