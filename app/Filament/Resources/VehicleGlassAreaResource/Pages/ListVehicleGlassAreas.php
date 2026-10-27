<?php

namespace App\Filament\Resources\VehicleGlassAreaResource\Pages;

use App\Filament\Resources\VehicleGlassAreaResource;
use App\Services\GlassAreaImportService;
use Filament\Actions;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListVehicleGlassAreas extends ListRecords
{
    protected static string $resource = VehicleGlassAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('import_from_sheet')
                ->label('Import dari Google Sheet')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                ->modalHeading('Import luas kaca dari Google Sheet "Data Ukuran"')
                ->modalSubmitActionLabel('Import')
                ->form([
                    Placeholder::make('preview')
                        ->label('Pratinjau')
                        ->content(function () {
                            try {
                                $service = app(GlassAreaImportService::class);
                                $result = $service->analyze($service->fetchRows());
                            } catch (\Throwable $e) {
                                return new HtmlString('<span class="text-danger-600">Gagal membaca Google Sheet: ' . e($e->getMessage()) . '</span>');
                            }

                            $s = $result['summary'];
                            $html = "<div class=\"text-sm\">Baris terbaca {$s['total_rows']}: <strong>{$s['new']} baru</strong>, {$s['changed']} berubah dari data di sistem, {$s['unchanged']} sama, {$s['invalid']} bermasalah, {$s['duplicates']} ganda. "
                                . "{$s['without_vehicle']} baris belum punya kendaraan dengan merek+model yang sama di menu Kendaraan.</div>";

                            if ($result['problems'] !== []) {
                                $html .= '<ul class="mt-2 list-disc ps-5 text-xs text-warning-700">';
                                foreach (array_slice($result['problems'], 0, 15) as $problem) {
                                    $html .= '<li>' . e($problem) . '</li>';
                                }
                                if (count($result['problems']) > 15) {
                                    $html .= '<li>… dan ' . (count($result['problems']) - 15) . ' lainnya</li>';
                                }
                                $html .= '</ul>';
                            }

                            return new HtmlString($html);
                        }),
                    Toggle::make('overwrite')
                        ->label('Perbarui juga baris yang nilainya berbeda dari data di sistem')
                        ->helperText('Mati (bawaan): hanya baris baru yang ditambahkan; yang sudah ada tidak diubah.')
                        ->default(false),
                ])
                ->action(function (array $data) {
                    try {
                        $service = app(GlassAreaImportService::class);
                        $analysis = $service->analyze($service->fetchRows());
                        $result = $service->import($analysis['entries'], (bool) ($data['overwrite'] ?? false));
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->title('Import gagal')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Import selesai')
                        ->body("{$result['created']} ditambahkan, {$result['updated']} diperbarui, {$result['skipped']} dilewati.")
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
