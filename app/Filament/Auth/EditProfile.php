<?php

namespace App\Filament\Auth;

use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;

/**
 * Profil sendiri: email boleh dikosongkan oleh karyawan yang masuk dengan No. HP. Email tetap wajib bila akun belum punya No. HP,
 * supaya akun selalu punya satu cara masuk.
 */
class EditProfile extends BaseEditProfile
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Email')
            ->email()
            ->required(fn () => blank(Filament::auth()->user()?->phone))
            ->maxLength(255)
            ->unique(ignoreRecord: true);
    }
}
