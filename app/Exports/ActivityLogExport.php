<?php

namespace App\Exports;

use App\Filament\Resources\ActivityResource;
use App\Support\ActivityPresenter;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Activitylog\Models\Activity;

/**
 * Ekspor Histori Aktivitas (Histori Aktivitas Fase 5, 2026-10-21): isi sama dengan layar (filter, pencarian, dan tab yang sedang
 * aktif), dengan label Indonesia dan waktu WIB. Pelaku ekspor dan jumlah barisnya dicatat oleh pemanggil (report_export).
 *
 * Teks yang berawalan = + - @ diberi tanda kutip di depan agar tidak dibaca Excel sebagai rumus (injeksi rumus lewat nama atau
 * deskripsi yang ditulis pihak lain).
 */
class ActivityLogExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    /** Batas baris per ekspor; lebih dari ini diminta mempersempit filter. Dapat diubah di tes. */
    public static int $maxRows = 50000;

    public function __construct(private ?Builder $query = null) {}

    public function query(): Builder
    {
        return ($this->query ?? Activity::query())->with('causer')->reorder('created_at', 'desc')->orderBy('id', 'desc');
    }

    public function headings(): array
    {
        return ['Waktu (WIB)', 'Pelaku', 'Jenis Pelaku', 'Modul', 'Aksi', 'Objek', 'Deskripsi', 'Asal', 'Alamat IP', 'Perangkat', 'Nilai Sebelumnya', 'Nilai Baru'];
    }

    /**
     * @param  Activity  $activity
     */
    public function map($activity): array
    {
        $properties = $activity->properties;
        $actorType = match (true) {
            $activity->causer_type === null => 'Sistem',
            $activity->causer_type === \App\Models\User::class => 'Staf',
            default => ActivityPresenter::actorNote($activity) ?? 'Lainnya',
        };

        return array_map([self::class, 'safe'], [
            $activity->created_at?->copy()->setTimezone(ActivityPresenter::TIMEZONE)->format('Y-m-d H:i:s'),
            $activity->causer?->name ?? ($activity->causer_type ? '(akun dihapus)' : 'Sistem'),
            $actorType,
            ActivityResource::logNameLabel($activity->log_name),
            ActivityPresenter::eventLabel($activity->event),
            $activity->subject_type ? ActivityPresenter::subjectLabel($activity) : '—',
            ActivityPresenter::describe($activity),
            ActivityPresenter::sourceLabel(data_get($properties, 'context.source')),
            data_get($properties, 'context.ip'),
            data_get($properties, 'context.device'),
            self::pairs($properties['old'] ?? []),
            self::pairs($properties['attributes'] ?? []),
        ]);
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1:L1')->getFont()->setBold(true);
        $sheet->getStyle('A1:L1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        $sheet->freezePane('A2');

        foreach (['A' => 20, 'B' => 22, 'C' => 12, 'D' => 26, 'E' => 14, 'F' => 22, 'G' => 60, 'H' => 22, 'I' => 16, 'J' => 40, 'K' => 50, 'L' => 50] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        return [];
    }

    /** "Kolom: nilai; Kolom: nilai" dari properties.old / properties.attributes. */
    private static function pairs(mixed $values): string
    {
        $rows = ActivityPresenter::rows($values);

        return implode('; ', array_map(fn ($label, $value) => "{$label}: {$value}", array_keys($rows), $rows));
    }

    /** Cegah injeksi rumus: sel teks yang diawali = + - @ diberi tanda kutip di depan. */
    public static function safe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@'], true) ? "'" . $value : $value;
    }
}
