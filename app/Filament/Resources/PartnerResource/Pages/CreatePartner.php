<?php

namespace App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Resources\PartnerResource;
use App\Models\Partner;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePartner extends CreateRecord
{
    protected static string $resource = PartnerResource::class;

    /**
     * Form ini menghasilkan 2 record: User (akun login) + Partner (profil
     * poin & kode referral). Field `email`/`password` bukan kolom di
     * tabel partners, jadi harus ditangani manual di sini, bukan lewat
     * $model::create($data) bawaan Filament — logikanya di Partner::createAccount().
     */
    protected function handleRecordCreation(array $data): Model
    {
        return Partner::createAccount($data);
    }
}
