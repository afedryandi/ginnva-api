<?php

namespace Tests\Feature;

use App\Filament\Resources\BookingResource\Pages\ViewBooking;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Models\Booking;
use App\Models\BookingFilmProduct;
use App\Models\BookingSaleLine;
use App\Models\Customer;
use App\Models\FilmProduct;
use App\Models\Invoice;
use App\Models\Store;
use App\Models\User;
use App\Services\InvoiceBookingPrefill;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Invoice: data ditarik dari Booking (tetap bisa diubah manual) dan tombol "Buat Invoice" di halaman booking. */
class InvoiceFromBookingTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999), 'email' => 'budi@test.local']);
    }

    private function booking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'booking_number' => 'BKG-INV-' . uniqid(),
            'customer_id' => $this->customer->id,
            'customer_name' => 'Budi',
            'phone_number' => $this->customer->phone_number,
            'store_id' => $this->store->id,
            'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true,
            'preferred_date' => now()->toDateString(),
            'status' => 'confirmed',
        ], $overrides));
    }

    private function product(string $type, string $name): FilmProduct
    {
        return FilmProduct::create([
            'sku' => 'SKU-' . uniqid(), 'name' => $name, 'product_type' => $type,
            'position' => 'front', 'base_price' => 100_000, 'is_active' => true,
        ]);
    }

    public function test_prefill_for_ppf_full_body_has_the_bonus_lines_at_zero_and_ppf_price_left_blank(): void
    {
        $ppf = $this->product('ppf', 'Ginnva H10');
        $booking = $this->booking(['film_product_id' => $ppf->id, 'ppf_area' => 'exterior', 'ppf_scope' => Booking::PPF_SCOPE_FULL_BODY]);

        $items = InvoiceBookingPrefill::items($booking);

        $this->assertCount(3, $items);
        $this->assertStringContainsString('Full Body', $items[0]['name']);
        $this->assertNull($items[0]['price'], 'Harga PPF dikosongkan agar diisi manual.');
        $this->assertSame($ppf->id, $items[0]['film_product_id']);
        $this->assertSame(0.0, $items[1]['price']);
        $this->assertSame('Termasuk paket PPF Full Body', $items[1]['note']);
        $this->assertSame(0.0, $items[2]['price']);
    }

    public function test_prefill_for_ppf_part_has_one_line_per_panel(): void
    {
        $ppf = $this->product('ppf', 'Ginnva H10');
        $booking = $this->booking(['film_product_id' => $ppf->id, 'ppf_area' => 'exterior', 'ppf_scope' => Booking::PPF_SCOPE_PARTIAL]);
        BookingFilmProduct::create(['booking_id' => $booking->id, 'film_product_id' => $ppf->id, 'position' => 'Hood']);
        BookingFilmProduct::create(['booking_id' => $booking->id, 'film_product_id' => $ppf->id, 'position' => 'Roof']);

        $names = array_column(InvoiceBookingPrefill::items($booking), 'name');

        $this->assertCount(2, $names);
        $this->assertStringContainsString('Hood', $names[0]);
        $this->assertStringContainsString('Roof', $names[1]);
    }

    public function test_prefill_for_several_variants_of_ppf_full_body_has_one_line_per_variant(): void
    {
        $a = $this->product('ppf', 'Ginnva H10');
        $b = $this->product('ppf', 'Ginnva M10');
        $booking = $this->booking(['film_product_id' => $a->id, 'ppf_area' => 'exterior', 'ppf_scope' => Booking::PPF_SCOPE_FULL_BODY]);
        BookingFilmProduct::create(['booking_id' => $booking->id, 'film_product_id' => $a->id, 'position' => Booking::PPF_ALL]);
        BookingFilmProduct::create(['booking_id' => $booking->id, 'film_product_id' => $b->id, 'position' => Booking::PPF_ALL]);

        $ppfLines = array_values(array_filter(InvoiceBookingPrefill::items($booking->fresh()), fn ($i) => str_contains($i['name'], 'Seluruh Bagian PPF')));

        $this->assertCount(2, $ppfLines);
        $this->assertSame([$a->id, $b->id], array_column($ppfLines, 'film_product_id'));
    }

    public function test_the_main_product_column_follows_the_first_product_per_part(): void
    {
        $a = $this->product('ppf', 'Ginnva H10');
        $b = $this->product('ppf', 'Ginnva M10');
        $booking = $this->booking();
        BookingFilmProduct::create(['booking_id' => $booking->id, 'film_product_id' => $a->id, 'position' => Booking::PPF_ALL]);
        BookingFilmProduct::create(['booking_id' => $booking->id, 'film_product_id' => $b->id, 'position' => 'Hood']);

        \App\Filament\Resources\BookingResource::syncMainProduct($booking);

        $this->assertSame($a->id, $booking->fresh()->film_product_id);
    }

    public function test_without_any_product_rows_the_old_main_product_is_left_alone(): void
    {
        $legacy = $this->product('ppf', 'Ginnva Lama');
        $booking = $this->booking(['film_product_id' => $legacy->id]);

        \App\Filament\Resources\BookingResource::syncMainProduct($booking);

        $this->assertSame($legacy->id, $booking->fresh()->film_product_id);
    }

    public function test_the_edit_form_shows_a_legacy_single_product_as_one_row(): void
    {
        $legacy = $this->product('ppf', 'Ginnva Lama');
        $booking = $this->booking(['film_product_id' => $legacy->id, 'status' => 'pending']);

        $component = Livewire::test(\App\Filament\Resources\BookingResource\Pages\EditBooking::class, ['record' => $booking->getKey()]);
        $rows = array_values($component->get('data.filmProducts') ?? []);

        $this->assertCount(1, $rows);
        $this->assertSame(Booking::PPF_ALL, $rows[0]['position']);
        $this->assertSame($legacy->id, $rows[0]['film_product_id']);
    }

    public function test_the_booking_form_no_longer_has_a_separate_main_product_field(): void
    {
        $booking = $this->booking(['status' => 'pending']);

        Livewire::test(\App\Filament\Resources\BookingResource\Pages\EditBooking::class, ['record' => $booking->getKey()])
            ->assertDontSee('Model Produk Utama')
            ->assertFormFieldExists('filmProducts');
    }

    public function test_prefill_uses_the_sale_lines_with_their_price_and_discount_when_present(): void
    {
        $ppf = $this->product('ppf', 'Ginnva H10');
        $booking = $this->booking(['film_product_id' => $ppf->id]);
        BookingSaleLine::create([
            'booking_id' => $booking->id, 'service_type' => 'ppf', 'film_product_id' => $ppf->id,
            'quantity' => 1, 'unit_price' => 10_000_000, 'discount_amount' => 1_000_000,
        ]);

        $items = InvoiceBookingPrefill::items($booking->fresh());

        $this->assertCount(1, $items);
        $this->assertSame(10_000_000.0, $items[0]['price']);
        $this->assertSame(10.0, $items[0]['discount_percent']);
    }

    public function test_the_create_page_opens_prefilled_from_the_booking_query(): void
    {
        $booking = $this->booking();

        Livewire::withQueryParams(['booking' => $booking->id])->test(CreateInvoice::class)
            ->assertFormSet([
                'booking_id' => $booking->id,
                'customer_name' => 'Budi',
                'customer_email' => 'budi@test.local',
            ]);
    }

    public function test_the_prefill_can_be_edited_manually_before_saving(): void
    {
        $booking = $this->booking();

        Livewire::withQueryParams(['booking' => $booking->id])->test(CreateInvoice::class)
            ->fillForm([
                'customer_name' => 'Budi Santoso (diubah)',
                'items' => [[
                    'film_product_id' => null, 'name' => 'Pasang PPF custom', 'quantity' => 1, 'unit' => 'Unit',
                    'price' => 7_500_000, 'discount_percent' => 0, 'note' => 'harga disepakati',
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $invoice = Invoice::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('Budi Santoso (diubah)', $invoice->customer_name);
        $this->assertSame('Pasang PPF custom', $invoice->items()->first()->name);
        $this->assertEquals(7_500_000, $invoice->total);
    }

    public function test_a_booking_outside_the_users_store_is_ignored(): void
    {
        $other = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko Lain', 'is_active' => true]);
        $booking = $this->booking(['store_id' => $other->id]);

        \Spatie\Permission\Models\Role::findOrCreate('store_manager', 'web');
        $staff = User::create(['name' => 'Staf', 'email' => 'staf@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
        $staff->assignRole('store_manager');
        $this->actingAs($staff, 'web');

        $this->assertNull(\App\Filament\Resources\BookingResource::getEloquentQuery()->find($booking->id));
    }

    public function test_the_buat_invoice_action_shows_only_for_confirmed_or_completed_bookings(): void
    {
        $confirmed = $this->booking();
        $pending = $this->booking(['status' => 'pending']);

        Livewire::test(ViewBooking::class, ['record' => $confirmed->getKey()])
            ->assertActionVisible('buatInvoice')
            ->assertActionHasUrl('buatInvoice', InvoiceResource::getUrl('create', ['booking' => $confirmed->getKey()]));

        Livewire::test(ViewBooking::class, ['record' => $pending->getKey()])
            ->assertActionHidden('buatInvoice');
    }
}
