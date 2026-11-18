<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Cetak PDF umum untuk SATU data di menu mana pun (panel admin). Isi PDF diambil dari form halaman edit/lihat menu itu, jadi judul
 * kolom dan pilihannya sama dengan yang dilihat staf; kolom yang disembunyikan form untuk data itu tidak ikut tercetak. Kalau form
 * tidak bisa dimuat di luar halaman, dipakai kolom model apa adanya. PDF khusus yang sudah ada (invoice, quotation, SPK, dll.) tidak
 * tergantikan.
 */
class RecordPdf
{
    /** Kolom model yang tidak pernah dicetak pada cadangan. */
    private const HIDDEN_ATTRIBUTES = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'api_token', 'token', 'updated_at', 'deleted_at'];

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    public static function render(string $resource, Model $record)
    {
        $sections = self::sectionsFromForm($resource, $record) ?? self::sectionsFromAttributes($record);

        // Logo Ginnva (lambang + kata, 600 px) seperti kop cetakan SPK. Jangan pakai ginnva-logo.png (4445 px): dompdf menguraikannya jadi puluhan MB dan memori habis.
        $logoPath = public_path('images/ginnva-wordmark-merah.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath)) : null;

        $title = trim((string) $resource::getRecordTitle($record));
        $label = (string) $resource::getModelLabel();

        return Pdf::loadView('pdf.record_detail', [
            'logo' => $logo,
            'label' => $label,
            'title' => $title !== '' ? $title : '#' . $record->getKey(),
            'sections' => $sections,
            'printedBy' => auth()->user()?->name,
            'printedAt' => now()->locale('id')->translatedFormat('d F Y H:i'),
        ])->setPaper('a4');
    }

    /** @return list<array{heading: string, rows: list<array{label: string, value: string, table?: array}>}>|null */
    private static function sectionsFromForm(string $resource, Model $record): ?array
    {
        try {
            $pages = $resource::getPages();
            $registration = $pages['edit'] ?? $pages['view'] ?? null;

            if ($registration === null) {
                return null;
            }

            $class = $registration->getPage();
            $page = app('livewire')->new($class);
            $page->mount($record->getKey());

            $form = $page->getForm('form');

            if ($form === null) {
                return null;
            }

            $groups = [];

            foreach ($form->getFlatFields(withHidden: false) as $field) {
                if (! $field instanceof Field || $field instanceof Hidden || $field instanceof FileUpload) {
                    continue;
                }

                if ($field instanceof TextInput && $field->isPassword()) {
                    continue;
                }

                $row = self::rowFor($field);

                if ($row === null) {
                    continue;
                }

                $groups[self::headingFor($field)][] = $row;
            }

            if ($groups === []) {
                return null;
            }

            return collect($groups)->map(fn (array $rows, string $heading) => ['heading' => $heading, 'rows' => $rows])->values()->all();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function headingFor(Field $field): string
    {
        $parent = $field->getContainer()->getParentComponent();

        while ($parent !== null) {
            if ($parent instanceof Section) {
                $heading = $parent->getHeading();

                if (filled($heading)) {
                    return (string) $heading;
                }
            }

            $parent = $parent->getContainer()->getParentComponent();
        }

        return 'Detail';
    }

    /** @return array{label: string, value: string, table?: array}|null */
    private static function rowFor(Field $field): ?array
    {
        $label = trim(strip_tags((string) $field->getLabel()));
        $state = $field->getRawState();

        if ($label === '') {
            return null;
        }

        if ($field instanceof Repeater) {
            return self::repeaterRow($field, $label);
        }

        $value = self::formatState($field, $state);

        return $value === null ? null : ['label' => $label, 'value' => $value];
    }

    private static function repeaterRow(Repeater $field, string $label): ?array
    {
        $rows = [];
        $columns = [];

        foreach ($field->getChildComponentContainers() as $container) {
            $line = [];

            foreach ($container->getFlatFields(withHidden: false) as $child) {
                if (! $child instanceof Field || $child instanceof Hidden || $child instanceof FileUpload) {
                    continue;
                }

                $childLabel = trim(strip_tags((string) $child->getLabel()));
                $value = self::formatState($child, $child->getRawState());

                if ($childLabel === '' || $value === null) {
                    continue;
                }

                $columns[$childLabel] = true;
                $line[$childLabel] = $value;
            }

            if ($line !== []) {
                $rows[] = $line;
            }
        }

        if ($rows === []) {
            return null;
        }

        return ['label' => $label, 'value' => '', 'table' => ['columns' => array_keys($columns), 'rows' => $rows]];
    }

    private static function formatState(Field $field, mixed $state): ?string
    {
        if ($field instanceof Toggle || $field instanceof Checkbox) {
            return $state ? 'Ya' : 'Tidak';
        }

        if ($state === null || $state === '' || $state === []) {
            return null;
        }

        if ($field instanceof Select || $field instanceof Radio || $field instanceof CheckboxList) {
            return self::optionLabels($field, $state);
        }

        if ($field instanceof DateTimePicker || $field instanceof DatePicker) {
            try {
                $date = Carbon::parse($state)->locale('id');

                return $field instanceof DateTimePicker && ! $field instanceof DatePicker
                    ? $date->translatedFormat('d F Y H:i')
                    : $date->translatedFormat('d F Y');
            } catch (\Throwable) {
                return (string) $state;
            }
        }

        return self::scalar($state);
    }

    private static function optionLabels(Field $field, mixed $state): ?string
    {
        $labels = [];

        try {
            if ($field instanceof Select && $field->isMultiple()) {
                $labels = array_values((array) $field->getOptionLabels());
            } elseif ($field instanceof Select) {
                $labels = [$field->getOptionLabel()];
            } else {
                $options = $field->getOptions();
                $labels = array_map(fn ($key) => $options[$key] ?? $key, (array) $state);
            }
        } catch (\Throwable) {
            $labels = (array) $state;
        }

        $labels = array_filter(
            array_map(fn ($label) => is_scalar($label) ? trim(strip_tags((string) $label)) : '', $labels),
            fn ($label) => $label !== '',
        );

        return $labels === [] ? null : implode(', ', $labels);
    }

    private static function scalar(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'Ya' : 'Tidak';
        }

        if (is_array($value)) {
            $flat = array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $value), fn ($v) => $v !== null && $v !== '');

            return $flat === [] ? null : implode(', ', $flat);
        }

        if ($value instanceof CarbonInterface) {
            return $value->locale('id')->translatedFormat('d F Y H:i');
        }

        return is_scalar($value) ? trim((string) $value) : null;
    }

    /** @return list<array{heading: string, rows: list<array{label: string, value: string}>}> */
    private static function sectionsFromAttributes(Model $record): array
    {
        $rows = [];

        foreach ($record->attributesToArray() as $key => $value) {
            if (in_array($key, self::HIDDEN_ATTRIBUTES, true) || $value === null || $value === '' || $value === []) {
                continue;
            }

            $text = self::scalar($value);

            if ($text === null) {
                continue;
            }

            $rows[] = ['label' => Str::headline($key), 'value' => $text];
        }

        return [['heading' => 'Detail', 'rows' => $rows]];
    }
}
