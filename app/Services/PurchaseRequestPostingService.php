<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\PurchaseRequest;
use RuntimeException;

/**
 * Auto-posting Permohonan Pembelian "Terpenuhi" → Jurnal Umum
 * Persediaan/Aset. Dipicu dari aksi "Tandai Terpenuhi" di
 * PurchaseRequestResource, sekaligus tempat actual_cost diisi (kolom
 * ini TIDAK ADA sebelumnya — permohonan aslinya cuma quantity+unit,
 * tidak ada dasar data Rupiah sampai kasir/admin mengisinya di sini).
 *
 * Debit 1130/1131 Persediaan (raw_material/consumable_item) atau akun
 * Aset Tetap pilihan admin (item_type='asset'), Kredit 2110 Hutang
 * Usaha — SENGAJA Hutang Usaha, BUKAN Kas, karena pembelian dari
 * supplier lazimnya berjalan (belum tentu langsung lunas saat barang
 * diterima). Ini POSTING SEDERHANA (menaikkan saldo 2110), BUKAN fitur
 * Hutang Usaha penuh (tidak ada pelacakan jatuh tempo/pelunasan per
 * tagihan — itu item checklist terpisah, belum dibangun).
 *
 * TIDAK menyentuh stok/RawMaterialMovement/ConsumableItemMovement sama
 * sekali — itu tetap lewat "Catat Stok" yang sudah ada, terpisah dari
 * jurnal keuangan ini (konsisten dengan komentar aksi 'fulfill' asli).
 */
class PurchaseRequestPostingService
{
    private const HUTANG_USAHA_ACCOUNT_CODE = '2110';
    private const PERSEDIAAN_BAHAN_BAKU_ACCOUNT_CODE = '1130';
    private const PERSEDIAAN_HABIS_PAKAI_ACCOUNT_CODE = '1131';

    /**
     * @throws RuntimeException kalau permohonan ini sudah pernah
     *         diposting, item_type='asset' tapi $assetAccountId tidak
     *         diisi, akun yang dibutuhkan tidak ditemukan, atau periode
     *         tanggal posting sudah ditutup (diteruskan dari
     *         JournalEntryService).
     */
    public function post(PurchaseRequest $request, float $actualCost, ?int $assetAccountId = null): JournalEntry
    {
        if ($request->journal_entry_id) {
            throw new RuntimeException('Permohonan ini sudah pernah diposting ke Jurnal Umum sebelumnya.');
        }

        $debitAccountId = match ($request->item_type) {
            'raw_material' => ChartOfAccount::where('code', self::PERSEDIAAN_BAHAN_BAKU_ACCOUNT_CODE)->value('id'),
            'consumable_item' => ChartOfAccount::where('code', self::PERSEDIAAN_HABIS_PAKAI_ACCOUNT_CODE)->value('id'),
            'asset' => $assetAccountId,
            default => null,
        };

        if (! $debitAccountId) {
            throw new RuntimeException($request->item_type === 'asset'
                ? 'Pilih dulu akun Aset Tetap tujuan untuk permohonan jenis Aset Baru.'
                : 'Akun Persediaan yang dibutuhkan tidak ditemukan di Bagan Akun.');
        }

        $hutangUsaha = ChartOfAccount::where('code', self::HUTANG_USAHA_ACCOUNT_CODE)->first();
        if (! $hutangUsaha) {
            throw new RuntimeException('Akun Hutang Usaha (kode ' . self::HUTANG_USAHA_ACCOUNT_CODE . ') tidak ditemukan di Bagan Akun.');
        }

        $service = app(JournalEntryService::class);

        $entry = $service->create([
            'entry_date' => now()->toDateString(),
            'store_id' => $request->store_id,
            'description' => "Pembelian {$request->item_name} (permohonan {$request->request_number})",
            'reference_type' => 'purchase_request',
            'reference_id' => $request->id,
            'created_by' => auth()->id(),
        ], [
            ['chart_of_account_id' => $debitAccountId, 'debit' => $actualCost],
            ['chart_of_account_id' => $hutangUsaha->id, 'credit' => $actualCost],
        ]);

        return $service->post($entry, auth()->id());
    }
}
