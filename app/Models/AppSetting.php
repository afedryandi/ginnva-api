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

    public const PRIVACY_NOTICE = 'booking.privacy_notice';

    /** Kunci yang dikenal: label di admin dan nilai bawaan bila baris belum ada / kosong. */
    public const DEFINITIONS = [
        self::CANCELLATION_POLICY => [
            'label' => 'Kebijakan Pembatalan Booking',
            'help' => 'Tampil di ringkasan booking pelanggan. Tulis ringkas dan tanpa janji yang tidak Anda terapkan.',
            'default' => 'Jika berhalangan, mohon ubah jadwal atau batalkan booking sedini mungkin agar slot bisa dipakai pelanggan lain. Tidak ada biaya pembatalan.',
        ],
        self::PRIVACY_NOTICE => [
            'label' => 'Pemberitahuan Privasi Booking',
            'help' => 'Tampil di ringkasan booking dengan kotak persetujuan. Mengubah isi membuat versi baru; persetujuan lama tetap tercatat pada versi yang disetujui. Sebut data yang dipakai, tujuan, masa simpan foto, dan cara meminta akses/penghapusan.',
            'default' => 'Kami memakai nama, nomor HP, data kendaraan, dan foto kendaraan Anda hanya untuk memproses booking, pengerjaan, garansi, dan komunikasi layanan. Foto kendaraan disimpan 24 bulan setelah layanan selesai, lalu dihapus. Anda dapat meminta akses, koreksi, atau penghapusan data dengan menghubungi toko, atau menghapus akun dari aplikasi.',
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

    /** @return array{text: string, version: string} teks pemberitahuan privasi saat ini dan versinya (hash singkat dari teks). */
    public static function privacyNotice(): array
    {
        $text = static::get(self::PRIVACY_NOTICE);

        return ['text' => $text, 'version' => substr(sha1($text), 0, 8)];
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
