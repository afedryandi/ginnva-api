<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    // Spatie\Permission\Models\Role bukan model kita sendiri, tidak bisa
    // dikasih trait LogsActivity — dicatat manual lewat activity() helper.
    protected function afterCreate(): void
    {
        activity('role')
            ->causedBy(auth()->user())
            ->performedOn($this->record)
            ->log("Role \"{$this->record->name}\" dibuat");
    }
}
