<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Klaim produk yang boleh dipakai di web, aplikasi, dan materi (Marketing/Konten Fase 2). Alur: draft -> disetujui (oleh akses penuh,
 * wajib ada bukti atau catatan sumber, plus tanggal uji) -> bisa ditarik. Mengubah isi klaim yang sudah disetujui mengembalikannya ke
 * draft, supaya angka yang tayang selalu persis angka yang pernah disetujui.
 */
class ProductClaim extends Model
{
    use LogsActivity;

    public const TYPES = [
        'tser' => 'TSER (penolakan energi surya total)',
        'ir' => 'Penolakan IR',
        'uv' => 'Penolakan UV',
        'vlt' => 'VLT (transmisi cahaya tampak)',
        'clarity' => 'Kejernihan',
        'warranty_years' => 'Garansi (tahun)',
        'other' => 'Lainnya',
    ];

    public const STATUSES = [
        'draft' => 'Draft',
        'approved' => 'Disetujui',
        'withdrawn' => 'Ditarik',
    ];

    /** Kolom yang, bila berubah pada klaim yang sudah disetujui, membatalkan persetujuannya. */
    private const CONTENT_FIELDS = ['series', 'claim_type', 'label', 'value', 'evidence_path', 'source_note', 'tested_at'];

    protected $fillable = [
        'series',
        'claim_type',
        'label',
        'value',
        'evidence_path',
        'source_note',
        'tested_at',
        'status',
        'approved_by',
        'approved_at',
        'created_by',
    ];

    protected $casts = [
        'tested_at' => 'date',
        'approved_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['series', 'claim_type', 'label', 'value', 'evidence_path', 'source_note', 'tested_at', 'status', 'approved_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('product_claim');
    }

    protected static function booted(): void
    {
        static::updating(function (ProductClaim $claim) {
            if ($claim->getOriginal('status') === 'approved' && $claim->status === 'approved' && $claim->isDirty(self::CONTENT_FIELDS)) {
                $claim->status = 'draft';
                $claim->approved_by = null;
                $claim->approved_at = null;
            }
        });
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /** Syarat sebelum disetujui: ada bukti (berkas atau catatan sumber) dan tanggal uji/dokumen. */
    public function approvalBlocker(): ?string
    {
        if (blank($this->evidence_path) && blank($this->source_note)) {
            return 'Lampirkan berkas bukti atau isi catatan sumber angka ini.';
        }

        if (! $this->tested_at) {
            return 'Isi tanggal uji atau tanggal dokumen sumber.';
        }

        return null;
    }

    public function approve(User $user): void
    {
        $this->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()]);
    }

    public function withdraw(): void
    {
        $this->update(['status' => 'withdrawn']);
    }
}
