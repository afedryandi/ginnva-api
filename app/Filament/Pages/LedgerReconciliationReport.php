<?php

namespace App\Filament\Pages;

use App\Models\Store;
use App\Services\InventorySyncService;
use App\Services\LedgerReconciliationService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use RuntimeException;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * "Rekonsiliasi Sub-Buku" (Keuangan Fase 0, 2026-11-04): membandingkan saldo akun kontrol Buku Besar dengan total sub-buku (Piutang,
 * Hutang, DP, Aset Tetap, Persediaan). Hanya laporan; selisih perlu dijelaskan akuntan. Terbatas full-access; yang lain lewat Hak Akses
 * Detail 'view' seperti laporan keuangan lain.
 */
class LedgerReconciliationReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationGroup = 'Jurnal & Akun';

    protected static ?string $navigationLabel = 'Rekonsiliasi Sub-Buku';

    protected static ?string $title = 'Rekonsiliasi Sub-Buku';

    protected static ?int $navigationSort = 204;

    protected static string $view = 'filament.pages.ledger-reconciliation-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || ($user?->canAccessStaffArea()
                && $user->hasMenuAccess(static::class)
                && $user->hasModuleAction(static::class, 'view', false));
    }

    public function mount(): void
    {
        $this->form->fill(['store_id' => null]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('store_id')
                ->label('Toko')
                ->placeholder('Seluruh perusahaan (termasuk persediaan)')
                ->options(fn () => Store::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->live(),
        ])->statePath('data');
    }

    use \App\Filament\Pages\Concerns\ExportsReportTable;

    protected function getHeaderActions(): array
    {
        return array_merge($this->exportActions(), $this->syncActions());
    }

    protected function reportExportData(): array
    {
        $checks = $this->getChecks();
        $storeName = ! empty($this->data['store_id']) ? Store::whereKey($this->data['store_id'])->value('name') : null;
        $notes = $checks->reject(fn (array $c) => $c['ok'] || empty($c['note']))->map(fn (array $c) => "{$c['label']}: {$c['note']}")->implode(' ');

        return [
            'key' => 'rekonsiliasi-sub-buku',
            'title' => 'Rekonsiliasi Sub-Buku',
            'subtitle' => 'Per ' . now()->translatedFormat('d F Y') . ' · ' . ($storeName ? "Toko {$storeName}" : 'Seluruh perusahaan'),
            'headings' => ['Pemeriksaan', 'Akun', 'Buku Besar', 'Sub-buku', 'Selisih', 'Status'],
            'rows' => $checks->map(fn (array $c) => [
                $c['label'],
                $c['account'],
                (float) $c['ledger'],
                (float) $c['subledger'],
                (float) $c['difference'],
                $c['ok'] ? 'Cocok' : 'Berselisih',
            ])->all(),
            'right' => [2, 3, 4],
            'note' => trim('Saldo akun di Buku Besar dibandingkan dengan total sub-buku keadaan sekarang. Selisih belum tentu galat. ' . $notes),
        ];
    }

    private function syncActions(): array
    {
        return [
            Action::make('syncInventory')
                ->label('Sinkronkan Persediaan')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                ->modalHeading('Sinkronkan Persediaan ke Sub-Buku')
                ->modalDescription('Membuat SATU jurnal penyesuaian yang menyamakan akun persediaan dengan nilai stok sekarang. Dipakai untuk saldo awal persediaan atau koreksi periodik. Pilih akun lawan dengan hati-hati dan minta akuntan memeriksanya.')
                ->form(fn () => [
                    CheckboxList::make('keys')
                        ->label('Akun yang disinkronkan')
                        ->options(fn () => app(InventorySyncService::class)->plan()->mapWithKeys(fn (array $row) => [
                            $row['key'] => $row['label'] . ' (' . ($row['adjustment'] > 0 ? '+' : '-') . 'Rp ' . number_format(abs($row['adjustment']), 0, ',', '.') . ')',
                        ])->all())
                        ->required()
                        ->default(fn () => app(InventorySyncService::class)->plan()->pluck('key')->all()),
                    \Filament\Forms\Components\Select::make('counter_account_id')
                        ->label('Akun lawan')
                        ->options(fn () => InventorySyncService::counterOptions())
                        ->required()
                        ->helperText('3200 Laba Ditahan untuk saldo awal persediaan; 5100/5200 untuk koreksi HPP; 6520 untuk kerugian persediaan.'),
                    Textarea::make('note')->label('Catatan')->rows(2)->maxLength(300),
                ])
                ->action(function (array $data) {
                    try {
                        $entry = app(InventorySyncService::class)->post($data['keys'], (int) $data['counter_account_id'], $data['note'] ?? null, auth()->id());
                        Notification::make()->title('Persediaan disinkronkan')->body("Jurnal {$entry->entry_number} dibuat.")->success()->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->title('Tidak bisa disinkronkan')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getChecks(): Collection
    {
        $storeId = $this->data['store_id'] ?? null;

        return app(LedgerReconciliationService::class)->run(now(), $storeId ? (int) $storeId : null);
    }
}
