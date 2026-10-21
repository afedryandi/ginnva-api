<?php

namespace App\Filament\Resources\ActivityResource\Pages;

use App\Filament\Resources\ActivityResource;
use App\Support\ActivityPresenter;
use Carbon\Carbon;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Activity;

class ListActivities extends ListRecords
{
    protected static string $resource = ActivityResource::class;

    /**
     * Ringkasan ukuran log (Histori Aktivitas Fase 4): retensi sengaja tanpa batas, jadi pemilik perlu melihat seberapa besar log
     * sudah tumbuh. Dihitung paling sering tiap 5 menit supaya membuka halaman tidak menghitung seluruh tabel setiap kali.
     * Rinciannya (per modul, per bulan, ukuran MB) lewat perintah `php artisan activity:size-report`.
     */
    public function getSubheading(): string|Htmlable|null
    {
        $stats = Cache::remember('activity_log_summary', 300, fn () => [
            'total' => Activity::query()->count(),
            'oldest' => Activity::query()->min('created_at'),
        ]);

        if ($stats['total'] === 0) {
            return null;
        }

        $since = Carbon::parse($stats['oldest'], config('app.timezone'))->setTimezone(ActivityPresenter::TIMEZONE)->format('d M Y');

        return number_format($stats['total'], 0, ',', '.') . " catatan sejak {$since} (disimpan tanpa batas waktu).";
    }
}
