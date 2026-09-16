<?php

namespace App\Filament\Resources\SpkResource\Pages;

use App\Filament\Resources\SpkResource;
use App\Services\SpkService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateSpk extends CreateRecord
{
    protected static string $resource = SpkResource::class;

    /**
     * Semua tulis-menulis (nomor SPK, checklist item) WAJIB lewat
     * SpkService -- lihat catatan di SpkResource::checklistRepeater()
     * soal 3 Repeater "checklist_*" yang SENGAJA bukan
     * ->relationship() bawaan Filament.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $checklistItems = $this->extractChecklistItems($data);

        $data['created_by'] = auth()->id();

        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $data['store_id'] = auth()->user()?->store_id;
        }

        try {
            return app(SpkService::class)->create($data, $checklistItems, auth()->id());
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
