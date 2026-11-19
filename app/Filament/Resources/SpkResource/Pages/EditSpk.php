<?php

namespace App\Filament\Resources\SpkResource\Pages;

use App\Filament\Resources\SpkResource;
use App\Services\SpkService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EditSpk extends EditRecord
{
    protected static string $resource = SpkResource::class;

    // Tombol Simpan dan Batal menempel di bawah layar saat form digulir.
    public static bool $formActionsAreSticky = true;

    /** Konteks di bawah judul: siapa, kendaraan apa, dan di toko mana. */
    public function getSubheading(): ?string
    {
        return collect([$this->record->customer_name, $this->record->vehicle_plate, $this->record->store?->name])
            ->filter()
            ->implode(' · ') ?: null;
    }

    protected function getHeaderActions(): array
    {
        // Cetak sejajar dengan Hapus di kepala halaman, jadi tidak perlu kembali ke daftar untuk mencetak.
        return [
            Actions\Action::make('print')
                ->label(fn () => $this->record->checked_out_at ? 'Cetak Ulang (Arsip)' : 'Cetak PDF')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->action(function () {
                    $record = $this->record->loadMissing(['checklistItems', 'damageMarks', 'store', 'booking']);
                    $reprint = $record->checked_out_at !== null;
                    $pdf = Pdf::loadView('pdf.spk', ['spk' => $record, 'isReprint' => $reprint])->setPaper('a4', 'portrait');
                    $filename = str_replace('/', '-', $record->spk_number) . ($reprint ? '-arsip' : '') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),

            Actions\DeleteAction::make(),
        ];
    }

    /**
     * 3 Repeater "checklist_*" SENGAJA bukan ->relationship() bawaan
     * Filament (lihat SpkResource::checklistRepeater()) -- jadi WAJIB
     * diisi manual di sini dari relasi asli record, dikelompokkan
     * ulang per kategori, atau form muncul kosong saat edit.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $items = $this->record->checklistItems()->get();

        foreach (['pekerjaan', 'extra_service', 'perlengkapan'] as $category) {
            $data["checklist_{$category}"] = $items->where('category', $category)
                ->map(fn ($item) => ['label' => $item->label, 'is_checked' => $item->is_checked])
                ->values()
                ->toArray();
        }

        return $data;
    }

    protected function handleRecordUpdate($record, array $data): Model
    {
        $checklistItems = $this->extractChecklistItems($data);

        try {
            return app(SpkService::class)->update($record, $data, $checklistItems);
        } catch (RuntimeException $e) {
            Notification::make()
                ->title('SPK tidak bisa disimpan')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }

    /**
     * @return array<int, array{category:string, label:string, is_checked:bool}>
     */
    private function extractChecklistItems(array &$data): array
    {
        $items = [];

        foreach (['pekerjaan', 'extra_service', 'perlengkapan'] as $category) {
            $key = "checklist_{$category}";

            foreach ($data[$key] ?? [] as $row) {
                $items[] = [
                    'category' => $category,
                    'label' => $row['label'],
                    'is_checked' => (bool) ($row['is_checked'] ?? false),
                ];
            }

            unset($data[$key]);
        }

        return $items;
    }
}
