<?php

namespace App\Filament\Resources\FinanceTransactionResource\Pages;

use App\Filament\Resources\FinanceTransactionResource;
use App\Models\FinanceCategory;
use App\Services\FinanceTransactionPostingService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EditFinanceTransaction extends EditRecord
{
    protected static string $resource = FinanceTransactionResource::class;

    private ?string $changeReason = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                ->form([
                    \Filament\Forms\Components\Textarea::make('reason')
                        ->label('Alasan Penghapusan')
                        ->required()
                        ->rows(2)
                        ->maxLength(500),
                ])
                ->action(function (array $data) {
                    try {
                        $posting = app(FinanceTransactionPostingService::class);

                        DB::transaction(function () use ($posting, $data) {
                            // Tutup Periode + pelaku = user yang menghapus
                            // (audit Transaksi Keuangan 2026-09-28).
                            $posting->assertPeriodsOpenForChange($this->record);
                            $posting->reverseExisting($this->record, auth()->id(), $data['reason']);
                            $posting->logChangeReason($this->record, 'dihapus', $data['reason']);
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
     * Kontrol edit setelah approval (audit Transaksi Keuangan 2026-09-28):
     * SEBELUMNYA siapa pun yang boleh 'update' bisa mengubah nominal/
     * tanggal/kategori/tipe/toko transaksi yang sudah disetujui direksi
     * (mis. Rp100rb -> Rp10jt) lalu jurnal diposting ulang diam-diam.
     * Sekarang non-full-access HANYA boleh mengoreksi keterangan & nota;
     * field finansial dan toko dikembalikan ke nilai tersimpan DI SERVER
     * (bukan cuma dikunci di form, karena state Livewire bisa dimanipulasi).
     * Perubahan finansial = wewenang full-access.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // change_reason bukan kolom -- diambil untuk catatan jurnal & log.
        $this->changeReason = $data['change_reason'] ?? null;
        unset($data['change_reason']);

        if (! (auth()->user()?->isFullAccess() ?? false)) {
            foreach (FinanceTransactionResource::FINANCIAL_FIELDS as $field) {
                $data[$field] = $this->record->getRawOriginal($field);
            }

            return $data;
        }

        // Kategori harus ada; harus AKTIF kecuali tidak diganti dari yang
        // sudah melekat di transaksi ini (kategori yang belakangan
        // dinonaktifkan tidak boleh mengunci edit transaksi lama).
        $category = FinanceCategory::find($data['finance_category_id'] ?? null);
        $unchanged = $category && (int) $category->id === (int) $this->record->finance_category_id;

        if (! $category || (! $category->is_active && ! $unchanged)) {
            Notification::make()
                ->title('Kategori tidak valid')
                ->body('Pilih kategori yang aktif.')
                ->danger()
                ->send();

            $this->halt();
        }

        if ($category->is_group) {
            Notification::make()
                ->title('Kategori tidak valid')
                ->body('Kategori grup tidak bisa dipakai untuk transaksi; pilih kategori di bawahnya.')
                ->danger()
                ->send();

            $this->halt();
        }

        $data['type'] = $category->type;

        return $data;
    }

    /**
     * Fase 3 — jurnal lama (kalau ada & masih posted) dibalik dulu, lalu
     * jurnal baru dibuat dari data transaksi yang sudah diperbarui.
     * SELALU resync penuh, bukan cuma kalau field finansial berubah —
     * lihat komentar FinanceTransactionPostingService::resync().
     *
     * Tutup Periode dicek terhadap tanggal LAMA dan BARU sebelum apa pun
     * diubah; pelaku jurnal = user yang mengedit.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $posting = app(FinanceTransactionPostingService::class);

        try {
            $posting->assertPeriodsOpenForChange($record, isset($data['transaction_date']) ? Carbon::parse($data['transaction_date']) : null);
        } catch (RuntimeException $e) {
            Notification::make()
                ->title('Perubahan tidak bisa disimpan')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }

        return DB::transaction(function () use ($record, $data, $posting) {
            $record->update($data);

            try {
                $entry = $posting->resync($record->refresh(), auth()->id(), $this->changeReason);
                $record->update(['journal_entry_id' => $entry->id]);

                if ($this->changeReason) {
                    $posting->logChangeReason($record, 'diubah', $this->changeReason);
                }
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
