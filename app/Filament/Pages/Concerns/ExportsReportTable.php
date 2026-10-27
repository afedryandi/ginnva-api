<?php

namespace App\Filament\Pages\Concerns;

use App\Exports\SectionedTableExport;
use App\Exports\SimpleTableExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Tombol Export ke Excel dan PDF untuk laporan berbentuk tabel sederhana. Halaman cukup mengisi reportExportData() dan
 * memanggil exportActions() dari getHeaderActions(). Tiap ekspor tercatat di riwayat aktivitas (report_export), sama seperti
 * laporan keuangan lainnya.
 *
 * Bentuk data: ['key' => 'saldo-kas-bank', 'title', 'subtitle'?, 'headings' => [...], 'rows' => [[...]], 'totals'? => [...],
 * 'right'? => [indeks kolom rata kanan], 'note'?]. Sel uang berupa angka (float): di Excel tetap angka, di PDF diformat Rp.
 * Sel kosong (null) tampil "—" di PDF.
 *
 * Laporan ringkasan yang terdiri dari beberapa tabel memakai 'sections' => [['title', 'headings', 'rows', 'totals'?, 'right'?, 'money'?]]
 * sebagai ganti headings/rows/totals di tingkat atas. 'money' => false (di tingkat atas atau per bagian) berarti angka adalah jumlah/hitungan,
 * bukan rupiah: di PDF ditampilkan tanpa "Rp".
 */
trait ExportsReportTable
{
    /** @return array{key: string, title: string, subtitle?: ?string, headings?: list<string>, rows?: list<list<mixed>>, totals?: ?list<mixed>, right?: list<int>, money?: bool, sections?: list<array<string, mixed>>, note?: ?string} */
    abstract protected function reportExportData(): array;

    /** @return list<Action> */
    protected function exportActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    $data = $this->reportExportData();
                    $this->logReportExport($data, 'xlsx');

                    return Excel::download(isset($data['sections']) ? new SectionedTableExport($data) : new SimpleTableExport($data), $data['key'] . '-' . now()->format('Ymd-His') . '.xlsx');
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $data = $this->reportExportData();
                    $this->logReportExport($data, 'pdf');

                    $sections = $data['sections'] ?? [[
                        'title' => null,
                        'headings' => $data['headings'],
                        'rows' => $data['rows'],
                        'totals' => $data['totals'] ?? null,
                        'right' => $data['right'] ?? [],
                        'money' => $data['money'] ?? true,
                    ]];

                    $pdfSections = array_map(function (array $section) {
                        $format = $this->pdfCellFormatter($section['money'] ?? true);

                        return [
                            'title' => $section['title'] ?? null,
                            'headings' => $section['headings'],
                            'right' => $section['right'] ?? [],
                            'pdf_rows' => array_map(fn (array $row) => array_map($format, $row), $section['rows']),
                            'pdf_totals' => ! empty($section['totals']) ? array_map(fn ($cell) => $cell === null || $cell === '' ? '' : $format($cell), $section['totals']) : null,
                        ];
                    }, $sections);

                    $widest = max(array_map(fn (array $s) => count($s['headings']), $sections));
                    $pdf = Pdf::loadView('pdf.simple_table_report', ['data' => $data + ['pdf_sections' => $pdfSections]])->setPaper('a4', $widest > 5 ? 'landscape' : 'portrait');

                    return response()->streamDownload(fn () => print($pdf->output()), $data['key'] . '-' . now()->format('Ymd-His') . '.pdf');
                }),
        ];
    }

    private function pdfCellFormatter(bool $money): \Closure
    {
        return function ($cell) use ($money) {
            if ($cell === null || $cell === '') {
                return '—';
            }

            if (is_int($cell) || is_float($cell)) {
                return $money
                    ? ($cell < 0 ? '-' : '') . 'Rp' . number_format(abs($cell), 0, ',', '.')
                    : number_format($cell, is_int($cell) || floor($cell) == $cell ? 0 : 1, ',', '.');
            }

            return (string) $cell;
        };
    }

    protected function reportPercent(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 1, ',', '.') . '%';
    }

    protected function reportHours(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 1, ',', '.') . ' jam';
    }

    private function logReportExport(array $data, string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => $data['key'], 'format' => $format])
                ->log('Export ' . $data['title'] . ' (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
