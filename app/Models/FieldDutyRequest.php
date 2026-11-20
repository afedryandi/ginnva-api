<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Pengajuan Dinas Luar karyawan (2026-11-12): bekerja di luar toko (mis. kanvasing), sehari penuh atau sebagian hari dengan rentang jam. Sehari
 * penuh: setelah disetujui, absensi hari itu menjadi Dinas Luar. Sebagian hari: telat dan pulang cepat tidak dihitung pada rentang jam itu. Aturan ada di FieldDutyRequestService; migrasinya create_field_duty_requests_table.
 */
class FieldDutyRequest extends Model
{
    use HasStoreScope;
    use LogsActivity;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'store_id',
        'date',
        'start_time',
        'end_time',
        'description',
        'location_photo',
        'location_photo_at',
        'location_latitude',
        'location_longitude',
        'location_accuracy',
        'end_photo',
        'end_photo_at',
        'end_latitude',
        'end_longitude',
        'end_accuracy',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'date' => 'date',
        'reviewed_at' => 'datetime',
        'location_photo_at' => 'datetime',
        'location_latitude' => 'float',
        'location_longitude' => 'float',
        'location_accuracy' => 'float',
        'end_photo_at' => 'datetime',
        'end_latitude' => 'float',
        'end_longitude' => 'float',
        'end_accuracy' => 'float',
    ];

    /** Tautan Google Maps ke titik koordinat saat foto dikirim; null bila koordinat tidak tercatat (foto lama atau dari panel). */
    public function mapsUrl(): ?string
    {
        if ($this->location_latitude === null || $this->location_longitude === null) {
            return null;
        }

        return 'https://www.google.com/maps?q=' . $this->location_latitude . ',' . $this->location_longitude;
    }

    /** Tautan Google Maps ke titik koordinat saat foto akhir dikirim; null bila koordinat tidak tercatat. */
    public function endMapsUrl(): ?string
    {
        if ($this->end_latitude === null || $this->end_longitude === null) {
            return null;
        }

        return 'https://www.google.com/maps?q=' . $this->end_latitude . ',' . $this->end_longitude;
    }

    /**
     * Foto akhir (saat selesai dinas) boleh dikirim sekarang: foto awal sudah ada, foto akhir belum, dan pengajuannya disetujui (atau menunggu pada hari
     * dinas, seperti foto awal). Tidak ada jeda minimum dari foto awal (keputusan pemilik 2026-12-21).
     */
    public function canSendEndPhoto(): bool
    {
        if (blank($this->location_photo) || filled($this->end_photo)) {
            return false;
        }

        return ($this->status === self::STATUS_APPROVED && $this->date->lte(today()))
            || ($this->status === self::STATUS_PENDING && $this->date->isToday());
    }

    /** Sudah disetujui, harinya tiba atau lewat, foto awal ada tetapi foto akhir belum dikirim. */
    public function needsEndPhoto(): bool
    {
        return $this->status === self::STATUS_APPROVED && filled($this->location_photo) && blank($this->end_photo) && $this->date->lte(today());
    }

    /**
     * Foto di lokasi boleh dikirim sekarang dan belum ada: pengajuan yang sudah disetujui pada hari dinas atau sesudahnya (susulan), atau yang
     * masih menunggu tetapi hari dinasnya hari ini (karyawan sudah di lokasi sebelum atasan sempat menyetujui; fotonya ikut dinilai begitu disetujui).
     */
    public function canSendLocationPhoto(): bool
    {
        if (filled($this->location_photo)) {
            return false;
        }

        return ($this->status === self::STATUS_APPROVED && $this->date->lte(today()))
            || ($this->status === self::STATUS_PENDING && $this->date->isToday());
    }

    /** Koordinat GPS hanya berarti pada hari dinas itu sendiri; untuk pengajuan susulan (tanggal sudah lewat) tidak dicatat dan tidak diwajibkan. */
    public function locationPhotoNeedsCoordinates(): bool
    {
        return $this->date->isToday();
    }

    /** Sudah disetujui, harinya sudah tiba (atau lewat), tetapi foto bukti di lokasi belum dikirim. */
    public function needsLocationPhoto(): bool
    {
        return $this->status === 'approved' && blank($this->location_photo) && $this->date->lte(today());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Sehari penuh bila jam mulai dan selesai kosong; selain itu dinas sebagian hari. */
    public function isPartialDay(): bool
    {
        return $this->start_time !== null && $this->end_time !== null;
    }

    public function startsAt(): ?\Illuminate\Support\Carbon
    {
        return $this->isPartialDay() ? $this->date->copy()->setTimeFromTimeString($this->start_time) : null;
    }

    public function endsAt(): ?\Illuminate\Support\Carbon
    {
        return $this->isPartialDay() ? $this->date->copy()->setTimeFromTimeString($this->end_time) : null;
    }

    /** Untuk tampilan: "Sehari penuh" atau "10:00–13:00". */
    public function windowLabel(): string
    {
        return $this->isPartialDay() ? substr($this->start_time, 0, 5) . '–' . substr($this->end_time, 0, 5) : 'Sehari penuh';
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'date', 'start_time', 'end_time', 'description', 'review_notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('field_duty_request')
            ->setDescriptionForEvent(fn (string $eventName) => "Pengajuan dinas luar {$this->user?->name} ({$this->date?->format('d M Y')}) — {$eventName}");
    }
}
