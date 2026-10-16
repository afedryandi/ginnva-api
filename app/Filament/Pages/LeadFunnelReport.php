<?php

namespace App\Filament\Pages;

use App\Services\LeadFunnelService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * "Dasbor Lead" (Marketing/Konten Fase 1, 2026-10-23): corong lead dari tiga sumber (quotation, inquiry produk, kemitraan) per kanal
 * dan kampanye: berapa masuk, berapa yang direspons dan seberapa cepat, berapa deal/tidak jadi, dan alasannya. Hitungan ada di
 * LeadFunnelService.
 */
class LeadFunnelReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-funnel';

    protected static ?string $cluster = \App\Filament\Clusters\MarketingKontenCluster::class;

    protected static ?string $navigationGroup = 'Inquiry & Kemitraan';

    protected static ?string $navigationLabel = 'Dasbor Lead';

    protected static ?string $title = 'Dasbor Lead';

    protected static ?int $navigationSort = -4;

    protected static string $view = 'filament.pages.lead-funnel-report';

    public ?array $data = [];

    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    #[Url(as: 'tipe')]
    public ?string $type = null;

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

        if (! array_key_exists((string) $this->type, LeadFunnelService::TYPES)) {
            $this->type = null;
        }

        $this->form->fill(['from' => $this->from, 'to' => $this->to, 'type' => $this->type]);
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
            'type' => $this->type = $value ?: null,
            default => null,
        };

        if (in_array($key, ['from', 'to'], true) && $this->from && $this->to && Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
            $this->data['to'] = $this->from;
        }
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
            Select::make('type')
                ->label('Jenis Lead')
                ->options(LeadFunnelService::TYPES)
                ->placeholder('Semua jenis')
                ->live(),
        ])->columns(3)->statePath('data');
    }

    public function getResult(): array
    {
        $type = $this->data['type'] ?? $this->type;

        return app(LeadFunnelService::class)->summarize(
            Carbon::parse($this->data['from'] ?? $this->from),
            Carbon::parse($this->data['to'] ?? $this->to),
            array_key_exists((string) $type, LeadFunnelService::TYPES) ? $type : null,
        );
    }
}
