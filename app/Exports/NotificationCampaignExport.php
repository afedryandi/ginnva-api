<?php

namespace App\Exports;

use App\Models\NotificationCampaign;
use App\Services\NotificationCampaignService;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Kampanye notifikasi beserta hasil pengirimannya. */
class NotificationCampaignExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function query(): Builder
    {
        return NotificationCampaign::query()
            ->with('creator:id,name')
            ->withCount([
                'deliveries as delivered_count' => fn ($q) => $q->where('status', \App\Models\NotificationDelivery::DELIVERED),
                'deliveries as problem_count' => fn ($q) => $q->whereIn('status', [\App\Models\NotificationDelivery::FAILED, \App\Models\NotificationDelivery::DEAD_TOKEN]),
            ])
            ->orderByDesc('created_at');
    }

    public function headings(): array
    {
        return ['Waktu', 'Judul', 'Untuk', 'Jenis', 'Sasaran', 'Status', 'Penerima', 'Dilewati', 'Diterima Expo', 'Terkirim', 'Gagal / Token Mati', 'Dibuat Oleh'];
    }

    public function map($campaign): array
    {
        $target = $campaign->audience === 'partner'
            ? (empty($campaign->partner_ids) ? 'Semua partner' : count($campaign->partner_ids) . ' partner terpilih')
            : (! empty($campaign->segment)
                ? 'Segmen: ' . NotificationCampaignService::segmentLabel($campaign->segment)
                : (empty($campaign->customer_ids) ? 'Semua pelanggan' : count($campaign->customer_ids) . ' pelanggan terpilih'));

        return [
            ($campaign->sent_at ?? $campaign->scheduled_at ?? $campaign->created_at)?->format('d/m/Y H:i'),
            $campaign->title,
            $campaign->audience === 'partner' ? 'Partner' : 'Pelanggan',
            $campaign->audience === 'partner' ? '—' : ($campaign->category === 'info' ? 'Informasi' : 'Promosi'),
            $target,
            NotificationCampaign::STATUS_LABELS[$campaign->status] ?? $campaign->status,
            $campaign->recipients,
            $campaign->skipped,
            $campaign->devices_sent,
            $campaign->delivered_count,
            $campaign->problem_count,
            $campaign->creator?->name,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
