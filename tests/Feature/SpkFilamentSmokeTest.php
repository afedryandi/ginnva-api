<?php

namespace Tests\Feature;

use App\Filament\Resources\SpkResource\Pages\CreateSpk;
use App\Filament\Resources\SpkResource\Pages\EditSpk;
use App\Filament\Resources\SpkResource\Pages\ListSpks;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Spk;
use App\Models\Store;
use App\Models\User;
use App\Services\SpkService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Smoke test SPK di Filament: daftar, buat, edit, dan template PDF cetak.
 */
class SpkFilamentSmokeTest extends TestCase
{
    use RefreshDatabase;

    private Spk $spk;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id'    => $customer->id,
            'store_id'       => $store->id,
            'service_type'   => 'Pelindung Cat (PPF)',
            'product_ppf'    => true,
            'preferred_date' => now()->addDays(3)->toDateString(),
            'status'         => 'confirmed',
        ]);

        $this->spk = app(SpkService::class)->createFromBooking($booking, $admin->id);
    }

    public function test_spk_list_renders_with_record(): void
    {
        Livewire::test(ListSpks::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->spk]);
    }

    public function test_spk_create_and_edit_pages_render(): void
    {
        Livewire::test(CreateSpk::class)->assertSuccessful();
        Livewire::test(EditSpk::class, ['record' => $this->spk->getKey()])->assertSuccessful();
    }

    public function test_spk_created_from_booking_prechecks_work_items(): void
    {
        $labels = $this->spk->checklistItems->where('is_checked', true)->pluck('label')->all();

        $this->assertContains('PPF', $labels);
        $this->assertNotContains('Kaca Film', $labels);
    }

    public function test_spk_pdf_template_renders(): void
    {
        $output = Pdf::loadView('pdf.spk', ['spk' => $this->spk->fresh(['checklistItems', 'damageMarks', 'booking', 'store']), 'isReprint' => false])
            ->setPaper('a4', 'portrait')
            ->output();

        $this->assertStringStartsWith('%PDF', $output);
    }
}
