<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Luas kaca (m²) per merek+model (Master Data Fase 2, 2026-10-21): kaca depan, samping (total), belakang, dan sunroof. Kunci merek+model
 * dinormalkan otomatis saat disimpan supaya tidak ada baris ganda karena huruf besar/kecil atau spasi.
 */
class VehicleGlassArea extends Model
{
    use LogsActivity;

    protected $fillable = ['brand', 'model', 'front_sqm', 'side_sqm', 'rear_sqm', 'sunroof_sqm', 'source', 'imported_at', 'notes'];

    protected $casts = [
        'front_sqm' => 'decimal:3',
        'side_sqm' => 'decimal:3',
        'rear_sqm' => 'decimal:3',
        'sunroof_sqm' => 'decimal:3',
        'imported_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (VehicleGlassArea $area) {
            $area->brand = trim(preg_replace('/\s+/', ' ', (string) $area->brand));
            $area->model = trim(preg_replace('/\s+/', ' ', (string) $area->model));
            $area->brand_key = static::keyOf($area->brand);
            $area->model_key = static::keyOf($area->model);
        });
    }

    /** Kunci pembanding: huruf kecil, spasi beruntun jadi satu, tepi dibuang. */
    public static function keyOf(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $text)));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['brand', 'model', 'front_sqm', 'side_sqm', 'rear_sqm', 'sunroof_sqm', 'source'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('vehicle_glass_area');
    }

    public function totalSqm(): float
    {
        return round((float) $this->front_sqm + (float) $this->side_sqm + (float) $this->rear_sqm + (float) $this->sunroof_sqm, 3);
    }

    /** Jumlah kendaraan katalog (semua varian) dengan merek+model yang sama; 0 berarti luas ini belum terhubung ke katalog. */
    public function catalogVehicleCount(): int
    {
        return Vehicle::query()
            ->whereRaw('LOWER(TRIM(brand)) = ?', [$this->brand_key])
            ->whereRaw('LOWER(TRIM(model)) = ?', [$this->model_key])
            ->count();
    }
}
