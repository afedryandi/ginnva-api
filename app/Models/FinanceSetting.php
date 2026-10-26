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

    /** Profil perusahaan: dipakai di kop dan kaki invoice. Kosong = hanya nama bawaan yang tampil. */
    public const COMPANY_NAME = 'company_name';

    public const COMPANY_ADDRESS = 'company_address';

    public const COMPANY_NPWP = 'company_npwp';

    public const COMPANY_KLU = 'company_klu';

    /** Nama yang dipakai sebelum profil diisi (sebelumnya tertulis langsung di tampilan invoice). */
    public const DEFAULT_COMPANY_NAME = 'Ginnva Shield Indonesia';

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

    /**
     * Profil perusahaan untuk dokumen cetak.
     *
     * @return array{name: string, address: ?string, npwp: ?string, klu: ?string}
     */
    public static function companyProfile(): array
    {
        return [
            'name' => (string) static::get(self::COMPANY_NAME, self::DEFAULT_COMPANY_NAME),
            'address' => static::get(self::COMPANY_ADDRESS),
            'npwp' => static::get(self::COMPANY_NPWP),
            'klu' => static::get(self::COMPANY_KLU),
        ];
    }

    /** NPWP sah: 15 atau 16 angka, titik dan strip diabaikan. */
    public static function isValidNpwp(?string $value): bool
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return in_array(strlen($digits), [15, 16], true);
    }
}
