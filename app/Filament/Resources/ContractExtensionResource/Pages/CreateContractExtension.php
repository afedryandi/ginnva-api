<?php

namespace App\Filament\Resources\ContractExtensionResource\Pages;

use App\Filament\Resources\ContractExtensionResource;
use App\Models\ContractExtension;
use App\Models\User;
use App\Services\PushNotificationService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateContractExtension extends CreateRecord
{
    protected static string $resource = ContractExtensionResource::class;

    /**
     * Lewat ContractExtension::recordExtension() (bukan create() polos) —
     * itu yang urus snapshot previous_end_date DAN sinkronkan
     * users.contract_end_date sekaligus dalam 1 transaction.
     *
     * try/catch InvalidArgumentException ditambahkan 2026-09-27 (audit
     * Perpanjang Kontrak) supaya guard tanggal di recordExtension()
     * tampil sebagai notifikasi yang jelas, bukan error 500 mentah.
     */
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        // Pilihan karyawan di dropdown hanya dibatasi di UI (Select tidak memvalidasi nilai kiriman
        // di sisi server): tanpa pengecekan ini store manager bisa memperpanjang kontrak karyawan
        // toko lain, atau akun partner, lewat permintaan yang diubah paksa.
        $employee = User::findOrFail($data['user_id']);
        $actor = auth()->user();

        if ($employee->hasRole('partner') || (! $actor->isFullAccess() && $employee->store_id !== $actor->store_id)) {
            Notification::make()
                ->title('Tidak bisa disimpan')
                ->body('Karyawan ini tidak termasuk yang boleh Anda kelola.')
                ->danger()
                ->send();

            $this->halt();
        }

        try {
            return ContractExtension::recordExtension(
                $employee,
                $data['new_end_date'],
                auth()->id(),
                $data['notes'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            Notification::make()
                ->title('Tidak bisa disimpan')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }

    /**
     * Gap standar enterprise diperbaiki 2026-09-27 (audit Perpanjang
     * Kontrak) -- SEBELUMNYA karyawan tidak pernah diberi tahu saat
     * kontraknya diperpanjang, cuma bisa tahu kalau ditanya langsung.
     */
    protected function afterCreate(): void
    {
        app(PushNotificationService::class)->sendToUsers(
            [$this->record->user_id],
            'Kontrak Kerja Diperpanjang',
            'Kontrak Anda telah diperpanjang sampai ' . $this->record->new_end_date->translatedFormat('d M Y') . '.'
        );
    }
}
