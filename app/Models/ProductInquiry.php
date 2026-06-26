<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductInquiry extends Model
{
    use HasFactory;

    protected $fillable = [
        'inquiry_number',
        'customer_name',
        'customer_contact',
        'message',
        'status',
        'notes',
    ];

    /**
     * Generate inquiry_number otomatis saat record dibuat,
     * mengikuti format yang sama dengan quotation_number:
     * INQ-YYYYMM-XXXX (4 karakter random alfanumerik uppercase)
     *
     * Catatan: prefix sengaja dibedakan jadi "AVL" (availability)
     * supaya tim sales bisa langsung membedakan ini bukan quotation
     * beli, melainkan inquiry ketersediaan produk.
     */
    protected static function booted(): void
    {
        static::creating(function (ProductInquiry $inquiry) {
            if (empty($inquiry->inquiry_number)) {
                $inquiry->inquiry_number = static::generateInquiryNumber();
            }
        });
    }

    protected static function generateInquiryNumber(): string
    {
        do {
            $candidate = 'AVL-' . now()->format('Ym') . '-' . Str::upper(Str::random(4));
        } while (static::where('inquiry_number', $candidate)->exists());

        return $candidate;
    }
}