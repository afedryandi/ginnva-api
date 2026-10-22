<?php

namespace App\Console\Commands;

use App\Support\GuideCatalog;
use Filament\Facades\Filament;
use Illuminate\Console\Command;

/**
 * Laporan cakupan Panduan Penggunaan (Panduan Fase 0, 2026-10-22): berapa menu panel yang dirujuk panduan, dan menu mana yang belum.
 * Hanya membaca. Dasar menurunkan angka "belum punya panduan" fase demi fase dan, di fase 2, mengunci cakupan lewat tes.
 */
class GuideCoverageReport extends Command
{
    protected $signature = 'guides:coverage {--list : Tampilkan menu yang belum punya panduan, dikelompokkan}';

    protected $description = 'Laporan baca-saja cakupan Panduan Penggunaan terhadap menu panel';

    public function handle(): int
    {
        $panel = Filament::getPanel('admin');
        $covered = collect(GuideCatalog::all())->pluck('menus')->flatten()->unique()->flip();

        $menus = collect(array_merge($panel->getResources(), $panel->getPages()))
            ->filter(function (string $class) {
                try {
                    return $class::shouldRegisterNavigation();
                } catch (\Throwable) {
                    return false;
                }
            })
            ->reject(fn (string $class) => in_array(class_basename($class), GuideCatalog::NO_GUIDE_NEEDED, true))
            ->map(fn (string $class) => ['name' => class_basename($class), 'group' => $this->groupOf($class), 'label' => $this->labelOf($class)])
            ->sortBy(['group', 'label'])
            ->values();

        $total = $menus->count();
        $done = $menus->filter(fn (array $m) => $covered->has($m['name']))->count();
        $percent = $total > 0 ? round($done / $total * 100, 1) : 0;

        $this->info('Cakupan panduan');
        $this->line("  Menu terdaftar    : {$total}");
        $this->line("  Dirujuk panduan   : {$done} ({$percent}%)");
        $this->line('  Belum punya panduan: ' . ($total - $done));
        $this->line('  Jumlah panduan    : ' . count(GuideCatalog::all()));

        if ($this->option('list')) {
            $this->newLine();
            $this->info('Menu yang belum punya panduan');

            foreach ($menus->reject(fn (array $m) => $covered->has($m['name']))->groupBy('group') as $group => $items) {
                $this->line("  {$group} (" . $items->count() . '): ' . $items->pluck('label')->implode(', '));
            }
        }

        return self::SUCCESS;
    }

    private function groupOf(string $class): string
    {
        try {
            $cluster = method_exists($class, 'getCluster') ? $class::getCluster() : null;

            if ($cluster) {
                return (string) $cluster::getNavigationLabel();
            }

            return (string) ($class::getNavigationGroup() ?: 'Tanpa grup');
        } catch (\Throwable) {
            return 'Tanpa grup';
        }
    }

    private function labelOf(string $class): string
    {
        try {
            return (string) $class::getNavigationLabel();
        } catch (\Throwable) {
            return class_basename($class);
        }
    }
}
