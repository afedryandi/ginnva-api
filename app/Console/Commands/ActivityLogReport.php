<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Laporan ukuran dan pertumbuhan activity_log (Histori Aktivitas Fase 4, 2026-10-21). Hanya membaca. Retensi log sengaja tanpa batas
 * (keputusan pemilik 2026-10-21), jadi perintah ini menjadi dasar meninjau keputusan itu dengan angka nyata: berapa baris, berapa MB,
 * modul mana yang paling banyak, dan seberapa cepat tabel tumbuh.
 */
class ActivityLogReport extends Command
{
    protected $signature = 'activity:size-report {--top=15 : Jumlah modul teratas yang ditampilkan}';

    protected $description = 'Laporan baca-saja ukuran dan pertumbuhan activity_log (Histori Aktivitas)';

    public function handle(): int
    {
        $table = config('activitylog.table_name', 'activity_log');
        $connection = config('activitylog.database_connection');
        $db = DB::connection($connection);

        $total = (int) $db->table($table)->count();

        if ($total === 0) {
            $this->info('Histori Aktivitas masih kosong.');

            return self::SUCCESS;
        }

        $oldest = $db->table($table)->min('created_at');
        $newest = $db->table($table)->max('created_at');
        $days = max(1, (int) ceil((strtotime((string) $newest) - strtotime((string) $oldest)) / 86400));

        $this->info('Ringkasan activity_log');
        $this->line('  Jumlah catatan : ' . number_format($total, 0, ',', '.'));
        $this->line("  Terlama        : {$oldest}");
        $this->line("  Terbaru        : {$newest}");
        $this->line('  Ukuran tabel   : ' . $this->sizeLabel($db, $table));
        $this->line('  Rata-rata      : ' . number_format($total / $days, 1, ',', '.') . ' catatan/hari (selama ' . $days . ' hari)');

        $last30 = (int) $db->table($table)->where('created_at', '>=', now()->subDays(30))->count();
        $this->line('  30 hari terakhir: ' . number_format($last30, 0, ',', '.') . ' catatan (perkiraan setahun: ' . number_format($last30 / 30 * 365, 0, ',', '.') . ')');

        $this->newLine();
        $this->info('Modul terbanyak');
        $rows = $db->table($table)
            ->selectRaw('COALESCE(log_name, \'(tanpa modul)\') as modul, COUNT(*) as jumlah')
            ->groupBy('log_name')
            ->orderByDesc('jumlah')
            ->limit((int) $this->option('top'))
            ->get()
            ->map(fn ($r) => [$r->modul, number_format($r->jumlah, 0, ',', '.'), number_format($r->jumlah / $total * 100, 1, ',', '.') . '%'])
            ->all();
        $this->table(['Modul', 'Catatan', 'Porsi'], $rows);

        $this->newLine();
        $this->info('Per bulan (12 bulan terakhir)');
        $months = $db->table($table)
            ->where('created_at', '>=', now()->subMonths(12)->startOfMonth())
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as bulan, COUNT(*) as jumlah")
            ->groupBy('bulan')
            ->orderBy('bulan')
            ->get()
            ->map(fn ($r) => [$r->bulan, number_format($r->jumlah, 0, ',', '.')])
            ->all();
        $this->table(['Bulan', 'Catatan'], $months);

        return self::SUCCESS;
    }

    private function sizeLabel($db, string $table): string
    {
        try {
            $row = $db->selectOne(
                'SELECT data_length + index_length AS bytes FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$db->getTablePrefix() . $table]
            );

            return $row ? number_format($row->bytes / 1048576, 2, ',', '.') . ' MB' : 'tidak diketahui';
        } catch (\Throwable) {
            return 'tidak diketahui';
        }
    }
}
