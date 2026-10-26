<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class WarrantyClaim extends Model
{
    use HasFactory, LogsActivity;

    // SEBELUMNYA tidak ada audit trail sama sekali untuk perubahan status
    // klaim (pending -> pass/reject) — cuma field reviewed_by/reviewed_at
    // di record itu sendiri, yang bisa tertimpa kalau direview ulang.
    // Sama pola dengan Warranty (yang sudah pakai LogsActivity). Lihat
    // audit modul Garansi 2026-08-27.
    /** Hasil akhir penanganan klaim yang sudah disetujui (pass). */
    public const RESOLUTIONS = [
        'repaired' => 'Diperbaiki',
        'replaced' => 'Diganti / dipasang ulang',
        'refunded' => 'Dana dikembalikan',
        'cancelled' => 'Dibatalkan pelanggan',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'category', 'rejection_reason', 'reviewed_by', 'handled_by', 'estimated_completion_date', 'resolution', 'resolution_notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'claim_number',
        'warranty_id',
        'category',
        'description',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'handled_by',
        'estimated_completion_date',
        'resolution',
        'resolution_notes',
        'resolved_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'estimated_completion_date' => 'date',
        'resolved_at' => 'datetime',
    ];

    /** Staf atau teknisi yang menangani klaim ini. */
    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * Memo Pengambilan/Pengembalian booking kunjungan klaim ini = daftar bahan yang terpakai. Stoknya sudah diurus
     * MaterialMemoStockService saat memo diisi; klaim hanya membaca dan menautkannya (tidak ada jalur stok kedua).
     */
    public function materialMemo(): ?MaterialMemo
    {
        return $this->booking?->materialMemo;
    }

    /** Klaim yang sudah disetujui, belum ada hasil akhir, dan perkiraan selesainya sudah lewat. */
    public function isOverdue(): bool
    {
        return $this->status === 'pass'
            && $this->resolution === null
            && $this->estimated_completion_date !== null
            && $this->estimated_completion_date->isBefore(today());
    }

    public function warranty()
    {
        return $this->belongsTo(Warranty::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Gap ditutup 2026-10-01 (audit Bagian B, "Klaim Garansi & Maintenance
     * PPF") -- booking kunjungan yang dibuat BARENGAN saat klaim ini
     * diajukan lewat app (lihat MyWarrantyController::storeClaim()).
     * Nullable -- klaim lama (dibuat staff manual lewat Filament sebelum
     * fitur ini ada) tidak punya booking terkait.
     */
    public function booking()
    {
        return $this->hasOne(Booking::class, 'warranty_claim_id');
    }

    /**
     * Generate claim_number otomatis, mengikuti format yang sama dengan
     * inquiry_number / quotation_number: PREFIX-YYYYMM-XXXX.
     * Prefix "CLM" (claim) supaya mudah dibedakan dari warranty_code (GNV-)
     * dan inquiry_number (AVL-).
     */
    protected static function booted(): void
    {
        static::creating(function (WarrantyClaim $claim) {
            if (empty($claim->claim_number)) {
                $claim->claim_number = static::generateClaimNumber();
            }
        });
    }

    protected static function generateClaimNumber(): string
    {
        do {
            $candidate = 'CLM-' . now()->format('Ym') . '-' . Str::upper(Str::random(4));
        } while (static::where('claim_number', $candidate)->exists());

        return $candidate;
    }
}