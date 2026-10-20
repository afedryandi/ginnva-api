<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Pengaturan keuangan sederhana (kunci dan nilai) yang diisi pemilik di panel (Keuangan Fase 4, 2026-11-07). Perubahan tercatat di riwayat
 * aktivitas. Kunci yang dikenal ada di KEYS beserta penjelasannya; kunci kosong berarti pengaturan tidak aktif (perilaku lama).
 */
class FinanceSetting extends Model
{
    use LogsActivity;

    /** Batas nominal pengeluaran yang cukup disetujui Store Manager (tahap manajer menjadi final). Kosong = semua nominal ke direksi. */
    public const EXPENSE_MANAGER_LIMIT = 'expense_manager_limit';

    protected $fillable = ['key', 'value'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['key', 'value'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('finance_setting');
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = static::where('key', $key)->value('value');

        return $value === null || $value === '' ? $default : $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $record = static::firstOrNew(['key' => $key]);
        $record->value = $value === null ? null : (string) $value;
        $record->save();
    }

    public static function expenseManagerLimit(): ?float
    {
        $value = static::get(self::EXPENSE_MANAGER_LIMIT);

        return $value === null ? null : (float) $value;
    }
}
