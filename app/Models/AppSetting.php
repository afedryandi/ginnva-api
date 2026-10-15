<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Pengaturan teks sederhana (kunci → nilai) yang bisa diedit admin lewat Filament (Fase 4 pengerjaan ulang booking,
 * 2026-10-20). Kunci yang dikenal didaftarkan di DEFINITIONS (label + nilai bawaan); kunci lain diabaikan.
 */
class AppSetting extends Model
{
    use LogsActivity;

    public const CANCELLATION_POLICY = 'booking.cancellation_policy';

    /** Kunci yang dikenal: label di admin dan nilai bawaan bila baris belum ada / kosong. */
    public const DEFINITIONS = [
        self::CANCELLATION_POLICY => [
            'label' => 'Kebijakan Pembatalan Booking',
            'help' => 'Tampil di ringkasan booking pelanggan. Tulis ringkas dan tanpa janji yang tidak Anda terapkan.',
            'default' => 'Jika berhalangan, mohon ubah jadwal atau batalkan booking sedini mungkin agar slot bisa dipakai pelanggan lain. Tidak ada biaya pembatalan.',
        ],
    ];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key): string
    {
        $default = self::DEFINITIONS[$key]['default'] ?? '';

        return Cache::remember("app_setting.{$key}", 300, function () use ($key, $default) {
            $value = static::query()->whereKey($key)->value('value');

            return filled($value) ? (string) $value : $default;
        });
    }

    protected static function booted(): void
    {
        static::saved(fn (AppSetting $s) => Cache::forget("app_setting.{$s->key}"));
        static::deleted(fn (AppSetting $s) => Cache::forget("app_setting.{$s->key}"));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('app_setting')
            ->logOnly(['key', 'value'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
