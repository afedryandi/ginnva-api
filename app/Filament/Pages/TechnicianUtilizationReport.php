<?php

namespace App\Filament\Pages;

use App\Models\Store;
use App\Services\TechnicianUtilizationService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * "Laporan Utilisasi Teknisi" — temuan PRIORITAS TINGGI dari audit Majoo
 * vs Ginnva (docs/audit-majoo-vs-ginnva.md), dibangun 2026-09-21.
 *
 * Lihat App\Services\TechnicianUtilizationService untuk penjelasan lengkap
 * definisi metrik & keterbatasannya (Jam Job adalah ESTIMASI dari
 * duration_days, bukan timestamp mulai/selesai kerja yang sesungguhnya).
 *
 * Pola form/filter (tanggal + cabang untuk full-access) sengaja disalin
 * dari SalesSummaryReport supaya konsisten dengan laporan lain.
 */
class TechnicianUtilizationReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationLabel = 'Utilisasi Teknisi';

    protected static ?string $title = 'Utilisasi Teknisi';

    protected static ?int $navigationSort = 35;

    protected static string $view = 'filament.pages.technician-utilization-report';

    public ?array $data = [];

    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    #[Url(as: 'cabang')]
    public ?int $storeId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->from = $this->queryDateOrDefault($this->from, now()->startOfMonth());
        $this->to = $this->queryDateOrDefault($this->to, now()->endOfMonth());

        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $this->storeId = null;
        }

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
            'store_id' => $this->storeId,
        ]);
    }

    private function queryDateOrDefault(mixed $value, Carbon $default): string
    {
        if (! is_string($value) || $value === '') {
            return $default->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return $default->toDateString();
        }
    }

    public function updatedData(mixed $value, string $key): void
    {
        match ($key) {
            'from' => $this->from = $value,
            'to' => $this->to = $value,
            'store_id' => $this->storeId = $value ? (int) $value : null,
            default => null,
        };
    }

    public function form(Form $form): Form
    {
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;

        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
            Select::make('store_id')
                ->label('Cabang')
                ->placeholder('Semua cabang')
                ->options(fn () => Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->visible($isFullAccess)
                ->live(),
        ])->columns($isFullAccess ? 3 : 2)->statePath('data');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getRows(): Collection
    {
        $user = auth()->user();
        $storeId = $user?->isFullAccess() ? $this->storeId : $user?->store_id;

        return app(TechnicianUtilizationService::class)->summarize(
            Carbon::parse($this->from)->startOfDay(),
            Carbon::parse($this->to)->endOfDay(),
            $storeId,
        );
    }
}
