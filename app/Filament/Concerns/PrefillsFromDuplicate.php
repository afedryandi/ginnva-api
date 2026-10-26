<?php

namespace App\Filament\Concerns;

use App\Filament\Resources\Support\DuplicatesRecords;
use Filament\Notifications\Notification;

/**
 * Halaman Buat yang bisa terisi dari data lain lewat ?duplikat={id} (tombol "Duplikat" di daftar).
 * Hanya mengisi form: tidak ada yang tersimpan sampai pengguna menekan Buat, dan yang disalin hanya
 * kolom yang disebut Resource::duplicateData() (nomor, status, tanggal transaksi, bukti, dan persetujuan tidak ikut).
 * Resource harus memakai trait DuplicatesRecords.
 */
trait PrefillsFromDuplicate
{
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill();

        $this->prefillFromDuplicate();

        $this->callHook('afterFill');
    }

    private function prefillFromDuplicate(): void
    {
        $id = request()->query('duplikat');

        if (! $id) {
            return;
        }

        /** @var class-string<\Filament\Resources\Resource&DuplicatesRecords> $resource */
        $resource = static::getResource();
        $record = $resource::getModel()::query()->find($id);

        // Hanya yang boleh melihat dan membuat data ini; selain itu form dibiarkan kosong.
        if (! $record || ! $resource::canCreate() || ! $resource::canView($record)) {
            return;
        }

        $this->form->fill([...$this->form->getRawState(), ...$resource::duplicateData($record)]);

        Notification::make()
            ->title('Disalin dari data yang ada')
            ->body('Periksa isinya, lalu tekan Buat. Belum ada yang tersimpan.')
            ->info()
            ->send();
    }
}
