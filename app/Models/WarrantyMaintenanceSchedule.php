<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Bagian C, "Klaim Garansi & Maintenance PPF Terhubung ke Slot Booking"
 * (2026-10-01) -- SATU baris = SATU occurrence jadwal maintenance. Lihat
 * catatan lengkap alur di migrasi create_warranty_maintenance_schedules_table.
 *
 * LogsActivity dipasang (beda dari WarrantyMaintenanceVisit yang murni
 * ledger tanpa log) -- status di sini berubah OTOMATIS lewat sistem
 * (command harian, bukan cuma aksi staff), jadi staff butuh jejak "kenapa
 * occurrence ini hangus/kapan" yang bisa dilihat di Histori Aktivitas
 * tanpa harus tanya developer.
 */
class WarrantyMaintenanceSchedule extends Model
{
    use LogsActivity;

    /**
     * Masa toleransi (hari) setelah scheduled_date: customer masih boleh datang / konfirmasi dan jadwal belum
     * dihanguskan (keputusan 2026-10-09: tanggal kedatangan tidak perlu terlalu spesifik).
     */
    public const GRACE_DAYS = 30;

    protected $fillable = [
        'warranty_id',
        'sequence',
        'scheduled_date',
        'status',
        'booking_id',
        'reminder_sent_at',
        'responded_at',
    ];

    protected $casts = [
        'scheduled_date'   => 'date',
        'reminder_sent_at' => 'datetime',
        'responded_at'     => 'datetime',
    ];

    public function warranty()
    {
        return $this->belongsTo(Warranty::class);
    }

    /** Batas akhir toleransi (hari terakhir jadwal ini masih berlaku). */
    public function validUntil(): \Illuminate\Support\Carbon
    {
        return $this->scheduled_date->copy()->addDays(self::GRACE_DAYS);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Geser tanggal maju ke hari buka toko terdekat kalau hasil hitungan
     * interval jatuh di hari tutup/libur (diminta user 2026-10-01, supaya
     * customer tidak pernah mentok harus hubungi toko manual setiap siklus
     * -- lihat MaintenanceScheduleController::confirm() yang SEBELUMNYA
     * cuma menolak, sekarang jarang/tidak pernah ketemu tanggal tutup lagi
     * karena sudah digeser dari awal). Cap 14 hari -- jaga-jaga toko
     * dikonfigurasi tutup permanen/salah, supaya tidak looping tanpa akhir.
     */
    private static function nextOpenDate(Warranty $warranty, \Illuminate\Support\Carbon $date): \Illuminate\Support\Carbon
    {
        $date = $date->copy();
        $store = $warranty->store;

        if (! $store) {
            return $date;
        }

        for ($i = 0; $i < 14 && $store->isClosedOn($date); $i++) {
            $date->addDay();
        }

        return $date;
    }

    /**
     * Majukan tanggal sebesar kelipatan interval sampai TIDAK lagi di masa lalu. Tanpa ini garansi lama yang jadwalnya
     * baru diaktifkan (mis. dipasang 2 tahun lalu, interval 6 bulan) menghasilkan occurrence yang sudah lewat tanggal:
     * command harian menghanguskannya satu per hari, menghabiskan kuota tanpa pernah menawarkan maintenance ke customer,
     * lalu mengirim "Siklus Maintenance Berakhir". Siklus yang dilewati tidak memakan nomor urut (sequence).
     */
    private static function notBeforeToday(Warranty $warranty, \Illuminate\Support\Carbon $date): \Illuminate\Support\Carbon
    {
        $date = $date->copy();
        $interval = (int) $warranty->maintenance_interval_months;

        if ($interval < 1) {
            return $date;
        }

        for ($guard = 0; $date->lt(today()) && $guard < 240; $guard++) {
            $date->addMonths($interval);
        }

        return $date;
    }

    /**
     * Occurrence pertama (sequence=1) untuk warranty yang baru saja
     * diaktifkan maintenance-nya (maintenance_interval_months diisi). Tidak
     * ada apa-apa terjadi kalau warranty tidak punya installation_date atau
     * interval -- dipanggil staff lewat WarrantyResource, divalidasi di sana.
     */
    public static function createFirstFor(Warranty $warranty): self
    {
        return static::create([
            'warranty_id'     => $warranty->id,
            'sequence'        => 1,
            'scheduled_date'  => static::nextOpenDate(
                $warranty,
                static::notBeforeToday($warranty, $warranty->installation_date->copy()->addMonths($warranty->maintenance_interval_months))
            ),
            'status'          => 'pending',
        ]);
    }

    /**
     * Occurrence ini hangus (ditolak customer ATAU tanggal lewat tanpa
     * respons) -- otomatis siapkan occurrence berikutnya selama kuota belum
     * habis. $explicit = true kalau customer tap "Tolak" (responded_at
     * diisi); false kalau diproses command harian karena tanggal sudah
     * lewat tanpa respons sama sekali (responded_at tetap null -- customer
     * memang tidak merespons, beda dari menolak aktif).
     */
    public function forfeit(bool $explicit = false): void
    {
        DB::transaction(function () use ($explicit) {
            $locked = static::whereKey($this->id)->lockForUpdate()->first();

            // Sudah diproses request/run lain barengan -- jangan dobel.
            if ($locked->status === 'forfeited' || $locked->status === 'confirmed' || $locked->status === 'completed') {
                return;
            }

            $locked->update([
                'status'       => 'forfeited',
                'responded_at' => $explicit ? now() : $locked->responded_at,
            ]);

            // Reuse relasi warranty.store yang sudah di-eager-load caller
            // kalau ada (audit 2026-10-01, N+1) -- $locked hasil
            // whereKey()->first() di atas SELALU query baru (demi lock),
            // jadi relasinya sendiri tidak pernah ikut ter-eager-load dari
            // query caller di luar transaksi ini.
            $warranty = $this->relationLoaded('warranty') ? $this->warranty : $locked->warranty;

            if ($locked->sequence < $warranty->maintenance_quota && $warranty->maintenance_interval_months) {
                static::create([
                    'warranty_id'    => $warranty->id,
                    'sequence'       => $locked->sequence + 1,
                    'scheduled_date' => static::nextOpenDate(
                        $warranty,
                        static::notBeforeToday($warranty, $locked->scheduled_date->copy()->addMonths($warranty->maintenance_interval_months))
                    ),
                    'status'         => 'pending',
                ]);
            } elseif ($warranty->customer_id) {
                // Gap ditutup 2026-10-01 (audit Maintenance PPF) --
                // SEBELUMNYA tidak ada pemberitahuan apa pun ke customer
                // begitu siklus otomatis berhenti (occurrence ini hangus
                // DAN tidak ada occurrence berikutnya lagi) -- customer
                // diam-diam tidak pernah dapat apa-apa lagi tanpa penjelasan.
                app(\App\Services\PushNotificationService::class)->sendToCustomer(
                    $warranty->customer_id,
                    'Siklus Maintenance Berakhir',
                    "Siklus pengingat maintenance PPF untuk garansi #{$warranty->warranty_code} sudah berakhir. Hubungi toko langsung kalau masih butuh maintenance.",
                    ['type' => 'ppf_maintenance_ended', 'route' => "/account/warranty-detail?id={$warranty->id}"]
                );
            }
        });

        $this->refresh();
    }

    /**
     * Dipanggil saat Booking yang terhubung occurrence ini berstatus
     * 'completed' -- occurrence ini TUNTAS (beda dari forfeit: ini berhasil,
     * bukan gagal datang), siapkan occurrence berikutnya kalau kuota belum
     * habis. Pencatatan ke warranty_maintenance_visits (ledger historis)
     * dilakukan TERPISAH di titik integrasi "booking selesai", bukan di sini.
     */
    public function completeAndScheduleNext(): void
    {
        DB::transaction(function () {
            $locked = static::whereKey($this->id)->lockForUpdate()->first();

            if ($locked->status === 'completed') {
                return;
            }

            $locked->update(['status' => 'completed']);

            // Lihat catatan sama persis di forfeit() di atas.
            $warranty = $this->relationLoaded('warranty') ? $this->warranty : $locked->warranty;

            if ($locked->sequence < $warranty->maintenance_quota && $warranty->maintenance_interval_months) {
                static::create([
                    'warranty_id'    => $warranty->id,
                    'sequence'       => $locked->sequence + 1,
                    'scheduled_date' => static::nextOpenDate(
                        $warranty,
                        static::notBeforeToday($warranty, $locked->scheduled_date->copy()->addMonths($warranty->maintenance_interval_months))
                    ),
                    'status'         => 'pending',
                ]);
            } elseif ($warranty->customer_id) {
                // Gap ditutup 2026-10-01 (audit Maintenance PPF) -- sama
                // alasan dengan forfeit() di atas, tapi pesan beda (ini
                // kunjungan BERHASIL, kuota benar-benar habis -- bukan
                // siklus yang berhenti karena forfeit terus-menerus).
                app(\App\Services\PushNotificationService::class)->sendToCustomer(
                    $warranty->customer_id,
                    'Kuota Maintenance Habis',
                    "Kunjungan maintenance PPF garansi #{$warranty->warranty_code} tercatat. Kuota maintenance Anda sudah habis.",
                    ['type' => 'ppf_maintenance_ended', 'route' => "/account/warranty-detail?id={$warranty->id}"]
                );
            }
        });

        $this->refresh();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'scheduled_date', 'booking_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('warranty_maintenance_schedule')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Jadwal maintenance ke-{$this->sequence} dibuat untuk {$this->warranty?->warranty_code}",
                'updated' => "Jadwal maintenance ke-{$this->sequence} {$this->warranty?->warranty_code} — status: {$this->status}",
                default => "Jadwal maintenance {$this->warranty?->warranty_code} — {$eventName}",
            });
    }
}
