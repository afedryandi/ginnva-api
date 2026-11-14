<?php

namespace App\Filament\Resources\NotificationTemplateResource\Pages;

use App\Filament\Resources\NotificationTemplateResource;
use Filament\Resources\Pages\ListRecords;

class ListNotificationTemplates extends ListRecords
{
    protected static string $resource = NotificationTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        return 'Template dibuat dari menu Kirim Notifikasi lewat "Simpan sebagai template". Di sini bisa diubah, dihapus, atau dipakai lagi.';
    }
}
