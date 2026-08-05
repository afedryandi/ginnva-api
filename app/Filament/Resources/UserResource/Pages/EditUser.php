<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

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
        return UserResource::splitMenuAccessIntoFields($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->rolesBeforeSave = $this->record->roles()->pluck('name')->all();

        return UserResource::mergeMenuAccessFields($data);
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