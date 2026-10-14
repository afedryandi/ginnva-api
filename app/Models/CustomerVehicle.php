<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kendaraan milik pelanggan ("Kendaraan Saya"), dipakai ulang di booking (Fase 1 pengerjaan ulang booking, 2026-10-15).
 */
class CustomerVehicle extends Model
{
    public const SIZES = ['S', 'M', 'L', 'XL', 'XXL'];

    protected $fillable = [
        'customer_id', 'vehicle_id', 'brand', 'model', 'variant', 'plate', 'plate_normalized',
        'year', 'color', 'size_category', 'vin', 'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'year' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /** "b 1234-abc" → "B1234ABC" (huruf besar, tanpa spasi/tanda baca). */
    public static function normalizePlate(string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plate) ?? '');
    }

    /** Plat Indonesia: 1–2 huruf wilayah, 1–4 angka, 0–3 huruf seri (mis. B1234ABC, AB12C, D1234). */
    public static function isValidPlate(string $normalized): bool
    {
        return (bool) preg_match('/^[A-Z]{1,2}[0-9]{1,4}[A-Z]{0,3}$/', $normalized);
    }

    /** "B1234ABC" → "B 1234 ABC". */
    public static function formatPlate(string $normalized): string
    {
        if (preg_match('/^([A-Z]{1,2})([0-9]{1,4})([A-Z]{0,3})$/', $normalized, $m)) {
            return trim($m[1] . ' ' . $m[2] . ' ' . $m[3]);
        }

        return $normalized;
    }

    /** Label singkat, mis. "Toyota Fortuner VRZ". */
    public function label(): string
    {
        return trim(implode(' ', array_filter([$this->brand, $this->model, $this->variant])));
    }
}
