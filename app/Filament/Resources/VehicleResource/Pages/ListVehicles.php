<?php

namespace App\Filament\Resources\VehicleResource\Pages;

use App\Exports\VehicleExport;
use App\Filament\Resources\VehicleResource;
use App\Services\VehicleImportService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ListVehicles extends ListRecords
{
    protected static string $resource = VehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Export sekaligus template import: format kolomnya sama.
            Actions\Action::make('export')
                ->label('Export Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                ->action(function () {
                    try {
                        activity('report_export')
                            ->causedBy(auth()->user())
                            ->withProperties(['report' => 'vehicle', 'format' => 'xlsx'])
                            ->log('Export Kendaraan (xlsx)');
                    } catch (\Throwable $e) {
                        report($e);
                    }

                    return Excel::download(new VehicleExport(), 'kendaraan-' . now()->format('Ymd') . '.xlsx');
                }),

            Actions\Action::make('import')
                ->label('Import Kendaraan')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn () => VehicleResource::canCreate())
                ->modalHeading('Import katalog kendaraan')
                ->modalDescription('Pakai format dari Export Excel. Kendaraan dicocokkan lewat merek, tipe, dan varian.')
                ->modalSubmitActionLabel('Jalankan')
                ->form([
                    Forms\Components\FileUpload::make('file')
                        ->label('File Excel')
                        ->required()
                        ->disk('local')
                        ->directory('vehicle-imports')
                        ->visibility('private')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                            'text/csv',
                        ]),
                    Forms\Components\Toggle::make('dry_run')
                        ->label('Hanya pratinjau (tidak menyimpan)')
                        ->helperText('Bawaan menyala: hasilnya hanya dilaporkan. Matikan lalu jalankan lagi untuk menyimpan.')
                        ->default(true),
                    Forms\Components\Toggle::make('overwrite')
                        ->label('Perbarui juga kendaraan yang datanya berbeda')
                        ->helperText('Mati: hanya kendaraan baru yang ditambahkan; yang sudah ada tidak diubah.')
                        ->default(false),
                ])
                ->action(function (array $data) {
                    $path = $data['file'];

                    try {
                        $service = app(VehicleImportService::class);
                        $analysis = $service->analyze($service->readRows(Storage::disk('local')->path($path)));
                        $s = $analysis['summary'];
                        $problems = collect($analysis['problems'])->take(8)->implode("\n");
                        $more = count($analysis['problems']) > 8 ? "\n… dan " . (count($analysis['problems']) - 8) . ' masalah lain.' : '';
                        $summary = "{$s['total_rows']} baris: {$s['new']} baru, {$s['changed']} berbeda, {$s['unchanged']} sama, {$s['invalid']} bermasalah, {$s['duplicates']} ganda.";

                        if ($data['dry_run'] ?? true) {
                            Notification::make()->title('Pratinjau import')->body($summary . ($problems !== '' ? "\n" . $problems . $more : '') . "\nBelum ada yang disimpan.")->info()->persistent()->send();

                            return;
                        }

                        $result = $service->import($analysis['entries'], (bool) ($data['overwrite'] ?? false));

                        Notification::make()
                            ->title('Import selesai')
                            ->body("{$result['created']} ditambahkan, {$result['updated']} diperbarui, {$result['skipped']} dilewati. " . $summary . ($problems !== '' ? "\n" . $problems . $more : ''))
                            ->success()
                            ->persistent()
                            ->send();
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->title('Import gagal')->body($e->getMessage())->danger()->send();
                    } finally {
                        Storage::disk('local')->delete($path);
                    }
                }),

            Actions\CreateAction::make(),
        ];
    }
}
