<?php

namespace App\Filament\Auth;

use App\Support\PhoneNumber;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Auth\Login as BaseLogin;

/**
 * Login panel admin dengan email ATAU No. HP. Isian tetap berkunci 'email' di form supaya pesan galat bawaan Filament ('data.email')
 * tetap menempel pada kolom ini; yang berubah hanya label, validasi (tidak lagi harus berbentuk email), dan kredensial yang dicoba:
 * mengandung "@" = email, selain itu dicocokkan ke users.phone_digits (bentuk baku 628…).
 */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Email atau No. HP')
            ->required()
            ->maxLength(255)
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    protected function getCredentialsFromFormData(array $data): array
    {
        $identifier = trim((string) ($data['email'] ?? ''));

        if (PhoneNumber::looksLikeEmail($identifier)) {
            return ['email' => $identifier, 'password' => $data['password']];
        }

        // '-' tidak mungkin cocok dengan nomor mana pun: nomor yang tidak masuk akal diperlakukan sebagai akun tidak ditemukan.
        return ['phone_digits' => PhoneNumber::digits($identifier) ?? '-', 'password' => $data['password']];
    }
}
