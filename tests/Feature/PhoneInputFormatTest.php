<?php

namespace Tests\Feature;

use App\Filament\Resources\PartnerResource\Pages\CreatePartner;
use App\Models\Partner;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Kolom telepon (->tel()) di form Filament memakai pola global dari AppServiceProvider: angka, spasi,
 * + ( ) - dan titik, 6-30 karakter. Pola bawaan Filament menolak nomor sah 13 digit / berawalan +62.
 * Diuji lewat form Partner (salah satu dari belasan form dengan ->tel()).
 */
class PhoneInputFormatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('partner', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');
    }

    private function create(string $phone, string $email)
    {
        return Livewire::test(CreatePartner::class)->fillForm([
            'email' => $email, 'password' => 'rahasia123', 'passwordConfirmation' => 'rahasia123',
            'business_name' => 'Mitra ' . $email, 'phone' => $phone, 'status' => 'active', 'type' => 'partner',
        ])->call('create');
    }

    public function test_common_indonesian_phone_formats_are_accepted(): void
    {
        foreach (['+6281234567890', '081234567890123', '+62 21 555 1234', '(021) 555-1234', '0812.3456.7890'] as $i => $phone) {
            $this->create($phone, "ok{$i}@mitra.test")->assertHasNoFormErrors();
        }

        $this->assertSame(5, Partner::count());
    }

    public function test_letters_and_too_short_numbers_are_rejected(): void
    {
        $this->create('telepon-rumah', 'a@mitra.test')->assertHasFormErrors(['phone']);
        $this->create('12345', 'b@mitra.test')->assertHasFormErrors(['phone']);

        $this->assertSame(0, Partner::count());
    }
}
