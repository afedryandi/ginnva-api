<?php

namespace App\Exports;

use App\Models\StoreReview;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Kepuasan Pelanggan" (CustomerSatisfactionReport) — audit
 * 2026-09-12, temuan B. Ringkasan + Per Toko + Ulasan digabung 1 sheet,
 * sama pola SerialNumberReportExport/PersediaanDetailReportExport.
 */
class CustomerSatisfactionReportExport implements FromArray, WithStyles
{
    private const SENTIMENT_LABEL = [
        'positive' => 'Positif',
        'negative' => 'Negatif',
        'neutral' => 'Netral',
    ];

    public function __construct(private array $result) {}

    public function array(): array
    {
        $rows = [
            ['RINGKASAN'],
            ['Total Review', $this->result['total']],
            ['Positif', $this->result['positive']],
            ['Netral', $this->result['neutral']],
            ['Negatif', $this->result['negative']],
            ['Tingkat Positif (%)', round($this->result['positiveRate'], 1)],
        ];

        $rows[] = [];
        $rows[] = ['PER TOKO'];
        $rows[] = ['Toko', 'Total Review', 'Positif', 'Negatif'];
        foreach ($this->result['byStore'] as $storeName => $row) {
            $rows[] = [$storeName, $row['total'], $row['positive'], $row['negative']];
        }

        $rows[] = [];
        $rows[] = ['ULASAN PELANGGAN'];
        $rows[] = ['Tanggal', 'Toko', 'Pelanggan', 'Sentiment', 'Tag', 'Komentar'];
        foreach ($this->result['reviews'] as $review) {
            /** @var StoreReview $review */
            $rows[] = [
                $review->created_at->format('Y-m-d'),
                $review->store?->name ?? '-',
                $review->customer?->name ?? 'Pelanggan',
                self::SENTIMENT_LABEL[$review->sentiment] ?? $review->sentiment,
                implode(', ', $review->tags ?? []),
                $review->comment ?: '-',
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
        ];
    }
}
