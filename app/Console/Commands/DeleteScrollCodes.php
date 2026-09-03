<?php

namespace App\Console\Commands;

use App\Models\ScrollCode;
use Illuminate\Console\Command;

/**
 * Hapus PERMANEN baris Kode Gulungan tertentu (by kode) — dibuat untuk
 * bersih-bersih data testing/live-QA, bukan dijadwalkan.
 *
 * Aman untuk data yang terkait:
 * - scroll_code_usages ikut cascadeOnDelete (riwayat pemakaian kode itu
 *   ikut terhapus) — makanya jumlahnya dilaporkan & butuh konfirmasi
 *   eksplisit kalau ada riwayat, supaya tidak ada yang tidak sadar
 *   menghapus riwayat pemakaian asli, bukan cuma kode test kosong.
 * - inventory_items.scroll_code_id di-null-kan otomatis (nullOnDelete),
 *   TIDAK ikut terhapus — unit fisik gudangnya tetap ada, cuma
 *   tautannya ke kode gulungan ini yang lepas.
 * - Kolom warranties.roll_number/roll_number_2/roll_number_front/
 *   roll_number_side_rear TIDAK punya foreign key ke tabel ini (cuma
 *   simpan teks kode) — dilaporkan terpisah karena tidak akan ketahuan
 *   lewat error FK kalau ada garansi yang masih "menyebut" kode ini.
 */
class DeleteScrollCodes extends Command
{
    protected $signature = 'scroll-codes:delete {codes* : Satu atau lebih kode gulungan yang mau dihapus} {--force : Lewati konfirmasi interaktif}';

    protected $description = 'Hapus permanen baris Kode Gulungan tertentu (by kode), beserta riwayat pemakaiannya';

    public function handle(): int
    {
        $codes = $this->argument('codes');

        $scrollCodes = ScrollCode::whereIn('code', $codes)->get();

        $notFound = collect($codes)->diff($scrollCodes->pluck('code'));
        if ($notFound->isNotEmpty()) {
            $this->warn('Kode tidak ditemukan (dilewati): ' . $notFound->implode(', '));
        }

        if ($scrollCodes->isEmpty()) {
            $this->info('Tidak ada kode yang cocok untuk dihapus.');
            return self::SUCCESS;
        }

        $this->table(
            ['Kode', 'Toko', 'Status', 'Jumlah Riwayat Pemakaian', 'Terkait Warranty (teks, cek manual)'],
            $scrollCodes->map(fn (ScrollCode $sc) => [
                $sc->code,
                $sc->store?->name ?? '—',
                $sc->status,
                $sc->usages()->count(),
                $sc->warranty_code ?? '—',
            ])
        );

        $hasUsageHistory = $scrollCodes->contains(fn (ScrollCode $sc) => $sc->usages()->count() > 0);
        if ($hasUsageHistory) {
            $this->warn('Perhatian: ada kode di atas yang punya riwayat pemakaian — riwayat itu akan ikut terhapus permanen (cascade), bukan cuma baris kode gulungannya.');
        }

        if (! $this->option('force')) {
            $confirmed = $this->confirm('Lanjutkan hapus permanen ' . $scrollCodes->count() . ' kode gulungan di atas?', false);
            if (! $confirmed) {
                $this->warn('Dibatalkan, tidak ada data yang dihapus.');
                return self::FAILURE;
            }
        }

        $deleted = 0;
        foreach ($scrollCodes as $scrollCode) {
            $scrollCode->delete();
            $deleted++;
        }

        $this->info("Selesai: {$deleted} kode gulungan dihapus permanen.");

        return self::SUCCESS;
    }
}
