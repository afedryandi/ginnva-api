<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Pesan validasi Laravel berbahasa Indonesia (lang/id/validation.php), dengan nama field yang dikenal sistem diterjemahkan.
 * Aplikasi mobile menampilkan pesan field pertama apa adanya, jadi pesan ini yang dibaca staf dan pelanggan.
 */
class IndonesianValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('id');
    }

    private function firstError(array $data, array $rules): string
    {
        return Validator::make($data, $rules)->errors()->first();
    }

    public function test_required_message_is_indonesian_and_uses_the_translated_field_name(): void
    {
        $this->assertSame('Alasan wajib diisi.', $this->firstError([], ['reason' => 'required']));
        $this->assertSame('Nomor telepon wajib diisi.', $this->firstError([], ['phone_number' => 'required']));
    }

    public function test_unknown_fields_still_read_naturally(): void
    {
        $this->assertSame('Warna kustom wajib diisi.', $this->firstError([], ['warna_kustom' => 'required']));
    }

    public function test_common_rules_have_indonesian_messages(): void
    {
        $this->assertSame('Email harus berupa alamat email yang valid.', $this->firstError(['email' => 'x'], ['email' => 'email']));
        $this->assertSame('Jumlah minimal 5.', $this->firstError(['quantity' => 2], ['quantity' => 'numeric|min:5']));
        $this->assertSame('Catatan tidak boleh lebih dari 3 karakter.', $this->firstError(['notes' => 'panjang'], ['notes' => 'string|max:3']));
        $this->assertSame('Konfirmasi password tidak cocok.', $this->firstError(['password' => 'a', 'password_confirmation' => 'b'], ['password' => 'confirmed']));
        $this->assertSame('Jumlah harus berupa bilangan bulat.', $this->firstError(['quantity' => 'abc'], ['quantity' => 'integer']));
    }
}
