<?php

namespace App\Filament\Resources\FinanceTransactionResource\Pages;

use App\Filament\Resources\FinanceTransactionResource;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Services\FinanceTransactionPostingService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateFinanceTransaction extends CreateRecord
{
    protected static string $resource = FinanceTransactionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        // Field "Toko" dikunci+tidak ikut submit untuk non-full-access
        // (lihat FinanceTransactionResource::form()) — dipaksa ke toko
        // staff itu sendiri di sini, sama pola dengan CreateAsset.
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $data['store_id'] = auth()->user()?->store_id;
        }

        // 'type' DISALIN dari kategori yang dipilih (bukan sekadar
        // percaya nilai Radio di form) — jaring pengaman kalau ada
        // ketidaksinkronan state form (mis. race saat ganti tipe cepat),
        // supaya transaksi tidak pernah tersimpan dengan type yang beda
        // dari kategori aslinya.
        $category = FinanceCategory::find($data['finance_category_id']);
        if ($category) {
            $data['type'] = $category->type;
        }

        return $data;
    }

    /**
     * Fase 3 — transaksi ini SEKALIGUS diposting otomatis ke Jurnal
     * Umum (lihat FinanceTransactionPostingService), dibungkus 1 DB
     * transaction supaya kalau posting gagal (mis. kategori belum
     * dihubungkan ke Bagan Akun, atau periode sudah ditutup), Transaksi
     * Keuangan-nya juga TIDAK ikut tersimpan — staff langsung tahu ada
     * masalah, bukan dapat transaksi "yatim" tanpa jurnal di baliknya.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $transaction = FinanceTransaction::create($data);

            try {
                $entry = app(FinanceTransactionPostingService::class)->post($transaction);
                $transaction->update(['journal_entry_id' => $entry->id]);
            } catch (RuntimeException $e) {
                Notification::make()
                    ->title('Transaksi tidak bisa disimpan')
                    ->body($e->getMessage())
                    ->danger()
                    ->send();

                $this->halt();
            }

            return $transaction;
        });
    }
}
