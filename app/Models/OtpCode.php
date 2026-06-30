<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Carbon\Carbon;

class OtpCode extends Model
{
    protected $fillable = [
        'identifier',
        'channel',
        'code',
        'expires_at',
        'used_at',
        'attempts',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public const MAX_ATTEMPTS = 5;
    public const EXPIRY_MINUTES = 10;

    /**
     * Generate & simpan kode OTP baru untuk identifier tertentu.
     * Kode lama yang belum dipakai untuk identifier+channel yang sama
     * dihapus dulu — supaya tidak ada beberapa kode valid sekaligus yang
     * bisa membingungkan (atau disalahgunakan kalau salah satu kebobolan).
     */
    public static function generateFor(string $identifier, string $channel = 'email'): self
    {
        static::where('identifier', $identifier)
            ->where('channel', $channel)
            ->whereNull('used_at')
            ->delete();

        return static::create([
            'identifier' => $identifier,
            'channel' => $channel,
            'code' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'expires_at' => Carbon::now()->addMinutes(self::EXPIRY_MINUTES),
            'attempts' => 0,
        ]);
    }

    /**
     * Verifikasi kode. Mengembalikan true/false, sekaligus menandai kode
     * sebagai terpakai (used_at) kalau berhasil, atau menambah hitungan
     * attempts kalau gagal — supaya brute-force dibatasi otomatis.
     */
    public static function verify(string $identifier, string $channel, string $code): bool
    {
        $otp = static::where('identifier', $identifier)
            ->where('channel', $channel)
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        if (! $otp) {
            return false;
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        if ($otp->expires_at->isPast()) {
            return false;
        }

        if (! hash_equals($otp->code, $code)) {
            $otp->increment('attempts');
            return false;
        }

        $otp->update(['used_at' => Carbon::now()]);
        return true;
    }
}
