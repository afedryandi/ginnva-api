<?php

namespace App\Filament\Resources\CaseStudyResource\Pages;

use App\Filament\Resources\CaseStudyResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCaseStudy extends EditRecord
{
    protected static string $resource = CaseStudyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /** Materi dari booking tidak boleh ditayangkan tanpa izin aktif, plat diburamkan, dan teks alternatif (lihat CaseStudy::activationBlocker()). */
    protected function beforeSave(): void
    {
        $probe = (clone $this->record)->fill($this->form->getState());

        if ($probe->is_active && ($blocker = $probe->activationBlocker())) {
            Notification::make()->title('Belum bisa ditayangkan')->body($blocker)->danger()->send();
            $this->halt();
        }
    }
}
