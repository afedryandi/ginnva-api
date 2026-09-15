<?php

namespace App\Filament\Resources\FinanceTransactionResource\Pages;

use App\Filament\Resources\FinanceTransactionApprovalRequestResource;
use App\Filament\Resources\FinanceTransactionResource;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\FinanceTransactionApprovalRequest;
use App\Services\FinanceTransactionApprovalService;
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
     * Fase 4 — Kontrol & Kepatuhan (2026-09-15): PENGELUARAN (type='out')
     * dari staff non-full-access TIDAK LAGI langsung tercatat -- masuk
     * antrean approval berjenjang dulu (store_manager lalu direksi),
     * lihat FinanceTransactionApprovalService. Pemasukan (type='in')
     * dan SEMUA transaksi dari full-access TETAP langsung tercatat
     * seperti sebelumnya (Fase 3, self-approval tidak menambah kontrol).
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();

        if ($data['type'] === 'out' && ! ($user?->isFullAccess() ?? false)) {
            $request = app(FinanceTransactionApprovalService::class)->submit($data, $user);

            Notification::make()
                ->title('Menunggu persetujuan')
                ->body('Pengeluaran ini sudah diajukan untuk disetujui sebelum tercatat.')
                ->warning()
                ->send();

            return $request;
        }

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

    /**
     * Kalau yang dibuat adalah pengajuan approval (bukan FinanceTransaction
     * sungguhan), arahkan ke daftar Persetujuan Pengeluaran, bukan
     * halaman Edit Transaksi Keuangan yang tidak berlaku untuk record ini.
     */
    protected function getRedirectUrl(): string
    {
        if ($this->record instanceof FinanceTransactionApprovalRequest) {
            return FinanceTransactionApprovalRequestResource::getUrl('index');
        }

        return parent::getRedirectUrl();
    }

    /**
     * Notifikasi "menunggu persetujuan" sudah dikirim manual di
     * handleRecordCreation() -- jangan dobel dengan notifikasi
     * "created" bawaan Filament yang judulnya tidak sesuai konteks ini.
     */
    protected function getCreatedNotification(): ?Notification
    {
        if ($this->record instanceof FinanceTransactionApprovalRequest) {
            return null;
        }

        return parent::getCreatedNotification();
    }
}
