<?php

namespace App\Filament\Pages;

use App\Models\WebPushSubscription;
use App\Services\WebPushService;
use Filament\Pages\Page;

/**
 * "Notifikasi di HP" (2026-11-24): memasang panel sebagai aplikasi (PWA) dan mengaktifkan notifikasi push di perangkat ini, untuk staf yang
 * bekerja lewat panel saja (mis. pengguna iPhone tanpa aplikasi karyawan). Tidak muncul di menu; dibuka dari menu avatar. Terbuka untuk semua
 * akun yang bisa masuk panel, karena menyangkut perangkat dan akun sendiri.
 */
class NotifikasiHp extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $title = 'Notifikasi di HP';

    protected static ?string $slug = 'notifikasi-hp';

    protected static string $view = 'filament.pages.notifikasi-hp';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function enabledOnServer(): bool
    {
        return app(WebPushService::class)->enabled();
    }

    public function publicKey(): ?string
    {
        return app(WebPushService::class)->publicKey();
    }

    /** Perangkat yang sudah berlangganan untuk akun ini (ringkas, tanpa kunci). */
    public function devices(): \Illuminate\Support\Collection
    {
        return WebPushSubscription::where('user_id', auth()->id())->orderByDesc('last_used_at')->get(['id', 'user_agent', 'last_used_at', 'created_at']);
    }

    public function deviceLabel(?string $userAgent): string
    {
        $ua = strtolower((string) $userAgent);

        return match (true) {
            str_contains($ua, 'iphone') => 'iPhone',
            str_contains($ua, 'ipad') => 'iPad',
            str_contains($ua, 'android') => 'Android',
            str_contains($ua, 'windows') => 'Windows',
            str_contains($ua, 'mac os') => 'Mac',
            default => 'Perangkat',
        };
    }
}
