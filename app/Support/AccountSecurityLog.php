<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Peristiwa keamanan akun di Histori Aktivitas (Histori Aktivitas Fase 2, 2026-10-21): masuk, gagal masuk, keluar, kata sandi
 * diganti atau direset. log_name 'auth'. Konteks (asal, dan IP/perangkat khusus staf) ditambahkan otomatis oleh ActivityContext.
 *
 * Tidak pernah mencatat kata sandi, token, atau kode OTP. Setiap metode aman dipanggil dari jalur login: kegagalan mencatat hanya
 * dilaporkan (report) dan tidak pernah dilempar ke pemanggil.
 */
class AccountSecurityLog
{
    public static function loggedIn(Model $account, string $channel): void
    {
        self::record('login', $account, $account, self::name($account) . ' masuk ' . self::channelText($channel), $account instanceof User ? 'staff' : 'customer');
    }

    /** $account diisi bila akun yang dituju dikenali (supaya tampil di tab Riwayat akun itu). */
    public static function loginFailed(string $audience, string $identifier, string $reason, ?Model $account = null): void
    {
        $label = $audience === 'staff' ? "\"{$identifier}\"" : self::maskEmail($identifier);
        $who = $audience === 'staff' ? 'staf' : 'pelanggan';

        self::record(
            'login_failed',
            null,
            $account,
            "Percobaan masuk {$who} gagal untuk {$label} ({$reason})",
            $audience,
            ['reason' => $reason]
        );
    }

    public static function loggedOut(Model $account, string $channel): void
    {
        self::record('logout', $account, $account, self::name($account) . ' keluar ' . self::channelText($channel), $account instanceof User ? 'staff' : 'customer');
    }

    /** $how: 'diganti sendiri' | 'direset lewat lupa kata sandi' | 'diubah admin'. $by: pelaku bila bukan pemilik akun. */
    public static function passwordChanged(User $account, string $how, ?Model $by = null): void
    {
        $event = str_contains($how, 'reset') ? 'password_reset' : 'password_changed';

        self::record($event, $by ?? $account, $account, "Kata sandi staf \"{$account->name}\" {$how}", 'staff');
    }

    public static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');

        return $at === false || $at < 1
            ? Str::mask($email, '*', 1)
            : substr($email, 0, 1) . str_repeat('*', max($at - 1, 1)) . substr($email, $at);
    }

    private static function name(Model $account): string
    {
        return $account instanceof User ? "Staf \"{$account->name}\"" : "Pelanggan #{$account->getKey()}";
    }

    private static function channelText(string $channel): string
    {
        return $channel === 'panel' ? 'di panel admin' : 'lewat aplikasi';
    }

    private static function record(string $event, ?Model $causer, ?Model $subject, string $description, string $audience, array $extra = []): void
    {
        try {
            $logger = activity('auth')
                ->event($event)
                ->causedBy($causer)
                ->withProperties($extra + ['audience' => $audience]);

            if ($subject) {
                $logger->performedOn($subject);
            }

            $logger->log($description);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
