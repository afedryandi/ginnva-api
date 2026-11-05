<?php

namespace App\Exports;

use App\Models\StoreReview;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Kepuasan Pelanggan" (CustomerSatisfactionReport): ringkasan, tag menurut nada, tren, per toko, dan SEMUA ulasan pada rentang
 * (tidak mengikuti saringan daftar di layar). FromArray karena sumbernya hasil getResult(), bukan Eloquent Builder biasa.
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
        $r = $this->result;

        $rows = [
            ['RINGKASAN'],
            ['Total Review', $r['total']],
            ['Positif', $r['positive']],
            ['Netral', $r['neutral']],
            ['Negatif', $r['negative']],
            ['Tingkat Positif (%)', round($r['positiveRate'], 1)],
            ['Skor Bersih (-100 sampai 100)', round($r['netScore'], 1)],
            ['Tingkat Respons (%)', $r['responseRate'] !== null ? round($r['responseRate'], 1) : '-'],
            ['Booking Selesai pada Rentang', $r['completedBookings']],
            ['Negatif Belum Ditindaklanjuti', $r['unfollowedNegativeCount']],
            ['Rata-rata Lama Tindak Lanjut (jam)', $r['avgFollowUpHours'] !== null ? round($r['avgFollowUpHours'], 1) : '-'],
        ];

        $rows[] = [];
        $rows[] = ['ASPEK YANG DISUKAI'];
        $rows[] = ['Aspek', 'Jumlah'];
        foreach ($r['tagGroups']['positive'] as $tag => $count) {
            $rows[] = [StoreReview::tagLabel((string) $tag), $count];
        }

        $rows[] = [];
        $rows[] = ['ASPEK YANG PERLU DIPERBAIKI'];
        $rows[] = ['Aspek', 'Jumlah'];
        foreach ($r['tagGroups']['negative'] as $tag => $count) {
            $rows[] = [StoreReview::tagLabel((string) $tag), $count];
        }

        $rows[] = [];
        $rows[] = [$r['trendMonthly'] ? 'TREN PER BULAN' : 'TREN PER MINGGU'];
        $rows[] = [$r['trendMonthly'] ? 'Bulan' : 'Minggu', 'Total', 'Positif', 'Negatif', 'Skor Bersih'];
        foreach ($r['trend'] as $row) {
            $rows[] = [$row['label'], $row['total'], $row['positive'], $row['negative'], round($row['net'], 1)];
        }

        $rows[] = [];
        $rows[] = ['PER TOKO'];
        $rows[] = ['Toko', 'Total Review', 'Positif', 'Netral', 'Negatif', '% Positif', 'Skor Bersih', 'Belum Ditindaklanjuti'];
        foreach ($r['byStore'] as $storeName => $row) {
            $rows[] = [$storeName, $row['total'], $row['positive'], $row['neutral'], $row['negative'], round($row['positiveRate'], 1), round($row['net'], 1), $row['unfollowed']];
        }

        $rows[] = [];
        $rows[] = ['ULASAN PELANGGAN'];
        $rows[] = ['Tanggal', 'Toko', 'Pelanggan', 'Sentiment', 'Tag', 'Komentar', 'No. Booking', 'Layanan', 'Tindak Lanjut'];
        foreach ($r['reviews'] as $review) {
            /** @var StoreReview $review */
            $rows[] = [
                $review->created_at->format('Y-m-d'),
                $review->store?->name ?? '-',
                $review->customer?->name ?? 'Pelanggan',
                self::SENTIMENT_LABEL[$review->sentiment] ?? $review->sentiment,
                implode(', ', $review->tagLabels()),
                $review->comment ?: '-',
                $review->booking?->booking_number ?? '-',
                $review->booking?->salesProductLabel() ?? '-',
                $review->followed_up_at ? 'Sudah' : ($review->sentiment === 'negative' ? 'Belum' : '-'),
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
