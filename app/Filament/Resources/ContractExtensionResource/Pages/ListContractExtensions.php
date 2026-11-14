<?php

namespace App\Filament\Resources\ContractExtensionResource\Pages;

use App\Filament\Resources\ContractExtensionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListContractExtensions extends ListRecords
{
    protected static string $resource = ContractExtensionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('makePermanent')
                ->label('Angkat Jadi Karyawan Tetap')
                ->icon('heroicon-o-check-badge')
                ->color('gray')
                ->visible(fn () => ContractExtensionResource::canCreate())
                ->modalHeading('Angkat Jadi Karyawan Tetap')
                ->modalDescription('Tipe karyawan diganti dan tanggal akhir kontrak dikosongkan, jadi tidak lagi diingatkan atau dinonaktifkan otomatis. Tercatat di Riwayat Aktivitas.')
                ->form([
                    \Filament\Forms\Components\Select::make('user_id')
                        ->label('Karyawan')
                        ->options(fn () => ContractExtensionResource::employeeOptions())
                        ->searchable()
                        ->required(),
                    \Filament\Forms\Components\Select::make('employee_type_id')
                        ->label('Tipe karyawan baru')
                        ->options(fn () => \App\Models\EmployeeType::where('is_active', true)->where('has_end_date', false)->orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->native(false),
                    \Filament\Forms\Components\Textarea::make('notes')->label('Catatan (opsional)')->rows(2),
                ])
                ->action(function (array $data) {
                    $employee = \App\Models\ContractExtension::eligibleEmployees(auth()->user())->whereKey($data['user_id'])->first();

                    if (! $employee) {
                        \Filament\Notifications\Notification::make()->title('Tidak bisa disimpan')
                            ->body('Karyawan ini tidak termasuk yang bisa diangkat oleh akun ini: harus aktif, bertipe kontrak, dan dalam wewenang akun ini.')->danger()->send();

                        return;
                    }

                    try {
                        $updated = \App\Models\ContractExtension::convertToPermanent($employee, \App\Models\EmployeeType::findOrFail($data['employee_type_id']), auth()->id(), $data['notes'] ?? null);
                    } catch (\InvalidArgumentException $e) {
                        \Filament\Notifications\Notification::make()->title('Tidak bisa disimpan')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    app(\App\Services\StaffNotifier::class)->toUsers(
                        [$updated->id],
                        'Status Kepegawaian Berubah',
                        "Status sekarang karyawan tetap ({$updated->employeeType->name}). Kontrak kerja tidak lagi berbatas waktu."
                    );

                    \Filament\Notifications\Notification::make()->title('Diangkat jadi karyawan tetap')
                        ->body("{$updated->name} kini {$updated->employeeType->name}." . ($updated->wasChanged('is_active') ? ' Akunnya diaktifkan kembali.' : ''))->success()->send();
                }),

            Actions\CreateAction::make()
                ->label('Perpanjang Kontrak'),
        ];
    }
}
