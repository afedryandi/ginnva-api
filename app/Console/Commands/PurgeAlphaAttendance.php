<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Menghapus baris absensi bertipe Alpha yang terlanjur direkam (mis. karyawan baru yang absensinya belum diwajibkan). Bawaannya hanya
 * MENAMPILKAN apa yang akan dihapus (per karyawan dan bulan, plus Payroll yang sudah memakai Alpha itu); data baru terhapus dengan --apply.
 * --before wajib supaya Alpha terbaru tidak terhapus tanpa disengaja. Payroll yang sudah dibuat tidak diubah (angkanya sudah tersimpan).
 */
class PurgeAlphaAttendance extends Command
{
    protected $signature = 'attendance:purge-alpha
        {--before= : Hapus Alpha SEBELUM tanggal ini (YYYY-MM-DD, tanggal itu sendiri tidak ikut). Wajib}
        {--email=* : Batasi ke karyawan ini saja (boleh diulang)}
        {--apply : Benar-benar menghapus; tanpa ini hanya menampilkan ringkasan}
        {--force : Lewati konfirmasi interaktif}';

    protected $description = 'Hapus baris Alpha yang terlanjur direkam (ringkasan dulu, hapus dengan --apply)';

    public function handle(): int
    {
        $before = $this->option('before');

        if (! $before || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $before)) {
            $this->error('Isi --before=YYYY-MM-DD (Alpha sebelum tanggal ini yang dihapus).');

            return self::FAILURE;
        }

        $cutoff = Carbon::parse($before)->toDateString();

        $query = Attendance::where('entry_type', 'alpha')->whereDate('date', '<', $cutoff);

        $emails = array_filter((array) $this->option('email'));
        if ($emails) {
            $userIds = User::whereIn('email', $emails)->pluck('id');
            if ($userIds->count() !== count(array_unique($emails))) {
                $this->error('Ada email yang tidak ditemukan. Periksa penulisannya.');

                return self::FAILURE;
            }
            $query->whereIn('user_id', $userIds);
        }

        $rows = (clone $query)->get(['id', 'user_id', 'date']);

        if ($rows->isEmpty()) {
            $this->info("Tidak ada baris Alpha sebelum {$cutoff}.");

            return self::SUCCESS;
        }

        $names = User::whereIn('id', $rows->pluck('user_id')->unique())->pluck('name', 'id');
        $table = $rows->groupBy(fn ($r) => $r->user_id . '|' . Carbon::parse($r->date)->format('Y-m'))
            ->map(fn ($group, $key) => [
                $names[(int) explode('|', $key)[0]] ?? '(akun terhapus)',
                explode('|', $key)[1],
                $group->count(),
            ])
            ->sortBy(fn ($row) => $row[0] . $row[1])
            ->values()
            ->all();

        $this->table(['Karyawan', 'Bulan', 'Baris Alpha'], $table);
        $this->info("Total {$rows->count()} baris Alpha sebelum {$cutoff}.");

        $payrolls = Payroll::where('alpha_days', '>', 0)
            ->whereIn('user_id', $rows->pluck('user_id')->unique())
            ->get(['user_id', 'period_month', 'alpha_days', 'status']);

        if ($payrolls->isNotEmpty()) {
            $this->warn('Payroll berikut sudah memuat potongan Alpha. Menghapus baris Alpha TIDAK mengubahnya; periksa lagi sebelum disetujui:');
            $this->table(
                ['Karyawan', 'Periode', 'Hari Alpha', 'Status'],
                $payrolls->map(fn ($p) => [$names[$p->user_id] ?? $p->user_id, Carbon::parse($p->period_month)->format('Y-m'), $p->alpha_days, $p->status])->all()
            );
        }

        if (! $this->option('apply')) {
            $this->comment('Belum ada yang dihapus. Tambahkan --apply untuk menghapus.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Hapus {$rows->count()} baris Alpha ini secara permanen?")) {
            $this->comment('Dibatalkan.');

            return self::SUCCESS;
        }

        $deleted = DB::transaction(fn () => Attendance::whereIn('id', $rows->pluck('id'))->where('entry_type', 'alpha')->delete());

        activity('attendance')->log("{$deleted} baris Alpha sebelum {$cutoff} dihapus lewat attendance:purge-alpha");

        $this->info("{$deleted} baris Alpha dihapus.");

        return self::SUCCESS;
    }
}
