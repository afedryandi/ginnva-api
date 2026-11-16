<?php

namespace Tests\Feature;

use App\Models\BookingServiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pilihan Jenis Layanan di form Booking panel mengikuti menu Layanan Booking (Tampil dan Urutan). */
class BookingServiceTypeFormOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_four_services_show_by_default_in_catalog_order(): void
    {
        $this->assertSame(['kaca_film', 'ppf', 'detailing', 'premium_wash'], array_keys(BookingServiceType::formOptions()));
    }

    public function test_a_hidden_service_disappears_from_the_form(): void
    {
        BookingServiceType::where('key', 'detailing')->update(['is_active' => false]);
        \Illuminate\Support\Facades\Cache::forget('booking_service_types.v1');

        $this->assertArrayNotHasKey('detailing', BookingServiceType::formOptions());
        $this->assertCount(3, BookingServiceType::formOptions());
    }

    public function test_a_hidden_service_stays_visible_when_the_booking_already_has_it(): void
    {
        BookingServiceType::where('key', 'detailing')->update(['is_active' => false]);
        \Illuminate\Support\Facades\Cache::forget('booking_service_types.v1');

        $this->assertArrayHasKey('detailing', BookingServiceType::formOptions(['detailing']));
    }

    public function test_the_order_follows_the_sort_order_column(): void
    {
        BookingServiceType::where('key', 'premium_wash')->update(['sort_order' => 0]);
        BookingServiceType::where('key', 'kaca_film')->update(['sort_order' => 9]);
        \Illuminate\Support\Facades\Cache::forget('booking_service_types.v1');

        $keys = array_keys(BookingServiceType::formOptions());

        $this->assertSame('premium_wash', $keys[0]);
        $this->assertSame('kaca_film', end($keys));
    }

    public function test_a_renamed_service_shows_its_new_name(): void
    {
        BookingServiceType::where('key', 'ppf')->update(['label' => 'PPF Premium']);
        \Illuminate\Support\Facades\Cache::forget('booking_service_types.v1');

        $this->assertSame('PPF Premium', BookingServiceType::formOptions()['ppf']);
    }
}
