<?php

namespace App\Filament\Resources\WarrantyResource\Pages;

use App\Filament\Resources\WarrantyResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\EditRecord;

class EditWarranty extends EditRecord
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

            // Gap "revoke/void" diperbaiki 2026-09-25 (audit Garansi) --
            // lihat WarrantyResource::performRevoke().
            Actions\Action::make('revoke')
                ->label('Batalkan Garansi (Revoke)')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(fn () => auth()->user()?->isFullAccess()
                    && $this->record->review_status === 'approved'
                    && $this->record->status !== 'revoked')
                ->form([
                    Forms\Components\Textarea::make('revoke_reason')
                        ->label('Alasan Pembatalan')
                        ->required(),
                ])
                ->requiresConfirmation()
                ->modalDescription('Garansi yang dibatalkan TIDAK bisa diaktifkan lagi lewat aksi ini — riwayatnya tetap tersimpan (beda dari Delete). Yakin lanjutkan?')
                ->action(function (array $data) {
                    WarrantyResource::performRevoke($this->record, $data['revoke_reason']);
                    $this->refreshFormData(['status', 'revoke_reason', 'revoked_at']);
                }),

            // Gap "transfer ke pemilik baru" diperbaiki 2026-09-25 (audit
            // Garansi) -- lihat WarrantyResource::performOwnershipTransfer().
            Actions\Action::make('transfer_ownership')
                ->label('Transfer Kepemilikan')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('gray')
                ->visible(fn () => auth()->user()?->isFullAccess()
                    && $this->record->review_status === 'approved'
                    && $this->record->status !== 'revoked')
                ->form(fn () => [
                    Forms\Components\Placeholder::make('current_owner_info')
                        ->label('Pemilik Saat Ini')
                        ->content($this->record->display_customer_name . ($this->record->display_phone_number !== '—' ? " ({$this->record->display_phone_number})" : '')),

                    Forms\Components\TextInput::make('new_customer_name')
                        ->label('Nama Pemilik Baru')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('new_phone_number')
                        ->label('No. Telepon Pemilik Baru')
                        ->tel()
                        ->maxLength(255),

                    Forms\Components\Select::make('new_customer_id')
                        ->label('Akun Customer Baru (opsional)')
                        ->placeholder('Pilih kalau pemilik baru sudah punya akun app')
                        ->options(fn () => \App\Models\Customer::orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($c) => [$c->id => trim(($c->name ?? 'Tanpa Nama') . ' — ' . $c->email)])
                        )
                        ->searchable()
                        ->helperText('Kalau diisi, garansi ini akan otomatis muncul di "Garansi Saya" akun tersebut.'),

                    Forms\Components\Textarea::make('note')
                        ->label('Catatan (opsional)')
                        ->placeholder('Contoh: bukti jual-beli kwitansi No. 123'),
                ])
                ->requiresConfirmation()
                ->modalDescription('Kepemilikan garansi akan dipindahkan dari pemilik saat ini ke pemilik baru. Riwayat pemilik lama tetap tersimpan.')
                ->action(function (array $data) {
                    WarrantyResource::performOwnershipTransfer($this->record, $data);
                    $this->refreshFormData(['customer_id', 'customer_name', 'phone_number']);
                }),

            // Fitur "Kuota Maintenance" (2026-09-25) -- lihat
            // WarrantyResource::performRecordMaintenanceVisit().
            Actions\Action::make('record_maintenance_visit')
                ->label('Catat Kunjungan Maintenance')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('success')
                ->visible(fn () => $this->record->review_status === 'approved'
                    && $this->record->status !== 'revoked'
                    && $this->record->maintenance_quota !== null
                    && $this->record->maintenance_remaining > 0)
                ->form([
                    Forms\Components\DatePicker::make('visited_at')
                        ->label('Tanggal Kunjungan')
                        ->required()
                        ->default(now())
                        ->maxDate(now()),

                    Forms\Components\Textarea::make('note')
                        ->label('Catatan (opsional)')
                        ->placeholder('Contoh: cek kondisi PPF area kap mesin'),
                ])
                ->requiresConfirmation()
                ->modalDescription(fn () => "Sisa kuota saat ini: {$this->record->maintenance_remaining}/{$this->record->maintenance_quota} kunjungan.")
                ->action(function (array $data) {
                    WarrantyResource::performRecordMaintenanceVisit($this->record, $data);
                    $this->refreshFormData(['maintenance_quota']);
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

            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $data['store_id'] = $user->store_id;
        }

        return $data;
    }
}
