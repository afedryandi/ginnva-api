<?php

namespace App\Console\Commands;

use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Daftar menu panel admin yang tabel datanya masih kosong, dikelompokkan menurut kluster dan grup menu. Hanya membaca (menghitung baris),
 * aman dijalankan di server mana pun. Berguna sebelum demo atau presentasi: menu yang kosong tidak punya apa-apa untuk ditunjukkan.
 *
 * Yang dihitung adalah Resource (menu berbasis tabel). Halaman laporan tidak punya tabel sendiri; isinya mengikuti data sumbernya, jadi laporan
 * kosong biasanya berarti sumbernya (booking selesai, jurnal, absensi, dan seterusnya) yang kosong.
 */
class CheckEmptyMenus extends Command
{
    protected $signature = 'data:cek-kosong
        {--semua : Tampilkan juga menu yang sudah berisi}
        {--panel=admin : ID panel Filament}';

    protected $description = 'Daftar menu panel admin yang datanya masih kosong';

    public function handle(): int
    {
        $panel = Filament::getPanel((string) $this->option('panel'));
        $rows = [];

        foreach ($panel->getResources() as $resource) {
            $model = $resource::getModel();

            try {
                /** @var class-string<Model> $model */
                $count = $model::withoutGlobalScopes()->count();
                $error = null;
            } catch (\Throwable $e) {
                $count = null;
                $error = class_basename($e);
            }

            $cluster = $resource::getCluster();

            $rows[] = [
                'cluster' => $cluster ? preg_replace('/Cluster$/', '', class_basename($cluster)) : '-',
                'group' => $resource::getNavigationGroup() ?: '-',
                'label' => $resource::getNavigationLabel(),
                'model' => class_basename($model),
                'count' => $count,
                'error' => $error,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['cluster'], $a['group'], $a['label']] <=> [$b['cluster'], $b['group'], $b['label']]);

        $empty = array_values(array_filter($rows, fn (array $r) => $r['count'] === 0));
        $failed = array_values(array_filter($rows, fn (array $r) => $r['count'] === null));
        $shown = $this->option('semua') ? $rows : $empty;

        $this->table(
            ['Kluster', 'Grup', 'Menu', 'Tabel (model)', 'Jumlah baris'],
            array_map(fn (array $r) => [$r['cluster'], $r['group'], $r['label'], $r['model'], $r['count'] ?? 'galat: ' . $r['error']], $shown),
        );

        $this->info(count($rows) . ' menu diperiksa: ' . count($empty) . ' kosong, ' . (count($rows) - count($empty) - count($failed)) . ' berisi' . ($failed ? ', ' . count($failed) . ' tidak bisa dihitung' : '') . '.');

        return self::SUCCESS;
    }
}
