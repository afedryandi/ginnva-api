<?php

namespace App\Filament\Pages;

use App\Models\Store;
use App\Services\BookingServiceLevelService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * "Laporan SLA & Tidak Datang" (Fase 5 pengerjaan ulang booking, 2026-10-21): kecepatan konfirmasi dibanding target, tingkat
 * tidak-datang, konversi pending → terkonfirmasi, dan alasan pembatalan. Hitungannya ada di BookingServiceLevelService.
 */
class BookingServiceLevelReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Jasa';

    protected static ?string $navigationLabel = 'Laporan SLA & Tidak Datang';

    protected static ?string $title = 'Laporan SLA & Tidak Datang';

    // 106 -- band grup 'Laporan Jasa' (setelah Durasi Pengerjaan per Layanan, 105).
    protected static ?int $navigationSort = 106;

    protected static string $view = 'filament.pages.booking-service-level-report';

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
        $this->from = $this->dateOrDefault($this->from, now()->startOfMonth());
        $this->to = $this->dateOrDefault($this->to, now()->endOfMonth());

        if (Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
        }

        // Staf toko tidak pernah boleh memilih cabang lain; URL yang tidak sah diabaikan.
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $this->storeId = null;
        }

        $this->form->fill(['from' => $this->from, 'to' => $this->to, 'store_id' => $this->storeId]);
    }

    private function dateOrDefault(mixed $value, Carbon $default): string
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

        if (in_array($key, ['from', 'to'], true) && $this->from && $this->to && Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
            $this->data['to'] = $this->from;
        }
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

    public function getResult(): array
    {
        $user = auth()->user();

        // Akses penuh memilih cabang (null = semua); staf toko dikunci ke tokonya, tanpa toko = tidak ada hasil (-1).
        $storeId = ($user?->isFullAccess() ?? false)
            ? ($this->data['store_id'] ?? null)
            : ($user?->store_id ?? -1);

        return app(BookingServiceLevelService::class)->summarize(
            Carbon::parse($this->data['from'] ?? $this->from),
            Carbon::parse($this->data['to'] ?? $this->to),
            $storeId ? (int) $storeId : null,
        );
    }
}
