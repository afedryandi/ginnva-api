<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WarrantyClaim extends Model
{
    use HasFactory;

    protected $fillable = [
        'claim_number',
        'warranty_id',
        'category',
        'description',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function warranty()
    {
        return $this->belongsTo(Warranty::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
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