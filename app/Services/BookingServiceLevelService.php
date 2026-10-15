<?php

namespace App\Services;

use App\Models\Booking;
use Illuminate\Support\Carbon;

/**
 * Ringkasan tingkat layanan booking (Fase 5 pengerjaan ulang booking, 2026-10-21): seberapa cepat booking dikonfirmasi
 * dibanding target, tingkat tidak-datang, konversi pending → terkonfirmasi, dan alasan pembatalan.
 *
 * Patokan periode: waktu booking diajukan (created_at). Waktu tunggu konfirmasi hanya dihitung untuk booking yang punya
 * confirmed_at (terisi mulai Fase 5; booking lama tidak punya waktu konfirmasi yang andal dan dikecualikan dari rata-rata).
 * Cakupan toko mengikuti global scope Booking (staf toko hanya melihat tokonya); $storeId mempersempit untuk akses penuh.
 */
class BookingServiceLevelService
{
    /**
     * @return array{
     *   created: int, pending: int, reached_confirmed: int, conversion_percent: ?float,
     *   confirmed_measured: int, avg_wait_hours: ?float, median_wait_hours: ?float, within_target: int, within_target_percent: ?float, target_hours: int,
     *   completed: int, no_show: int, no_show_percent: ?float,
     *   cancelled: int, cancelled_percent: ?float, cancelled_by: array<string, int>, cancel_reasons: array<int, array{reason: string, total: int}>
     * }
     */
    public function summarize(Carbon $from, Carbon $to, ?int $storeId = null): array
    {
        $base = fn () => Booking::query()
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId));

        $created = $base()->count();
        $pending = $base()->where('status', 'pending')->count();
        $completed = $base()->where('status', 'completed')->count();
        $noShow = $base()->whereNotNull('no_show_at')->count();
        $cancelled = $base()->where('status', 'cancelled')->whereNull('no_show_at')->count();

        // "Pernah dikonfirmasi": status confirmed/completed, atau punya waktu konfirmasi, atau dibatalkan karena tidak datang
        // (no-show hanya terjadi pada booking terkonfirmasi).
        $reached = $base()->where(function ($q) {
            $q->whereIn('status', ['confirmed', 'completed'])->orWhereNotNull('confirmed_at')->orWhereNotNull('no_show_at');
        })->count();

        $target = max(1, (int) config('booking.confirm_sla_hours', 4));

        $waits = $base()->whereNotNull('confirmed_at')->get(['created_at', 'confirmed_at'])
            ->map(fn (Booking $b) => max(0, $b->created_at->diffInMinutes($b->confirmed_at, false)) / 60)
            ->sort()->values();

        $measured = $waits->count();
        $within = $waits->filter(fn (float $h) => $h <= $target)->count();

        $median = null;
        if ($measured > 0) {
            $mid = intdiv($measured, 2);
            $median = $measured % 2 ? $waits[$mid] : ($waits[$mid - 1] + $waits[$mid]) / 2;
        }

        $cancelledBase = fn () => $base()->where('status', 'cancelled')->whereNull('no_show_at');

        return [
            'created' => $created,
            'pending' => $pending,
            'reached_confirmed' => $reached,
            'conversion_percent' => $created > 0 ? round($reached / $created * 100, 1) : null,
            'confirmed_measured' => $measured,
            'avg_wait_hours' => $measured > 0 ? round($waits->avg(), 1) : null,
            'median_wait_hours' => $median !== null ? round($median, 1) : null,
            'within_target' => $within,
            'within_target_percent' => $measured > 0 ? round($within / $measured * 100, 1) : null,
            'target_hours' => $target,
            'completed' => $completed,
            'no_show' => $noShow,
            // Tidak-datang dibanding booking terkonfirmasi yang sudah berakhir (selesai atau tidak datang).
            'no_show_percent' => ($completed + $noShow) > 0 ? round($noShow / ($completed + $noShow) * 100, 1) : null,
            'cancelled' => $cancelled,
            'cancelled_percent' => $created > 0 ? round($cancelled / $created * 100, 1) : null,
            'cancelled_by' => $cancelledBase()->selectRaw("COALESCE(cancelled_by_type, 'tidak tercatat') as who, COUNT(*) as total")
                ->groupBy('who')->pluck('total', 'who')->map(fn ($n) => (int) $n)->all(),
            'cancel_reasons' => $cancelledBase()->whereNotNull('cancel_reason')
                ->selectRaw('cancel_reason as reason, COUNT(*) as total')->groupBy('cancel_reason')->orderByDesc('total')->limit(8)->get()
                ->map(fn ($r) => ['reason' => (string) $r->reason, 'total' => (int) $r->total])->all(),
        ];
    }
}
