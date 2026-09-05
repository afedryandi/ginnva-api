<?php

namespace App\Filament\Resources\FinanceTransactionResource\Pages;

use App\Filament\Resources\FinanceTransactionResource;
use App\Models\FinanceCategory;
use App\Services\FinanceTransactionPostingService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EditFinanceTransaction extends EditRecord
{
    protected static string $resource = FinanceTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                ->action(function () {
                    try {
                        DB::transaction(function () {
                            app(FinanceTransactionPostingService::class)->reverseExisting($this->record);
                            $this->record->delete();
                        });

                        Notification::make()->title('Transaksi dihapus')->success()->send();
                        $this->redirect($this->getResource()::getUrl('index'));
                    } catch (RuntimeException $e) {
                        Notification::make()
                            ->title('Gagal menghapus transaksi')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    /**
     * Sama jaring pengaman dengan CreateFinanceTransaction — 'type'
     * disalin ulang dari kategori yang (mungkin baru) dipilih saat edit.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $category = FinanceCategory::find($data['finance_category_id']);
        if ($category) {
            $data['type'] = $category->type;
        }

        return $data;
    }

    /**
     * Fase 3 — jurnal lama (kalau ada & masih posted) dibalik dulu, lalu
     * jurnal baru dibuat dari data transaksi yang sudah diperbarui.
     * SELALU resync penuh, bukan cuma kalau field finansial berubah —
     * lihat komentar FinanceTransactionPostingService::resync().
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data) {
            $record->update($data);

            try {
                $entry = app(FinanceTransactionPostingService::class)->resync($record->refresh());
                $record->update(['journal_entry_id' => $entry->id]);
            } catch (RuntimeException $e) {
                Notification::make()
                    ->title('Perubahan tidak bisa disimpan')
                    ->body($e->getMessage())
                    ->danger()
                    ->send();

                $this->halt();
            }

            return $record->refresh();
        });
    }
}
