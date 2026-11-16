<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ActivityResource;
use App\Support\ActivityPresenter;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Kartu "Aktivitas terbaru" di Dashboard Utama: delapan catatan Histori Aktivitas paling baru (siapa, melakukan apa, pada objek apa, kapan).
 * Datanya log asli yang sama dengan menu Histori Aktivitas, jadi syarat melihatnya juga sama (akses penuh, atau staf yang menu itu dicentang
 * eksplisit): log memuat perubahan gaji, hak akses, dan IP staf, yang tidak boleh terbuka lewat akses menu bawaan.
 */
class AktivitasTerbaruWidget extends Widget
{
    protected static string $view = 'filament.widgets.aktivitas-terbaru';

    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 80;

    public static function canView(): bool
    {
        return ActivityResource::canViewAny();
    }

    /** @return Collection<int, array{actor: string, action: string, module: string, target: string, note: string, time: string, risky: bool}> */
    public function getLogs(): Collection
    {
        return Activity::query()
            ->with('causer:id,name')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (Activity $activity): array => [
                'actor' => $activity->causer?->name ?? 'Sistem',
                'action' => ActivityPresenter::eventLabel($activity->event),
                'module' => ActivityResource::logNameLabel($activity->log_name),
                'target' => ActivityPresenter::subjectLabel($activity),
                'note' => Str::limit((string) $activity->description, 80),
                'time' => $activity->created_at?->locale('id')->diffForHumans() ?? '—',
                'risky' => in_array($activity->log_name, ActivityResource::RISKY_MODULES, true),
            ]);
    }

    public function allUrl(): string
    {
        return ActivityResource::getUrl('index');
    }
}
