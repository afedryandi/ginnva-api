<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\BlockedDate;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Riwayat absensi tanpa tanggal yang lompat (2026-11-24): tiap tanggal dari awal bulan (atau tanggal masuk kerja) sampai hari ini muncul satu
 * baris. Hari yang punya catatan absensi tampil seperti biasa; hari tanpa catatan diberi keterangan: Libur (libur mingguan toko atau tanggal
 * yang ditutup di Kalender Kapasitas & Libur, lengkap dengan alasannya), Belum absen (hari ini), atau Tanpa catatan.
 *
 * Dipakai bersama oleh API aplikasi karyawan (GET /api/staff/attendance/history) dan tab Riwayat di Absen Saya (panel).
 */
class AttendanceCalendar
{
    public const KIND_RECORD = 'record';

    public const KIND_HOLIDAY = 'libur';

    public const KIND_PENDING = 'belum';

    public const KIND_EMPTY = 'kosong';

    /**
     * @return Collection<int, array{date: Carbon, kind: string, label: string|null, attendance: Attendance|null}> terbaru dulu
     */
    public function days(User $user, Carbon $month): Collection
    {
        $first = $month->copy()->startOfMonth()->startOfDay();
        $today = today();
        $to = $month->copy()->endOfMonth()->startOfDay();

        if ($to->gt($today)) {
            $to = $today->copy();
        }

        $from = $first->copy();

        // Sebelum tanggal masuk kerja tidak ada kewajiban absen, jadi tidak ditampilkan.
        if ($user->join_date && $user->join_date->copy()->startOfDay()->gt($from)) {
            $from = $user->join_date->copy()->startOfDay();
        }

        if ($from->gt($to)) {
            return collect();
        }

        $records = Attendance::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Attendance $a) => $a->date->toDateString());

        $store = $user->store_id ? Store::withoutGlobalScopes()->find($user->store_id) : null;
        $weeklyClosed = collect($store?->opening_hours ?? [])
            ->filter(fn ($row) => ! empty($row['closed']))
            ->flatMap(fn ($row) => $row['days'] ?? [])
            ->map(fn ($d) => strtolower((string) $d))
            ->all();

        $blocked = $store
            ? BlockedDate::withoutGlobalScopes()
                ->where(fn ($q) => $q->where('store_id', $store->id)->orWhereNull('store_id'))
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->get()
                ->keyBy(fn (BlockedDate $b) => $b->date->toDateString())
            : collect();

        $days = collect();

        for ($date = $to->copy(); $date->gte($from); $date->subDay()) {
            $key = $date->toDateString();
            $current = $date->copy();

            if ($record = $records->get($key)) {
                $days->push(['date' => $current, 'kind' => self::KIND_RECORD, 'label' => null, 'attendance' => $record]);

                continue;
            }

            if ($closure = $blocked->get($key)) {
                $days->push(['date' => $current, 'kind' => self::KIND_HOLIDAY, 'label' => filled($closure->reason) ? (string) $closure->reason : 'Toko libur', 'attendance' => null]);

                continue;
            }

            if (in_array(strtolower($date->format('D')), $weeklyClosed, true)) {
                $days->push(['date' => $current, 'kind' => self::KIND_HOLIDAY, 'label' => 'Libur mingguan toko (' . $date->translatedFormat('l') . ')', 'attendance' => null]);

                continue;
            }

            if ($date->isSameDay($today)) {
                $days->push(['date' => $current, 'kind' => self::KIND_PENDING, 'label' => 'Belum ada absen hari ini', 'attendance' => null]);

                continue;
            }

            $days->push(['date' => $current, 'kind' => self::KIND_EMPTY, 'label' => 'Tidak ada catatan absensi', 'attendance' => null]);
        }

        return $days;
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_HOLIDAY => 'Libur',
            self::KIND_PENDING => 'Belum absen',
            self::KIND_EMPTY => 'Tanpa catatan',
            default => '',
        };
    }
}
