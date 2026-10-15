<?php

namespace App\Services;

use App\Models\PartnershipInquiry;
use App\Models\ProductInquiry;
use App\Models\Quotation;
use App\Support\LeadAttribution;
use App\Support\LeadOutcome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ringkasan corong lead untuk "Dasbor Lead" (Marketing/Konten Fase 1, 2026-10-23): tiga tipe lead (quotation, inquiry produk, kemitraan)
 * digabung menjadi satu daftar baris seragam lalu dikelompokkan per kanal, per kampanye, dan per tipe.
 *
 * Patokan periode: waktu lead masuk (created_at). Hasil: kolom outcome; khusus kemitraan, bila outcome kosong, status "deal" dibaca
 * Menang dan "rejected" dibaca Kalah (status itu sudah ada sebelum kolom hasil). Waktu respons = contacted_at - created_at, hanya untuk
 * lead yang sudah ditindak. Cakupan data mengikuti global scope tiap model (staf hanya melihat yang berhak dilihatnya).
 */
class LeadFunnelService
{
    public const TYPES = [
        'quotation' => 'Quotation',
        'product_inquiry' => 'Inquiry Produk',
        'partnership' => 'Kemitraan',
    ];

    /**
     * @param  ?string  $type  salah satu kunci TYPES, atau null = semua
     * @return array{
     *   sla_hours: int,
     *   totals: array<string, mixed>,
     *   by_type: array<int, array<string, mixed>>,
     *   by_channel: array<int, array<string, mixed>>,
     *   by_campaign: array<int, array<string, mixed>>,
     *   lost_reasons: array<int, array{reason: string, total: int}>
     * }
     */
    public function summarize(Carbon $from, Carbon $to, ?string $type = null): array
    {
        $rows = $this->rows($from, $to, $type);
        $sla = (int) config('marketing.lead_response_sla_hours', 24);

        return [
            'sla_hours' => $sla,
            'totals' => $this->metrics($rows, $sla),
            'by_type' => $this->grouped($rows, 'type', fn (string $k) => self::TYPES[$k] ?? $k, $sla),
            'by_channel' => $this->grouped($rows, 'channel', fn (string $k) => LeadAttribution::CHANNELS[$k] ?? 'Tidak tercatat', $sla),
            'by_campaign' => array_slice($this->grouped($rows->filter(fn (array $r) => $r['campaign'] !== null), 'campaign', fn (string $k) => $k, $sla), 0, 15),
            'lost_reasons' => $rows->where('outcome', LeadOutcome::LOST)->whereNotNull('reason')->groupBy('reason')
                ->map(fn (Collection $g, string $reason) => ['reason' => LeadOutcome::REASONS[$reason] ?? $reason, 'total' => $g->count()])
                ->sortByDesc('total')->values()->take(8)->all(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(Carbon $from, Carbon $to, ?string $type): Collection
    {
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $rows = collect();

        $sources = [
            'quotation' => fn () => Quotation::query()->whereBetween('created_at', $range)
                ->get(['status', 'channel', 'utm_campaign', 'created_at', 'contacted_at', 'outcome', 'outcome_reason']),
            'product_inquiry' => fn () => ProductInquiry::query()->whereBetween('created_at', $range)
                ->get(['status', 'channel', 'utm_campaign', 'created_at', 'contacted_at', 'outcome', 'outcome_reason']),
            'partnership' => fn () => PartnershipInquiry::query()->whereBetween('created_at', $range)
                ->get(['status', 'channel', 'utm_campaign', 'created_at', 'contacted_at', 'outcome', 'outcome_reason']),
        ];

        foreach ($sources as $key => $load) {
            if ($type !== null && $type !== $key) {
                continue;
            }

            foreach ($load() as $lead) {
                $outcome = $lead->outcome;

                if ($outcome === null && $key === 'partnership') {
                    $outcome = match ($lead->status) {
                        'deal' => LeadOutcome::WON,
                        'rejected' => LeadOutcome::LOST,
                        default => null,
                    };
                }

                $rows->push([
                    'type' => $key,
                    'channel' => $lead->channel ?: 'tidak_tercatat',
                    'campaign' => $lead->utm_campaign ?: null,
                    'status' => $lead->status,
                    'created_at' => $lead->created_at,
                    'contacted_at' => $lead->contacted_at,
                    'outcome' => $outcome,
                    'reason' => $lead->outcome_reason,
                ]);
            }
        }

        return $rows;
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function grouped(Collection $rows, string $by, callable $label, int $sla): array
    {
        return $rows->groupBy($by)
            ->map(fn (Collection $g, string $key) => ['key' => $key, 'label' => $label($key)] + $this->metrics($g, $sla))
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function metrics(Collection $rows, int $sla): array
    {
        $total = $rows->count();
        $waits = $rows->filter(fn (array $r) => $r['contacted_at'] !== null)
            ->map(fn (array $r) => max(0, $r['created_at']->diffInMinutes($r['contacted_at'], false)) / 60)
            ->sort()->values();

        $responded = $waits->count();
        $within = $waits->filter(fn (float $h) => $h <= $sla)->count();

        $median = null;
        if ($responded > 0) {
            $mid = intdiv($responded, 2);
            $median = $responded % 2 ? $waits[$mid] : ($waits[$mid - 1] + $waits[$mid]) / 2;
        }

        $won = $rows->where('outcome', LeadOutcome::WON)->count();
        $lost = $rows->where('outcome', LeadOutcome::LOST)->count();

        $overdue = $rows->filter(fn (array $r) => $r['status'] === 'new' && $r['contacted_at'] === null && $r['created_at']->lte(now()->subHours($sla)))->count();

        return [
            'total' => $total,
            'responded' => $responded,
            'responded_percent' => $total > 0 ? round($responded / $total * 100, 1) : null,
            'median_response_hours' => $median !== null ? round($median, 1) : null,
            'within_sla' => $within,
            'within_sla_percent' => $responded > 0 ? round($within / $responded * 100, 1) : null,
            'overdue_unanswered' => $overdue,
            'won' => $won,
            'lost' => $lost,
            'open' => $total - $won - $lost,
            'win_rate_percent' => ($won + $lost) > 0 ? round($won / ($won + $lost) * 100, 1) : null,
        ];
    }
}
