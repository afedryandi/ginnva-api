<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'city',
        'address',
        'phone',
        'opening_hours',
        'latitude',
        'longitude',
        'maps_url',
        'google_place_id',
        'is_active',
        'install_capacity_per_day',
        'attendance_radius_meters',
        'late_tolerance_minutes',
        'late_deduction_amount',
    ];

    protected $casts = [
        'latitude'      => 'float',
        'longitude'     => 'float',
        'is_active'     => 'boolean',
        'install_capacity_per_day' => 'integer',
        'attendance_radius_meters' => 'integer',
        'late_tolerance_minutes'   => 'integer',
        'late_deduction_amount'    => 'decimal:2',
        // Array baris {days: string[], open, close, closed} — lihat
        // migration 2026_07_29_084638 & DAY_LABELS di bawah.
        'opening_hours' => 'array',
    ];

    // Urutan & label hari (kode day dipakai di kolom opening_hours,
    // sekaligus dipakai buat expand ke schema.org openingHoursSpecification
    // di StoreController). Kode 2-huruf (mo/tu/we/...) sesuai standar
    // schema.org DayOfWeek biar bisa dipakai langsung tanpa mapping lagi.
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const DAY_LABELS = [
        'mon' => 'Senin',
        'tue' => 'Selasa',
        'wed' => 'Rabu',
        'thu' => 'Kamis',
        'fri' => 'Jumat',
        'sat' => 'Sabtu',
        'sun' => 'Minggu',
    ];

    public const DAY_LABELS_SHORT = [
        'mon' => 'Sen',
        'tue' => 'Sel',
        'wed' => 'Rab',
        'thu' => 'Kam',
        'fri' => 'Jum',
        'sat' => 'Sab',
        'sun' => 'Min',
    ];

    /**
     * "Senin–Jumat 08:30–17:00" untuk beberapa hari BERURUTAN dengan jam
     * sama, atau "Senin 08:30–17:00" kalau cuma 1 hari — dipakai label
     * tiap baris/grup di form Filament (Repeater) supaya admin tahu
     * grup mana yang sedang diedit tanpa buka baris satu-satu.
     */
    public static function formatDayRange(array $days): string
    {
        $ordered = collect(self::DAYS)->filter(fn ($d) => in_array($d, $days, true))->values();

        if ($ordered->isEmpty()) return '';
        if ($ordered->count() === 1) return self::DAY_LABELS[$ordered->first()];

        // Cek apakah hari-hari yang dipilih berurutan persis di kalender
        // (mis. mon,tue,wed) — kalau ya, tampilkan ringkas "Senin–Rabu".
        // Kalau tidak berurutan (mis. mon,wed cuma pilih 2 hari lompat),
        // tampilkan gabungan dengan koma supaya tidak menyesatkan.
        $indexes = $ordered->map(fn ($d) => array_search($d, self::DAYS, true));
        $isConsecutive = $indexes->values()->all() === range($indexes->first(), $indexes->last());

        if ($isConsecutive) {
            return self::DAY_LABELS[$ordered->first()] . '–' . self::DAY_LABELS[$ordered->last()];
        }

        return $ordered->map(fn ($d) => self::DAY_LABELS_SHORT[$d])->implode(', ');
    }

    /**
     * 1 baris teks per grup hari — "Senin–Jumat 08.30–17.00", "Sabtu
     * 09.00–13.00", "Minggu Libur". Dipakai getOpeningHoursSummaryAttribute
     * (gabungan koma, untuk teks pendek) DAN getOpeningHoursLinesAttribute
     * (array asli, untuk web/mobile render per baris) — SENGAJA dipecah
     * jadi array dulu, tidak langsung digabung dengan koma lalu di-split
     * lagi oleh frontend, karena label hari non-berurutan (formatDayRange)
     * sendiri juga bisa mengandung koma ("Sen, Rab") yang bikin split
     * naif salah potong baris.
     */
    public function getOpeningHoursLinesAttribute(): array
    {
        if (empty($this->opening_hours)) return [];

        return collect($this->opening_hours)
            ->map(function ($row) {
                $label = self::formatDayRange($row['days'] ?? []);
                if (! empty($row['closed'])) {
                    return "{$label} Libur";
                }

                return "{$label} " . self::formatTime($row['open'] ?? null) . '–' . self::formatTime($row['close'] ?? null);
            })
            ->values()
            ->all();
    }

    /**
     * "08.30" dari "08:30" ATAU "08:30:00" — dibuat lewat Carbon::parse()
     * (bukan str_replace(':', '.', ...) langsung) supaya tidak bergantung
     * pada asumsi format persis yang disimpan Filament TimePicker (H:i vs
     * H:i:s), dan tidak error kalau nilainya kosong/null (baris rusak,
     * mis. diisi lewat cara lain di luar form Filament yang validasinya
     * mewajibkan open/close terisi).
     */
    private static function formatTime(?string $time): string
    {
        if (! $time) return '?';

        try {
            return \Illuminate\Support\Carbon::parse($time)->format('H.i');
        } catch (\Throwable $e) {
            return str_replace(':', '.', $time);
        }
    }

    /**
     * Ringkasan 1 baris untuk tampilan yang butuh teks pendek/inline (mis.
     * kolom tabel Filament) — "Senin–Jumat 08.30–17.00, Sabtu 09.00–13.00,
     * Minggu Libur".
     */
    public function getOpeningHoursSummaryAttribute(): ?string
    {
        $lines = $this->opening_hours_lines;

        return empty($lines) ? null : implode(', ', $lines);
    }

    /**
     * Expand ke format schema.org OpeningHoursSpecification (dipakai
     * DealersList.tsx untuk JSON-LD AutomotiveBusiness) — 1 entry per
     * BARIS (bukan per hari), Google/AI search yang parse yang expand
     * sendiri dayOfWeek array-nya.
     */
    public function getOpeningHoursSchemaAttribute(): array
    {
        if (empty($this->opening_hours)) return [];

        return collect($this->opening_hours)
            ->filter(fn ($row) => empty($row['closed']))
            ->map(fn ($row) => [
                '@type'    => 'OpeningHoursSpecification',
                'dayOfWeek' => collect($row['days'] ?? [])
                    ->map(fn ($d) => match ($d) {
                        'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday',
                        'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
                        default => $d,
                    })
                    ->values(),
                'opens'  => $row['open'] ?? null,
                'closes' => $row['close'] ?? null,
            ])
            ->values()
            ->all();
    }

    protected $appends = ['opening_hours_summary', 'opening_hours_lines', 'opening_hours_schema'];

    /**
     * Toko tutup di tanggal $date? Dipakai validasi booking customer
     * (BookingController) DAN perhitungan rentang tanggal/kapasitas slot
     * instalasi (Booking::nthWorkingDay(), fullDatesInRange()) — hari
     * libur toko tidak pernah dihitung sebagai hari kerja instalasi. Cek
     * 2 sumber: libur RUTIN mingguan (opening_hours, mis. Minggu) DAN
     * tanggal yang di-block manual satu kali (BlockedDate, mis. cuti
     * bersama yang jatuh di hari kerja biasa).
     *
     * format('D') Carbon selalu 3-huruf Inggris ("Mon".."Sun") — cocok
     * langsung (di-lowercase) dengan kode day di opening_hours/DAYS di
     * atas, tidak perlu tabel mapping manual terpisah.
     *
     * $this->blockedDates di-lazy-load SEKALI lalu di-cache Eloquent per
     * instance Store — dipanggil berkali-kali (loop harian di
     * Booking::fullDatesInRange()/nthWorkingDay()) TIDAK query ulang ke
     * DB tiap panggilan, selama pakai instance Store yang sama.
     */
    public function isClosedOn(\Illuminate\Support\Carbon $date): bool
    {
        $dayCode = strtolower($date->format('D'));

        $weeklyClosed = collect($this->opening_hours ?? [])
            ->filter(fn ($row) => ! empty($row['closed']))
            ->flatMap(fn ($row) => $row['days'] ?? [])
            ->contains($dayCode);

        if ($weeklyClosed) {
            return true;
        }

        return $this->blockedDates->contains(fn (BlockedDate $b) => $b->date->isSameDay($date));
    }

    /**
     * Jam buka toko (format "HH:MM") di hari $date, atau null kalau toko
     * tutup/tidak ada jadwal untuk hari itu — dipakai Attendance sebagai
     * acuan "jam masuk yang diharapkan" untuk hitung telat. TIDAK
     * membedakan shift per karyawan, cuma per toko (cukup untuk Fase 1).
     */
    public function openingTimeOn(\Illuminate\Support\Carbon $date): ?string
    {
        if ($this->isClosedOn($date)) {
            return null;
        }

        $dayCode = strtolower($date->format('D'));

        $row = collect($this->opening_hours ?? [])
            ->first(fn ($row) => empty($row['closed']) && in_array($dayCode, $row['days'] ?? []));

        return $row['open'] ?? null;
    }

    /**
     * Sama seperti openingTimeOn() tapi untuk jam TUTUP — dipakai
     * Attendance buat hitung pulang cepat (early_leave_minutes).
     */
    public function closingTimeOn(\Illuminate\Support\Carbon $date): ?string
    {
        if ($this->isClosedOn($date)) {
            return null;
        }

        $dayCode = strtolower($date->format('D'));

        $row = collect($this->opening_hours ?? [])
            ->first(fn ($row) => empty($row['closed']) && in_array($dayCode, $row['days'] ?? []));

        return $row['close'] ?? null;
    }

    /**
     * Jarak (meter) dari titik $lat/$lng ke toko ini — Haversine, cukup
     * akurat untuk cek "absen dari dekat toko" (bukan navigasi presisi
     * tinggi). Null kalau toko belum punya koordinat tersimpan (tidak bisa
     * dinilai jaraknya sama sekali, BUKAN dianggap 0 meter).
     */
    public function distanceMetersTo(float $lat, float $lng): ?float
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        $earthRadiusMeters = 6371000;

        $latDelta = deg2rad($lat - $this->latitude);
        $lngDelta = deg2rad($lng - $this->longitude);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($this->latitude)) * cos(deg2rad($lat)) * sin($lngDelta / 2) ** 2;

        return $earthRadiusMeters * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function blockedDates()
    {
        return $this->hasMany(BlockedDate::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function warranties()
    {
        return $this->hasMany(Warranty::class);
    }

    public function quotations()
    {
        return $this->hasMany(Quotation::class);
    }

    public function technicians()
    {
        return $this->hasMany(Technician::class);
    }
}