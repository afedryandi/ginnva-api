<?php

namespace App\Filament\Resources\SpkResource\Pages;

use App\Filament\Resources\SpkResource;
use App\Services\SpkService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EditSpk extends EditRecord
{
    protected static string $resource = SpkResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
