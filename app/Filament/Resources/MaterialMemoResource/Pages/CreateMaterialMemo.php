<?php

namespace App\Filament\Resources\MaterialMemoResource\Pages;

use App\Filament\Resources\MaterialMemoResource;
use App\Models\MaterialMemo;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CreateMaterialMemo extends CreateRecord
{
    protected static string $resource = MaterialMemoResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    /**
     * Bug diperbaiki 2026-09-30 (audit Memo Pengambilan/Pengembalian) --
     * SEBELUMNYA generateMemoNumber() dipanggil telanjang di
     * mutateFormDataBeforeCreate() tanpa retry, beda dari jalur mobile
     * (Api\Staff\MaterialMemoController::store()) yang sudah dibungkus
     * retry loop untuk race condition ini (2 request nyaris bersamaan
     * hitung-lalu-format nomor yang sama, ditolak UNIQUE constraint
     * memo_number sebagai QueryException mentah/500). Disamakan polanya
     * di sini -- override handleRecordCreation() (bukan
     * mutateFormDataBeforeCreate()) supaya create() ulang bisa dicoba
     * dengan nomor baru tiap percobaan.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $attempts = 0;

        while (true) {
            $attempts++;

            try {
                return DB::transaction(function () use ($data) {
                    return MaterialMemo::create($data + [
                        'memo_number' => MaterialMemo::generateMemoNumber(),
                    ]);
                });
            } catch (QueryException $e) {
                if ($attempts >= 3 || ! str_contains($e->getMessage(), 'memo_number')) {
                    throw $e;
                }
            }
        }
    }
}
