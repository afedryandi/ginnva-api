<?php

namespace App\Filament\Resources\FinanceCategoryResource\Pages;

use App\Filament\Resources\FinanceCategoryResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditFinanceCategory extends EditRecord
{
    protected static string $resource = FinanceCategoryResource::class;

    private ?bool $wasActive = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => FinanceCategoryResource::canDelete($this->record)),

            // Gap audit Kategori Keuangan 2026-09-28: tombol hapus yang
            // disembunyikan tanpa penjelasan membingungkan -- tampilkan
            // pengganti nonaktif dengan alasan.
            Actions\Action::make('cannotDelete')
                ->label('Tidak bisa dihapus')
                ->icon('heroicon-o-lock-closed')
                ->color('gray')
                ->disabled()
                ->tooltip('Kategori ini sudah dipakai transaksi keuangan. Nonaktifkan saja kalau tidak mau dipakai lagi.')
                ->visible(fn () => FinanceCategoryResource::canDeleteAny() && $this->record->hasTransactions()),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->wasActive = $this->record->is_active;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (\RuntimeException $e) {
            Notification::make()->title('Tidak bisa menyimpan')->body($e->getMessage())->danger()->send();

            $this->halt();
        }
    }

    /**
     * Menonaktifkan kategori yang sudah punya transaksi: transaksi lama
     * yang diedit harus memilih ulang kategori (dropdown hanya memuat
     * kategori aktif untuk transaksi BARU) -- admin diberi tahu dampaknya.
     */
    protected function afterSave(): void
    {
        if ($this->wasActive && ! $this->record->is_active && $this->record->hasTransactions()) {
            Notification::make()
                ->title('Kategori dinonaktifkan')
                ->body('Kategori ini punya ' . $this->record->transactions()->count() . ' transaksi. Riwayat tetap utuh; kategori tidak muncul lagi sebagai pilihan transaksi BARU.')
                ->warning()
                ->persistent()
                ->send();
        }
    }
}
