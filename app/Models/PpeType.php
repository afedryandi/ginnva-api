<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Jenis alat pelindung diri / perlengkapan kerja (Karyawan Fase 5, 2026-11-02) beserta umur pakai bawaannya. Angka bawaan hanyalah
 * titik awal yang masuk akal dan HARUS disesuaikan dengan SDS produk, kebijakan K3, dan pemakaian nyata di lapangan.
 */
class PpeType extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'replace_after_months', 'is_active'];

    protected $casts = ['replace_after_months' => 'integer', 'is_active' => 'boolean'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'replace_after_months', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('ppe_type');
    }

    public function issuances(): HasMany
    {
        return $this->hasMany(PpeIssuance::class);
    }

    /** Jenis bawaan; aman dipanggil berulang (tidak menimpa perubahan staf). */
    public static function ensureDefaults(): void
    {
        foreach ([
            ['Seragam kerja', 12],
            ['Sepatu keselamatan', 12],
            ['Sarung tangan', 1],
            ['Masker/respirator', 3],
            ['Kacamata pelindung', 12],
            ['Pelindung telinga', 12],
        ] as [$name, $months]) {
            static::firstOrCreate(['name' => $name], ['replace_after_months' => $months, 'is_active' => true]);
        }
    }
}
