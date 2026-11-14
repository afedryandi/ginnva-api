<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hapus Alpha satu tanggal yang ternyata hari libur (2026-11-11). Alpha dibuat tiap dini hari untuk KEMARIN (MarkAbsences), sedangkan Kalender
 * Kapasitas & Libur tidak bisa menutup tanggal yang sudah lewat; jadi bila libur lupa ditetapkan, Alpha-nya sudah terbentuk dan tidak ada
 * jalan lain selain menghapus baris satu per satu. Layanan ini menghapus HANYA baris berjenis alpha pada satu tanggal (satu toko atau semua),
 * dibatasi ke tanggal kemarin atau sebelumnya dalam {@see self::MAX_DAYS_BACK} hari, dan mencatat ringkasannya di Riwayat Aktivitas.
 */
class AlphaCleanupService
{
    public const MAX_DAYS_BACK = 45;

    /** Alpha yang akan terhapus untuk tanggal (dan toko, null = semua toko). */
    public function candidates(Carbon|string $date, ?int $storeId = null): Builder
    {
        return Attendance::query()
            ->where('entry_type', 'alpha')
            ->whereDate('date', Carbon::parse($date)->toDateString())
            ->when($storeId, fn (Builder $q) => $q->where('store_id', $storeId));
    }

    /**
     * @return int jumlah baris Alpha yang dihapus
     *
     * @throws \RuntimeException bila tanggal tidak boleh dibersihkan atau alasan kosong
     */
    public function remove(Carbon|string $date, ?int $storeId, string $reason, ?User $by = null): int
    {
        $day = Carbon::parse($date)->startOfDay();
        $reason = trim($reason);

        if ($reason === '') {
            throw new \RuntimeException('Alasan wajib diisi.');
        }

        if ($day->gte(today())) {
            throw new \RuntimeException('Hanya tanggal kemarin atau sebelumnya yang bisa dibersihkan. Untuk hari ini, tutup tanggalnya di Kalender Kapasitas & Libur.');
        }

        if ($day->lt(today()->subDays(self::MAX_DAYS_BACK))) {
            throw new \RuntimeException('Paling jauh ' . self::MAX_DAYS_BACK . ' hari ke belakang.');
        }

        return DB::transaction(function () use ($day, $storeId, $reason, $by) {
            $rows = $this->candidates($day, $storeId)->lockForUpdate()->get();

            if ($rows->isEmpty()) {
                return 0;
            }

            // Dihapus lewat model (bukan delete massal) supaya jejak 'deleted' per baris tetap tercatat.
            $rows->each->delete();

            activity('attendance_alpha_cleanup')
                ->causedBy($by)
                ->withProperties([
                    'date' => $day->toDateString(),
                    'store_id' => $storeId,
                    'count' => $rows->count(),
                    'user_ids' => $rows->pluck('user_id')->values()->all(),
                    'reason' => $reason,
                ])
                ->log('Alpha tanggal ' . $day->format('d M Y') . ' dihapus (' . $rows->count() . ' baris): ' . $reason);

            return $rows->count();
        });
    }
}
