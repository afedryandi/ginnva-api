<?php

namespace Tests\Feature;

use App\Filament\Resources\BookingResource;
use App\Filament\Resources\CustomerGroupResource;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Store;
use App\Models\User;
use App\Support\RecordPdf;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Cetak PDF satu data dari menu mana pun: tombol di halaman, rute, hak akses, dan isi umum. */
class RecordPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');
    }

    private function booking(): Booking
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return Booking::create([
            'booking_number' => 'BKG-PDF-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true,
            'preferred_date' => now()->toDateString(),
            'status' => 'pending',
        ]);
    }

    public function test_booking_detail_downloads_as_pdf(): void
    {
        $booking = $this->booking();

        $this->get(route('record-pdf', ['slug' => BookingResource::getSlug(), 'record' => $booking->getKey()]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_another_menu_also_prints(): void
    {
        $group = CustomerGroup::create(['name' => 'Grup Uji', 'discount_percent' => 5]);

        $this->get(route('record-pdf', ['slug' => CustomerGroupResource::getSlug(), 'record' => $group->getKey()]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_unknown_menu_is_404_and_guests_are_redirected(): void
    {
        $this->get(route('record-pdf', ['slug' => 'tidak-ada', 'record' => 1]))->assertNotFound();

        auth()->logout();
        $this->get(route('record-pdf', ['slug' => 'bookings', 'record' => 1]))->assertRedirect();
    }

    public function test_attribute_fallback_never_prints_secrets(): void
    {
        $user = User::create(['name' => 'Rahasia', 'email' => 'rahasia@test.local', 'password' => 'x']);

        $method = new \ReflectionMethod(RecordPdf::class, 'sectionsFromAttributes');
        $method->setAccessible(true);
        $text = json_encode($method->invoke(null, $user));

        $this->assertStringContainsString('Rahasia', $text);
        $this->assertStringNotContainsString('"Password"', $text);
    }
}
