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

    /** Jendela deteksi double-submit (menit). */
    private const DUPLICATE_WINDOW_MINUTES = 10;

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
        // Validasi SERVER (audit Kategori Keuangan 2026-09-28): kategori harus
        // ada dan AKTIF -- sebelumnya id palsu/nonaktif lolos dan type
        // dibiarkan dari input.
        $category = FinanceCategory::find($data['finance_category_id'] ?? null);
        if (! $category || ! $category->is_active) {
            Notification::make()
                ->title('Kategori tidak valid')
                ->body('Pilih kategori yang aktif.')
                ->danger()
                ->send();

            $this->halt();
        }

        if ($category->is_group) {
            Notification::make()
                ->title('Kategori tidak valid')
                ->body('Kategori grup tidak bisa dipakai untuk transaksi; pilih kategori di bawahnya.')
                ->danger()
                ->send();

            $this->halt();
        }

        $data['type'] = $category->type;

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

        // Cegah double-submit (klik dua kali / request terkirim ganda): data
        // identik (termasuk keterangan) dari user yang sama dalam 10 menit terakhir ditolak (audit
        // Transaksi Keuangan 2026-09-28). Mencakup transaksi langsung DAN
        // pengajuan approval yang masih menunggu.
        if ($this->isDuplicateSubmission($data, $user->id)) {
            Notification::make()
                ->title('Transaksi ini sudah dikirim')
                ->body('Data yang identik (nominal, kategori, toko, tanggal, keterangan) baru saja tercatat/diajukan dalam ' . self::DUPLICATE_WINDOW_MINUTES . ' menit terakhir. Cek daftar transaksi sebelum mengirim ulang. Kalau ini memang transaksi terpisah, bedakan keterangannya (mis. tambahkan nomor nota).')
                ->warning()
                ->send();

            $this->discardReceipt($data);
            $this->halt();
        }

        if ($data['type'] === 'out' && ! ($user?->isFullAccess() ?? false)) {
            try {
                $request = app(FinanceTransactionApprovalService::class)->submit($data, $user);
            } catch (RuntimeException $e) {
                Notification::make()
                    ->title('Pengajuan tidak bisa dibuat')
                    ->body($e->getMessage())
                    ->danger()
                    ->send();

                $this->discardReceipt($data);
                $this->halt();
            }

            Notification::make()
                ->title('Menunggu persetujuan')
                ->body('Pengeluaran ini sudah diajukan untuk disetujui sebelum tercatat.')
                ->warning()
                ->send();

            return $request;
        }

        try {
            return DB::transaction(function () use ($data) {
                $transaction = FinanceTransaction::create($data);

                $entry = app(FinanceTransactionPostingService::class)->post($transaction, auth()->id());
                $transaction->update(['journal_entry_id' => $entry->id]);

                return $transaction;
            });
        } catch (RuntimeException $e) {
            Notification::make()
                ->title('Transaksi tidak bisa disimpan')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->discardReceipt($data);
            $this->halt();
        }
    }

    private function isDuplicateSubmission(array $data, int $userId): bool
    {
        $keys = ['type', 'finance_category_id', 'store_id', 'amount', 'transaction_date', 'description'];
        $same = fn (array $a) => collect($keys)->every(fn ($k) => (string) ($a[$k] ?? '') === (string) ($data[$k] ?? ''));

        $recentTransactions = FinanceTransaction::where('created_by', $userId)
            ->where('created_at', '>=', now()->subMinutes(self::DUPLICATE_WINDOW_MINUTES))
            ->get()
            ->contains(fn (FinanceTransaction $t) => (float) $t->amount === (float) ($data['amount'] ?? 0)
                && (int) $t->finance_category_id === (int) ($data['finance_category_id'] ?? 0)
                && (int) $t->store_id === (int) ($data['store_id'] ?? 0)
                && $t->transaction_date->toDateString() === \Illuminate\Support\Carbon::parse($data['transaction_date'])->toDateString()
                && (string) $t->description === (string) ($data['description'] ?? ''));

        if ($recentTransactions) {
            return true;
        }

        return FinanceTransactionApprovalRequest::where('requested_by', $userId)
            ->whereIn('status', ['pending_manager', 'pending_direksi'])
            ->where('created_at', '>=', now()->subMinutes(self::DUPLICATE_WINDOW_MINUTES))
            ->get()
            ->contains(fn (FinanceTransactionApprovalRequest $r) => $same($r->payload));
    }

    /** File nota yang sudah terunggah tidak dirujuk siapa pun kalau penyimpanan gagal -- hapus supaya tidak yatim. */
    private function discardReceipt(array $data): void
    {
        if (! empty($data['receipt'])) {
            \Illuminate\Support\Facades\Storage::disk(config('filament.default_filesystem_disk', 'public'))->delete($data['receipt']);
        }
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
