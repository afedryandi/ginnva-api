<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Role;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    // Spatie roles = relasi many-to-many, BUKAN kolom langsung di tabel
    // users — LogsActivity (dirty-attribute tracking) tidak bisa
    // menangkapnya otomatis, jadi diaudit manual lewat activity() helper
    // (lihat mutateFormDataBeforeSave/afterSave di bawah).
    protected array $rolesBeforeSave = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    // Pecah kolom `menu_access` (array gabungan) balik jadi field per-grup
    // (menu_access_penjualan, dst) supaya CheckboxList di tiap grup
    // menampilkan centang yang benar saat form edit dibuka.
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = UserResource::splitMenuAccessIntoFields($data);

        return UserResource::splitMenuPermissionsIntoFields($data);
    }

    /**
     * Bug KRITIS diperbaiki 2026-09-26 (audit fitur User) -- SEBELUMNYA
     * canEdit() cuma cek isFullAccess(), tidak ada pengecualian
     * "$record->id === auth()->id()" seperti canDelete()/toggleActive()
     * yang sudah punya proteksi itu. Akibatnya seorang super_admin bisa
     * buka form edit akunnya SENDIRI dan mencabut role super_admin/
     * direksi dari dirinya sendiri tanpa guard apa pun -- kalau itu
     * satu-satunya akun full-access aktif, SELURUH sistem kehilangan
     * admin (tidak ada siapa pun lagi yang bisa buka menu User/Role
     * untuk mengoreksi). Guard ini dicek di sini (bukan cuma canEdit())
     * karena masalahnya spesifik ke PERUBAHAN ROLE, bukan akses form
     * edit itu sendiri (field lain seperti nama/password tetap wajar
     * bisa diedit sendiri).
     */
    private function guardLastFullAccessAccount(array $data): void
    {
        $newRoleIds = $data['roles'] ?? [];
        $newRoleNames = Role::whereIn('id', $newRoleIds)->pluck('name')->all();

        $wasFullAccess = collect($this->rolesBeforeSave)->intersect(['super_admin', 'direksi'])->isNotEmpty();
        $willBeFullAccess = collect($newRoleNames)->intersect(['super_admin', 'direksi'])->isNotEmpty();

        if (! $wasFullAccess || $willBeFullAccess) {
            return;
        }

        $otherFullAccessCount = User::where('id', '!=', $this->record->id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'direksi']))
            ->count();

        if ($otherFullAccessCount === 0) {
            Notification::make()
                ->title('Tidak bisa disimpan')
                ->body('Akun ini adalah satu-satunya akun full-access (super_admin/direksi) yang AKTIF di sistem. Mencabut role ini akan membuat tidak ada satu pun admin yang bisa mengelola hak akses lagi. Tambahkan atau aktifkan akun full-access lain dulu sebelum mengubah role akun ini.')
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->rolesBeforeSave = $this->record->roles()->pluck('name')->all();

        $this->guardLastFullAccessAccount($data);

        // "Riwayat Karir" (audit Majoo f57) — alasan perpindahan toko,
        // field TRANSIEN (bukan kolom users, lihat User::$pendingTransferReason)
        // yang dibaca User::booted()::updated() begitu store_id benar-
        // benar berubah. Dihapus dari $data supaya tidak ikut lewat ke
        // update() (aman juga kalau lupa -- 'transfer_reason' bukan
        // $fillable, Eloquent otomatis mengabaikannya).
        $this->record->pendingTransferReason = $data['transfer_reason'] ?? null;
        unset($data['transfer_reason']);

        $data = UserResource::mergeMenuAccessFields($data);

        return UserResource::mergeMenuPermissionFields($data);
    }

    protected function afterSave(): void
    {
        $before = collect($this->rolesBeforeSave)->sort()->values()->all();
        $after = $this->record->roles()->pluck('name')->sort()->values()->all();

        if ($before === $after) {
            return;
        }

        activity('user')
            ->causedBy(auth()->user())
            ->performedOn($this->record)
            ->withProperties(['old' => ['roles' => $before], 'attributes' => ['roles' => $after]])
            ->log("Role user \"{$this->record->name}\" ({$this->record->email}) diubah");
    }
}