<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * f18 "Metrik Utilisasi Bay/Stall Instalasi" (audit Majoo vs Ginnva,
 * disetujui pemilik bisnis 2026-09-24) — menghitung utilisasi 2 ZONA
 * FISIK toko dari durasi NYATA tiap booking menempati tiap tahap,
 * direkonstruksi dari activity_log (Booking::current_stage/secondary_stage
 * di-log via LogsActivity, lihat Booking::getActivitylogOptions()).
 *
 * BEDA dengan App\Filament\Pages\ReservationUtilizationReport: laporan
 * itu 1 angka kapasitas AGREGAT per toko (install_capacity_per_day) dan
 * cuma menghitung JUMLAH booking yang overlap per hari (bukan durasi
 * tahap sungguhan) karena Ginnva memang tidak punya entitas bay/stall
 * individual. Laporan zona ini TETAP tidak melacak bay/stall INDIVIDUAL
 * (mis. "Bay 1", "Bay 2") — tetap 1 angka slot per ZONA per toko — tapi
 * bedanya durasi okupansi dihitung dari timestamp transisi tahap
 * sungguhan (bukan diasumsikan/rata-rata), karena durasi tiap tahap
 * SANGAT bervariasi (ukuran mobil, tingkat kotor, dll — pemilik bisnis
 * eksplisit bilang ini tidak bisa diestimasi).
 *
 * Zona fisik & tahap penyusunnya (konfirmasi pemilik bisnis 2026-09-24):
 * - Zona Detailing & Persiapan: ppf_washing, kf_cleaning, kf_heating,
 *   ppf_detailing — semua dikerjakan di area fisik yang sama.
 * - Zona Instalasi & QC: kf_installation, ppf_installation, qc.
 *
 * Stage yang TIDAK masuk zona manapun (mis. 'completed', atau nilai
 * lama/tidak dikenal) otomatis dibuang dari perhitungan interval.
 */
class BayZoneUtilizationService
{
    public const ZONE_DETAILING = 'detailing';

    public const ZONE_INSTALASI_QC = 'instalasi_qc';

    public const ZONE_STAGES = [
        self::ZONE_DETAILING => [
            'ppf_washing',
            'kf_cleaning',
            'kf_heating',
            'ppf_detailing',
        ],
        self::ZONE_INSTALASI_QC => [
            'kf_installation',
            'ppf_installation',
            'qc',
        ],
    ];

    public const ZONE_LABELS = [
        self::ZONE_DETAILING => 'Zona Detailing & Persiapan',
        self::ZONE_INSTALASI_QC => 'Zona Instalasi & QC',
    ];

    private const FINAL_STATUSES = ['completed', 'cancelled'];

    /**
     * @return array<string, array{
     *   label: string,
     *   configured: bool,
     *   slotCount: ?int,
     *   bookingCount: int,
     *   availableHours: float,
     *   occupiedHours: float,
     *   utilizationPct: float,
     *   peakConcurrent: int,
     * }>
     */
    public function summarize(Store $store, Carbon $from, Carbon $to): array
    {
        $slotCounts = [
            self::ZONE_DETAILING => $store->detailing_slot_count,
            self::ZONE_INSTALASI_QC => $store->instalasi_qc_slot_count,
        ];

        // Toko yang sama sekali belum diisi slot manapun -> kedua zona
        // "belum dikonfigurasi", tidak perlu tarik data booking sama
        // sekali (hemat query kalau memang laporan tidak bisa ditampilkan).
        if ($slotCounts[self::ZONE_DETAILING] === null && $slotCounts[self::ZONE_INSTALASI_QC] === null) {
            return $this->unconfiguredResult($slotCounts);
        }

        $intervalsByZone = $this->buildZoneIntervals($store, $from, $to);
        $availableHours = $this->availableHoursInRange($store, $from, $to);

        $result = [];

        foreach (self::ZONE_STAGES as $zone => $stages) {
            $slotCount = $slotCounts[$zone];

            if ($slotCount === null) {
                $result[$zone] = [
                    'label' => self::ZONE_LABELS[$zone],
                    'configured' => false,
                    'slotCount' => null,
                    'bookingCount' => 0,
                    'availableHours' => 0.0,
                    'occupiedHours' => 0.0,
                    'utilizationPct' => 0.0,
                    'peakConcurrent' => 0,
                ];

                continue;
            }

            $intervals = $intervalsByZone[$zone] ?? collect();

            $occupiedHours = $intervals->sum(fn (array $i) => $i['end']->floatDiffInHours($i['start']));
            $bookingCount = $intervals->pluck('booking_id')->unique()->count();
            $peakConcurrent = $this->peakConcurrent($intervals);
            $zoneAvailableHours = $availableHours * $slotCount;

            $result[$zone] = [
                'label' => self::ZONE_LABELS[$zone],
                'configured' => true,
                'slotCount' => $slotCount,
                'bookingCount' => $bookingCount,
                'availableHours' => $zoneAvailableHours,
                'occupiedHours' => $occupiedHours,
                'utilizationPct' => $zoneAvailableHours > 0 ? min(100, $occupiedHours / $zoneAvailableHours * 100) : 0.0,
                'peakConcurrent' => $peakConcurrent,
            ];
        }

        return $result;
    }

    private function unconfiguredResult(array $slotCounts): array
    {
        $result = [];

        foreach (self::ZONE_STAGES as $zone => $stages) {
            $result[$zone] = [
                'label' => self::ZONE_LABELS[$zone],
                'configured' => false,
                'slotCount' => $slotCounts[$zone],
                'bookingCount' => 0,
                'availableHours' => 0.0,
                'occupiedHours' => 0.0,
                'utilizationPct' => 0.0,
                'peakConcurrent' => 0,
            ];
        }

        return $result;
    }

    /**
     * Jam tersedia TOTAL (belum dikali jumlah slot) dalam [$from, $to] —
     * dihitung per hari kalender, hari tutup (Store::isClosedOn()) tidak
     * dihitung sama sekali (persis pola ReservationUtilizationReport,
     * supaya konsisten: hari toko libur bukan "kapasitas kosong
     * terbuang"). Hari buka pakai openingTimeOn()/closingTimeOn() kalau
     * ada jadwal jelas; kalau toko tidak isi jam spesifik untuk hari itu
     * (opening_hours kosong/tidak match), dianggap 24 jam penuh — sama
     * "tidak menganggap tutup" seperti isClosedOn() default false.
     */
    private function availableHoursInRange(Store $store, Carbon $from, Carbon $to): float
    {
        $hours = 0.0;
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lte($end)) {
            if (! $store->isClosedOn($cursor)) {
                $open = $store->openingTimeOn($cursor);
                $close = $store->closingTimeOn($cursor);

                if ($open && $close) {
                    $openAt = Carbon::parse($cursor->toDateString() . ' ' . $open);
                    $closeAt = Carbon::parse($cursor->toDateString() . ' ' . $close);
                    $dayStart = $cursor->copy()->startOfDay();
                    $dayEnd = $cursor->copy()->endOfDay();
                    $hours += max(0, $openAt->max($dayStart)->floatDiffInHours($closeAt->min($dayEnd->addSecond())));
                } else {
                    $hours += 24;
                }
            }

            $cursor->addDay();
        }

        return $hours;
    }

    /**
     * @return array<string, Collection<int, array{booking_id:int, start:Carbon, end:Carbon}>>
     */
    private function buildZoneIntervals(Store $store, Carbon $from, Carbon $to): array
    {
        // Booking dengan AKTIVITAS apa pun (current_stage/secondary_stage)
        // di dalam window — dipakai untuk menentukan SET booking yang
        // relevan, tapi begitu ketemu, histori log yang ditarik untuk
        // booking itu FULL sejak awal (bukan cuma yang jatuh di window),
        // supaya kita tahu tahap apa yang sudah dijalani booking itu
        // SEBELUM $from (booking bisa saja sudah masuk zona sebelum
        // periode laporan dimulai).
        $bookingIds = Activity::query()
            ->where('subject_type', Booking::class)
            ->where(function ($q) {
                $q->whereNotNull('properties->attributes->current_stage')
                    ->orWhereNotNull('properties->attributes->secondary_stage');
            })
            ->whereBetween('created_at', [$from, $to])
            ->whereHasMorph('subject', [Booking::class], fn ($q2) => $q2->where('store_id', $store->id))
            ->get()
            ->pluck('subject_id')
            ->unique()
            ->values();

        if ($bookingIds->isEmpty()) {
            return [];
        }

        $bookings = Booking::query()
            ->whereIn('id', $bookingIds)
            ->get(['id', 'store_id', 'status', 'created_at', 'updated_at', 'product_kaca_film', 'product_ppf', 'current_stage', 'secondary_stage']);

        // Histori LENGKAP (dari awal booking, bukan cuma window) untuk tiap
        // booking yang relevan — dibutuhkan untuk merekonstruksi timeline
        // penuh (lihat kelas comment).
        $fullLogs = Activity::query()
            ->where('subject_type', Booking::class)
            ->whereIn('subject_id', $bookingIds)
            ->where(function ($q) {
                $q->whereNotNull('properties->attributes->current_stage')
                    ->orWhereNotNull('properties->attributes->secondary_stage');
            })
            ->orderBy('created_at')
            ->get(['subject_id', 'created_at', 'properties'])
            ->groupBy('subject_id');

        $stageToZone = [];
        foreach (self::ZONE_STAGES as $zone => $stages) {
            foreach ($stages as $stage) {
                $stageToZone[$stage] = $zone;
            }
        }

        $intervalsByZone = [
            self::ZONE_DETAILING => collect(),
            self::ZONE_INSTALASI_QC => collect(),
        ];

        foreach ($bookings as $booking) {
            $hasBothProducts = (bool) $booking->product_kaca_film && (bool) $booking->product_ppf;
            $logs = $fullLogs->get($booking->id, collect());

            foreach (['current_stage', 'secondary_stage'] as $column) {
                // secondary_stage cuma relevan kalau booking pesan 2 produk
                // sekaligus (lihat Booking::stageColumnFor()) — track yang
                // tidak relevan dilewati supaya tidak menghasilkan interval
                // palsu dari nilai NULL/basi kolom itu.
                if ($column === 'secondary_stage' && ! $hasBothProducts) {
                    continue;
                }

                $timeline = $this->buildTrackTimeline($booking, $column, $logs);
                $this->appendZoneIntervals($intervalsByZone, $stageToZone, $booking, $timeline, $from, $to);
            }
        }

        return $intervalsByZone;
    }

    /**
     * Rekonstruksi urutan (stage, entered_at) untuk 1 track (current_stage
     * atau secondary_stage) dari log activity milik 1 booking.
     *
     * PENDEKATAN untuk "tahap pertama" (APPROXIMATION, didokumentasikan
     * karena tidak ada cara pasti tahu kapan persis stage awal itu
     * dimulai kalau log-nya tidak lengkap): logOnlyDirty berarti entry
     * paling awal di log untuk track ini SUDAH BERUPA transisi (dari
     * suatu nilai sebelumnya ke nilai baru) — bukan "nilai awal saat
     * booking dibuat". Kalau track ini punya nilai stage yang valid
     * (current/secondary_stage sekarang tidak null & termasuk salah satu
     * zona) TAPI tidak ada log SAMA SEKALI untuk track itu, ATAU log
     * paling awal untuk track itu sendiri bukan stage "pertama" secara
     * eksplisit (kita tidak tahu stage sebelumnya) — maka kita anggap
     * booking SUDAH berada di stage pertama yang diketahui itu SEJAK
     * booking dibuat (created_at). Ini estimasi yang WAJAR dipakai untuk
     * booking lama yang transisi awalnya terjadi sebelum logging aktif
     * atau tidak tercatat bersih, tapi bisa sedikit MELEBIHKAN durasi
     * okupansi zona pertama booking tsb dibanding kenyataan.
     *
     * @return Collection<int, array{stage: ?string, entered_at: Carbon}>
     */
    private function buildTrackTimeline(Booking $booking, string $column, Collection $logs): Collection
    {
        $transitions = collect();

        foreach ($logs as $log) {
            $attributes = data_get($log->properties, 'attributes', []);

            if (! array_key_exists($column, $attributes)) {
                continue;
            }

            $transitions->push([
                'stage' => $attributes[$column],
                'entered_at' => Carbon::parse($log->created_at),
            ]);
        }

        $currentValue = $booking->{$column};

        if ($transitions->isEmpty()) {
            // Tidak ada log SAMA SEKALI untuk track ini — kalau kolomnya
            // toh punya nilai stage yang valid sekarang, anggap sudah di
            // stage itu sejak booking dibuat (lihat dokblok method ini).
            if ($currentValue !== null) {
                $transitions->push([
                    'stage' => $currentValue,
                    'entered_at' => Carbon::parse($booking->created_at),
                ]);
            }

            return $transitions;
        }

        // Entry paling awal di log ITU SENDIRI adalah transisi MENUJU
        // suatu stage (bukan "stage pertama" murni) — perlakukan
        // timestamp-nya sebagai kapan booking MASUK ke stage tsb tetap
        // valid (itu memang saat kolom berubah jadi nilai itu), jadi
        // tidak perlu koreksi tambahan di sini; komentar dokblok di atas
        // menjelaskan kenapa ini pendekatan yang wajar dipakai (bukan
        // "stage sebelum stage pertama yang tercatat" yang memang tidak
        // pernah kita ketahui).
        return $transitions->sortBy('entered_at')->values();
    }

    /**
     * @param array<string, Collection> $intervalsByZone
     * @param array<string, string> $stageToZone
     */
    private function appendZoneIntervals(array &$intervalsByZone, array $stageToZone, Booking $booking, Collection $timeline, Carbon $from, Carbon $to): void
    {
        $isFinal = in_array($booking->status, self::FINAL_STATUSES, true);
        $naturalEnd = $isFinal ? Carbon::parse($booking->updated_at) : Carbon::now();

        $timeline = $timeline->values();

        foreach ($timeline as $index => $entry) {
            $zone = $stageToZone[$entry['stage']] ?? null;

            if ($zone === null) {
                continue;
            }

            $start = $entry['entered_at'];
            $next = $timeline->get($index + 1);
            $end = $next ? $next['entered_at'] : $naturalEnd;

            if ($end->lte($start)) {
                continue;
            }

            $clippedStart = $start->max($from);
            $clippedEnd = $end->min($to);

            if ($clippedEnd->lte($clippedStart)) {
                continue;
            }

            $intervalsByZone[$zone]->push([
                'booking_id' => $booking->id,
                'start' => $clippedStart,
                'end' => $clippedEnd,
            ]);
        }
    }

    /**
     * Sweep-line sederhana: urutkan semua titik mulai/selesai, hitung
     * jumlah interval yang sedang berjalan bersamaan, ambil puncaknya.
     *
     * @param Collection<int, array{start:Carbon, end:Carbon}> $intervals
     */
    private function peakConcurrent(Collection $intervals): int
    {
        if ($intervals->isEmpty()) {
            return 0;
        }

        $events = collect();

        foreach ($intervals as $interval) {
            $events->push(['at' => $interval['start']->getTimestamp(), 'delta' => 1]);
            $events->push(['at' => $interval['end']->getTimestamp(), 'delta' => -1]);
        }

        // Urutan proses saat timestamp sama: 'selesai' (-1) DULUAN sebelum
        // 'mulai' (+1) supaya interval yang PAS bersambungan (end A ==
        // start B) tidak dihitung dobel sebagai overlap semu.
        $sorted = $events->sort(function ($a, $b) {
            if ($a['at'] === $b['at']) {
                return $a['delta'] <=> $b['delta'];
            }

            return $a['at'] <=> $b['at'];
        });

        $current = 0;
        $peak = 0;

        foreach ($sorted as $event) {
            $current += $event['delta'];
            $peak = max($peak, $current);
        }

        return $peak;
    }
}
