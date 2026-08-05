<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Password sudah otomatis di-hash lewat cast 'password' => 'hashed'
        // di model User, jadi tidak perlu Hash::make() manual di sini.
        return UserResource::mergeMenuAccessFields($data);
    }
}