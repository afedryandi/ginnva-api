<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Vehicle extends Model
{
    use LogsActivity;

    /** Tipe bodi (Master Data Fase 1): label ditampilkan di panel; kunci disimpan. */
    public const BODY_TYPES = [
        'hatchback' => 'Hatchback',
        'sedan' => 'Sedan',
        'suv' => 'SUV',
        'mpv' => 'MPV',
        'pickup' => 'Pikap / Double Cabin',
        'minibus' => 'Minibus / Van',
        'coupe' => 'Coupe / Convertible',
        'lainnya' => 'Lainnya',
    ];

    protected $fillable = [
        'brand',
        'model',
        'variant',
        'size_category',
        'is_active',
        'body_type',
        'year_from',
        'year_to',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'year_from' => 'integer',
        'year_to' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['brand', 'model', 'variant', 'size_category', 'is_active', 'body_type', 'year_from', 'year_to'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('vehicle');
    }

    /** Kendaraan yang masih ditawarkan di katalog (aplikasi pelanggan/staf dan formulir). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function quotations()
    {
        return $this->hasMany(Quotation::class);
    }

    /**
     * Pemakaian kendaraan ini oleh data lain, untuk memutuskan boleh dihapus atau cukup dinonaktifkan. Kosong = tidak dipakai di mana pun.
     * Termasuk kendaraan pelanggan: menghapus katalog melepas tautan itu diam-diam (nullOnDelete), jadi dihitung sebagai pemakaian.
     *
     * @return array<string, int> label pemakaian => jumlah
     */
    public function usageSummary(): array
    {
        return array_filter([
            'penawaran' => Quotation::where('vehicle_id', $this->getKey())->count(),
            'studi kasus' => CaseStudy::where('vehicle_id', $this->getKey())->count(),
            'kendaraan pelanggan' => CustomerVehicle::where('vehicle_id', $this->getKey())->count(),
        ], fn (int $count) => $count > 0);
    }

    /** Contoh model per kelas ukuran untuk panduan di form (maks. $limit nama per kelas), dari data yang sudah ada. */
    public static function sizeExamples(int $limit = 3): array
    {
        $examples = [];

        foreach (['S', 'M', 'L', 'XL', 'XXL'] as $size) {
            $examples[$size] = static::active()
                ->where('size_category', $size)
                ->whereNotNull('model')
                ->orderBy('brand')->orderBy('model')
                ->distinct()
                ->limit($limit)
                ->get(['brand', 'model'])
                ->map(fn (Vehicle $v) => trim($v->brand . ' ' . $v->model))
                ->unique()
                ->values()
                ->all();
        }

        return $examples;
    }
}
