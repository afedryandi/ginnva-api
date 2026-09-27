<?php

namespace App\Filament\Resources\EmployeeTypeResource\Pages;

use App\Filament\Resources\EmployeeTypeResource;
use App\Models\User;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeType extends EditRecord
{
    protected static string $resource = EmployeeTypeResource::class;

    /**
     * Bug diperbaiki 2026-09-27 (audit Tipe Karyawan) -- SEBELUMNYA
     * toggle has_end_date bisa diubah bebas tanpa peringatan apa pun
     * pada tipe yang sudah dipakai banyak karyawan, padahal dampaknya
     * langsung & diam-diam: mengubahnya ke false diam-diam mengeluarkan
     * karyawan bertipe ini dari kandidat contracts:deactivate-expired
     * (query live, bukan snapshot), mengubahnya ke true tidak
     * retroaktif meminta contract_end_date karyawan yang sudah
     * terlanjur kosong. Field ini disimpan di sini (bukan dalam
     * closure form) supaya nilai SEBELUM disimpan bisa dibandingkan di
     * afterSave().
     */
    private ?bool $hadEndDateBeforeSave = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->hadEndDateBeforeSave = $this->record->has_end_date;

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->hadEndDateBeforeSave === $this->record->has_end_date) {
            return;
        }

        $affected = $this->record->users()->count();
        if ($affected === 0) {
            return;
        }

        $body = $this->record->has_end_date
            ? "{$affected} karyawan bertipe \"{$this->record->name}\" sekarang seharusnya punya Tanggal Berakhir Kontrak, tapi yang belum terisi TIDAK otomatis diminta -- cek satu-satu di Daftar Karyawan."
            : "{$affected} karyawan bertipe \"{$this->record->name}\" sekarang DIKECUALIKAN dari nonaktivasi otomatis kontrak habis (contracts:deactivate-expired), walau tanggal kontraknya sudah lewat.";

        Notification::make()
            ->title('"Memiliki Tanggal Berakhir" diubah')
            ->body($body)
            ->warning()
            ->persistent()
            ->send();

        /**
         * Gap standar enterprise diperbaiki 2026-09-27 (audit Tipe
         * Karyawan) -- SEBELUMNYA perubahan sepenting ini hanya
         * tercatat pasif di activity log, admin LAIN (yang tidak
         * sedang membuka layar ini) tidak diberi tahu sama sekali.
         * Dikirim ke admin lain (bukan diri sendiri -- sudah dapat
         * notifikasi transient di atas) yang full-access atau punya
         * akses menu Tipe Karyawan/User, persis pola
         * DeactivateExpiredContracts.
         */
        $otherAdmins = User::where('is_active', true)
            ->where('id', '!=', auth()->id())
            ->get()
            ->filter(fn (User $u) => $u->isFullAccess()
                || $u->hasMenuAccess(EmployeeTypeResource::class)
                || $u->hasMenuAccess(\App\Filament\Resources\UserResource::class));

        foreach ($otherAdmins as $admin) {
            Notification::make()
                ->title('"Memiliki Tanggal Berakhir" Tipe Karyawan Diubah')
                ->body($body . " Diubah oleh " . (auth()->user()?->name ?? '—') . '.')
                ->warning()
                ->sendToDatabase($admin);
        }
    }
}
