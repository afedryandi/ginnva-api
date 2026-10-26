<?php

namespace App\Filament\Pages\Concerns;

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
 */
trait ExportsReportTable
{
    /** @return array{key: string, title: string, subtitle?: ?string, headings: list<string>, rows: list<list<mixed>>, totals?: ?list<mixed>, right?: list<int>, note?: ?string} */
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

                    return Excel::download(new SimpleTableExport($data), $data['key'] . '-' . now()->format('Ymd-His') . '.xlsx');
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $data = $this->reportExportData();
                    $this->logReportExport($data, 'pdf');

                    $format = fn ($cell) => $cell === null || $cell === ''
                        ? '—'
                        : (is_int($cell) || is_float($cell) ? ($cell < 0 ? '-' : '') . 'Rp' . number_format(abs($cell), 0, ',', '.') : (string) $cell);

                    $view = $data + [
                        'pdf_rows' => array_map(fn (array $row) => array_map($format, $row), $data['rows']),
                        'pdf_totals' => ! empty($data['totals']) ? array_map(fn ($cell) => $cell === null || $cell === '' ? '' : $format($cell), $data['totals']) : null,
                    ];

                    $pdf = Pdf::loadView('pdf.simple_table_report', ['data' => $view])->setPaper('a4', count($data['headings']) > 5 ? 'landscape' : 'portrait');

                    return response()->streamDownload(fn () => print($pdf->output()), $data['key'] . '-' . now()->format('Ymd-His') . '.pdf');
                }),
        ];
    }

    private function logReportExport(array $data, string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => $data['key'], 'format' => $format])
                ->log('Ekspor ' . $data['title'] . ' (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
