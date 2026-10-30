<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingQcResult;
use App\Models\BookingRework;
use App\Models\Technician;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Mutu pekerjaan per installer untuk PEMBINAAN (Karyawan Fase 4, 2026-10-31): dari booking selesai pada rentang tanggal, berapa yang
 * lulus Quality Check pada pemeriksaan pertama dan berapa yang pernah gagal QC (dianggap perlu dikerjakan ulang). Bukan dasar potongan
 * gaji atau komisi (keputusan rancangan: mutu hanya untuk pembinaan). Pada booking tim, tiap installer bertugas dihitung sama.
 *
 * Catatan angka: sampel kecil menyesatkan, jadi laporan menampilkan jumlah pekerjaan di samping persentase, dan persentase baru dihitung
 * dari pekerjaan yang sudah diperiksa QC.
 */
class InstallerQualityService
{
    /** Jumlah pekerjaan diperiksa QC di bawah ini dianggap sampel kecil: persentasenya belum bisa dijadikan patokan. */
    public const SMALL_SAMPLE = 5;

    /**
     * Rincian booking selesai milik satu installer pada periode itu (aturan sama dengan forPeriod()), yang perlu perhatian (QC pertama gagal,
     * pernah gagal, atau ada catatan pekerjaan ulang) di urutan atas.
     *
     * @return Collection<int, array{booking_id: int, booking_number: ?string, date: Carbon, qc: string, reworks: list<string>, attention: bool}>
     */
    public function jobsFor(int $userId, Carbon $from, Carbon $to, ?int $storeId = null): Collection
    {
        $bookings = Booking::query()
            ->where('status', 'completed')
            ->whereDate('preferred_date', '>=', $from->toDateString())
            ->whereDate('preferred_date', '<=', $to->toDateString())
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->whereHas('installers', fn ($q) => $q->where('users.id', $userId))
            ->get(['id', 'booking_number', 'preferred_date']);

        if ($bookings->isEmpty()) {
            return collect();
        }

        $results = BookingQcResult::query()->whereIn('booking_id', $bookings->pluck('id'))->orderBy('id')->get(['id', 'booking_id', 'passed'])->groupBy('booking_id');
        $reworks = BookingRework::withoutGlobalScopes()->whereIn('booking_id', $bookings->pluck('id'))->orderBy('id')->get(['booking_id', 'source', 'reason'])->groupBy('booking_id');

        return $bookings->map(function (Booking $booking) use ($results, $reworks) {
            $checks = $results->get($booking->id, collect());
            $firstFailed = $checks->isNotEmpty() && ! $checks->first()->passed;
            $everFailed = $checks->contains(fn ($r) => ! $r->passed);
            $notes = $reworks->get($booking->id, collect())
                ->map(fn ($r) => (BookingRework::SOURCES[$r->source] ?? $r->source) . ': ' . $r->reason)->values()->all();

            return [
                'booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'date' => Carbon::parse($booking->preferred_date),
                'qc' => $checks->isEmpty() ? 'Belum diperiksa' : ($firstFailed ? 'Gagal pada pemeriksaan pertama' : ($everFailed ? 'Lulus (pernah gagal)' : 'Lulus pertama')),
                'reworks' => $notes,
                'attention' => $everFailed || $notes !== [],
            ];
        })
            ->sortBy([['attention', 'desc'], ['date', 'desc']])
            ->values();
    }

    /**
     * @return Collection<int, array{user_id: int, name: string, store: ?string, is_active: bool, jobs: int, checked: int, first_pass: int, reworked: int, rework_recorded: int, first_pass_rate: ?float, small_sample: bool}>
     */
    public function forPeriod(Carbon $from, Carbon $to, ?int $storeId = null): Collection
    {
        $bookings = Booking::query()
            ->where('status', 'completed')
            ->whereDate('preferred_date', '>=', $from->toDateString())
            ->whereDate('preferred_date', '<=', $to->toDateString())
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->whereHas('installers')
            ->with('installers:id,name')
            ->get(['id', 'store_id', 'preferred_date', 'status']);

        if ($bookings->isEmpty()) {
            return collect();
        }

        $results = BookingQcResult::query()
            ->whereIn('booking_id', $bookings->pluck('id'))
            ->orderBy('id')
            ->get(['id', 'booking_id', 'passed'])
            ->groupBy('booking_id');

        // Booking dengan catatan Pekerjaan Ulang (di luar QC gagal): keluhan pelanggan dan temuan internal ikut terhitung.
        $reworkBookingIds = BookingRework::withoutGlobalScopes()->whereIn('booking_id', $bookings->pluck('id'))->pluck('booking_id')->flip();

        // Hanya installer terdaftar (punya data teknisi): akun lain yang kebetulan ditugaskan di booking, mis. store manager, tidak ikut.
        // Installer nonaktif ikut bila punya pekerjaan di periode ini dan diberi penanda, supaya laporan lama tetap lengkap.
        $technicians = Technician::query()
            ->whereIn('user_id', $bookings->flatMap(fn (Booking $b) => $b->installers->pluck('id'))->unique()->all())
            ->with('store:id,name')
            ->get()
            ->keyBy('user_id');

        $rows = [];

        foreach ($bookings as $booking) {
            $checks = $results->get($booking->id, collect());
            $hasQc = $checks->isNotEmpty();
            $firstPassed = $hasQc && $checks->first()->passed;
            $everFailed = $checks->contains(fn ($r) => ! $r->passed);

            foreach ($booking->installers as $installer) {
                $technician = $technicians->get($installer->id);

                if (! $technician) {
                    continue;
                }

                $row = $rows[$installer->id] ?? [
                    'user_id' => $installer->id,
                    'name' => $technician->name,
                    'store' => $technician->store?->name,
                    'is_active' => $technician->status !== 'inactive',
                    'jobs' => 0, 'checked' => 0, 'first_pass' => 0, 'reworked' => 0, 'rework_recorded' => 0,
                ];

                $row['jobs']++;
                $row['checked'] += $hasQc ? 1 : 0;
                $row['first_pass'] += $firstPassed ? 1 : 0;
                $row['reworked'] += $everFailed ? 1 : 0;
                $row['rework_recorded'] += $reworkBookingIds->has($booking->id) ? 1 : 0;
                $rows[$installer->id] = $row;
            }
        }

        return collect($rows)
            ->map(fn (array $row) => $row + [
                'first_pass_rate' => $row['checked'] > 0 ? round($row['first_pass'] / $row['checked'] * 100, 1) : null,
                'small_sample' => $row['checked'] > 0 && $row['checked'] < self::SMALL_SAMPLE,
            ])
            ->sortBy('name')
            ->values();
    }
}
