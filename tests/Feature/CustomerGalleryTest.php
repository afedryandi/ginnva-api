<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerGalleryPhotoResource;
use App\Filament\Resources\CustomerGalleryPhotoResource\Pages\CreateCustomerGalleryPhoto;
use App\Filament\Resources\CustomerGalleryPhotoResource\Pages\EditCustomerGalleryPhoto;
use App\Filament\Resources\CustomerGalleryPhotoResource\Pages\ListCustomerGalleryPhotos;
use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\BookingMessagePhoto;
use App\Models\Customer;
use App\Models\CustomerGalleryPhoto;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Galeri Pemasangan: API galeri customer (foto dari chat booking miliknya
 * sendiri + "Galeri Pilihan" showcase yang is_featured), isolasi antar
 * customer, dan pengelolaan galeri showcase di Filament (unggah, validasi,
 * tampil/sembunyi, urutan, hapus, izin per aksi, audit).
 */
class CustomerGalleryTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function customer(): Customer
    {
        return Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
    }

    private function booking(Customer $customer, array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id'    => $customer->id,
            'store_id'       => $this->store->id,
            'service_type'   => 'Pelindung Cat (PPF)',
            'product_ppf'    => true,
            'preferred_date' => now()->toDateString(),
            'status'         => 'confirmed',
            'current_stage'  => 'qc',
        ], $overrides));
    }

    /** Pesan staf di chat booking dengan N foto (tabel booking_message_photos). */
    private function photoMessage(Booking $booking, array $paths, ?string $stage = 'qc'): BookingMessage
    {
        $message = BookingMessage::create([
            'booking_id' => $booking->id, 'sender_type' => 'staff', 'type' => 'photo', 'stage' => $stage,
        ]);
        foreach ($paths as $path) {
            BookingMessagePhoto::create(['booking_message_id' => $message->id, 'path' => $path]);
        }

        return $message;
    }

    private function showcase(Customer $customer, array $overrides = []): CustomerGalleryPhoto
    {
        return CustomerGalleryPhoto::create(array_merge([
            'customer_id' => $customer->id, 'image' => 'customer-gallery/' . uniqid() . '.jpg',
            'is_featured' => true, 'sort_order' => 0,
        ], $overrides));
    }

    private function asAdmin(): User
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        return $admin;
    }

    private function gallery(Customer $customer): array
    {
        return $this->actingAs($customer, 'customer')->getJson('/api/customer/my-gallery')
            ->assertSuccessful()->json('data');
    }

    // ------------------------------------------------------------- API customer

    public function test_gallery_requires_customer_login(): void
    {
        $this->getJson('/api/customer/my-gallery')->assertStatus(401);
    }

    public function test_gallery_is_empty_for_a_customer_without_photos(): void
    {
        $customer = $this->customer();
        $this->booking($customer); // booking tanpa pesan foto tidak muncul

        $this->actingAs($customer, 'customer')->getJson('/api/customer/my-gallery')
            ->assertSuccessful()->assertExactJson(['success' => true, 'data' => []]);
    }

    public function test_booking_photos_are_flattened_one_entry_per_photo_with_stage_label(): void
    {
        $customer = $this->customer();
        $booking = $this->booking($customer);
        $message = $this->photoMessage($booking, ['booking-photos/a.jpg', 'booking-photos/b.jpg'], 'qc');

        $data = $this->gallery($customer);

        $this->assertCount(1, $data);
        $this->assertSame($booking->id, $data[0]['booking_id']);
        $this->assertSame($booking->booking_number, $data[0]['booking_number']);
        $this->assertSame('Toko A', $data[0]['store_name']);

        $photos = $data[0]['photos'];
        $this->assertCount(2, $photos, 'Satu pesan dengan 2 foto menjadi 2 entri galeri.');
        $this->assertSame([$message->id * 1000, $message->id * 1000 + 1], collect($photos)->pluck('id')->all());
        $this->assertStringEndsWith('/storage/booking-photos/a.jpg', $photos[0]['url']);
        $this->assertSame(BookingMessage::allStages()['qc'], $photos[0]['stage_label']);
    }

    public function test_legacy_single_photo_path_still_appears(): void
    {
        $customer = $this->customer();
        $booking = $this->booking($customer);
        BookingMessage::create([
            'booking_id' => $booking->id, 'sender_type' => 'staff', 'type' => 'photo',
            'stage' => 'qc', 'photo_path' => 'booking-photos/lama.jpg',
        ]);

        $photos = $this->gallery($customer)[0]['photos'];

        $this->assertCount(1, $photos);
        $this->assertStringEndsWith('/storage/booking-photos/lama.jpg', $photos[0]['url']);
    }

    public function test_text_only_messages_do_not_create_gallery_entries(): void
    {
        $customer = $this->customer();
        $booking = $this->booking($customer);
        BookingMessage::create(['booking_id' => $booking->id, 'sender_type' => 'staff', 'type' => 'text', 'body' => 'Halo']);

        $this->assertSame([], $this->gallery($customer));
    }

    public function test_customer_never_sees_another_customers_photos(): void
    {
        $mine = $this->customer();
        $other = $this->customer();
        $this->photoMessage($this->booking($mine), ['booking-photos/milik-saya.jpg']);
        $this->photoMessage($this->booking($other), ['booking-photos/milik-orang.jpg']);
        $this->showcase($other, ['image' => 'customer-gallery/orang.jpg']);

        $json = json_encode($this->gallery($mine));

        $this->assertStringContainsString('milik-saya.jpg', $json);
        $this->assertStringNotContainsString('milik-orang.jpg', $json);
        $this->assertStringNotContainsString('customer-gallery/', $json, 'Foto showcase customer lain tidak bocor.');
    }

    public function test_bookings_are_listed_newest_date_first(): void
    {
        $customer = $this->customer();
        $older = $this->booking($customer, ['preferred_date' => now()->subDays(10)->toDateString()]);
        $newer = $this->booking($customer, ['preferred_date' => now()->toDateString()]);
        $this->photoMessage($older, ['booking-photos/1.jpg']);
        $this->photoMessage($newer, ['booking-photos/2.jpg']);

        $this->assertSame([$newer->id, $older->id], collect($this->gallery($customer))->pluck('booking_id')->all());
    }

    public function test_featured_showcase_photos_appear_as_a_curated_group_at_the_end(): void
    {
        $customer = $this->customer();
        $booking = $this->booking($customer);
        $this->photoMessage($booking, ['booking-photos/a.jpg']);
        $second = $this->showcase($customer, ['caption' => 'Kedua', 'sort_order' => 2]);
        $first = $this->showcase($customer, ['caption' => 'Pertama', 'sort_order' => 1]);
        $hidden = $this->showcase($customer, ['caption' => 'Disembunyikan', 'is_featured' => false]);

        $data = $this->gallery($customer);

        $this->assertCount(2, $data);
        $this->assertSame($booking->id, $data[0]['booking_id']);

        $curated = $data[1];
        $this->assertNull($curated['booking_id']);
        $this->assertSame('Galeri Pilihan', $curated['service_type']);
        $this->assertSame(['Pertama', 'Kedua'], collect($curated['photos'])->pluck('stage_label')->all(), 'Caption jadi label, urut sort_order.');
        $this->assertSame([1000000000 + $first->id, 1000000000 + $second->id], collect($curated['photos'])->pluck('id')->all());
        $this->assertNotContains(1000000000 + $hidden->id, collect($curated['photos'])->pluck('id')->all());
    }

    public function test_only_showcase_photos_still_produce_a_gallery(): void
    {
        $customer = $this->customer();
        $this->showcase($customer);

        $data = $this->gallery($customer);

        $this->assertCount(1, $data);
        $this->assertSame('Galeri Pilihan', $data[0]['service_type']);
    }

    public function test_hiding_a_showcase_photo_removes_it_from_the_api(): void
    {
        $customer = $this->customer();
        $photo = $this->showcase($customer);
        $this->assertCount(1, $this->gallery($customer));

        $photo->update(['is_featured' => false]);

        $this->assertSame([], $this->gallery($customer));
    }

    // ------------------------------------------------------------- Filament

    public function test_filament_list_renders_and_filters_by_visibility(): void
    {
        $this->asAdmin();
        $customer = $this->customer();
        $shown = $this->showcase($customer);
        $hidden = $this->showcase($customer, ['is_featured' => false]);

        Livewire::test(ListCustomerGalleryPhotos::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$shown, $hidden])
            ->filterTable('is_featured', true)
            ->assertCanSeeTableRecords([$shown])->assertCanNotSeeTableRecords([$hidden]);
    }

    public function test_admin_uploads_a_showcase_photo_for_a_customer(): void
    {
        Storage::fake('public');
        $admin = $this->asAdmin();
        $customer = $this->customer();

        Livewire::test(CreateCustomerGalleryPhoto::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'image' => UploadedFile::fake()->image('hasil.jpg', 1200, 800),
                'caption' => 'Kaca Film A70 — Alphard', 'is_featured' => true, 'sort_order' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $photo = CustomerGalleryPhoto::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame($admin->id, $photo->uploaded_by);
        $this->assertStringStartsWith('customer-gallery/', $photo->image);
        Storage::disk('public')->assertExists($photo->image);

        $this->assertSame('Kaca Film A70 — Alphard', $this->gallery($customer)[0]['photos'][0]['stage_label']);
    }

    public function test_customer_and_photo_are_required_and_caption_is_limited(): void
    {
        Storage::fake('public');
        $this->asAdmin();
        $customer = $this->customer();

        Livewire::test(CreateCustomerGalleryPhoto::class)
            ->fillForm(['caption' => 'Tanpa customer & foto'])
            ->call('create')
            ->assertHasFormErrors(['customer_id' => 'required', 'image' => 'required']);

        Livewire::test(CreateCustomerGalleryPhoto::class)
            ->fillForm([
                'customer_id' => $customer->id, 'image' => UploadedFile::fake()->image('a.jpg', 800, 600),
                'caption' => str_repeat('a', 256),
            ])
            ->call('create')
            ->assertHasFormErrors(['caption' => 'max']);

        $this->assertSame(0, CustomerGalleryPhoto::count());
    }

    public function test_admin_hides_a_photo_from_the_edit_form_and_it_is_audited(): void
    {
        Storage::fake('public');
        $realPath = UploadedFile::fake()->image('asli.jpg', 1200, 800)->store('customer-gallery', 'public');
        $admin = $this->asAdmin();
        $customer = $this->customer();
        $photo = $this->showcase($customer, ['image' => $realPath]);
        $this->assertCount(1, $this->gallery($customer));
        $this->actingAs($admin, 'web'); // gallery() mengganti guard default ke customer

        Livewire::test(EditCustomerGalleryPhoto::class, ['record' => $photo->getKey()])
            ->fillForm(['is_featured' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([], $this->gallery($customer));
        $this->assertTrue(Activity::where('log_name', 'customer_gallery_photo')->where('subject_id', $photo->id)->where('event', 'updated')->exists());
    }

    public function test_admin_deletes_a_photo(): void
    {
        $admin = $this->asAdmin();
        $customer = $this->customer();
        $photo = $this->showcase($customer);

        Livewire::test(ListCustomerGalleryPhotos::class)->callTableAction('delete', $photo);

        $this->actingAs($admin, 'web');
        $this->assertNull(CustomerGalleryPhoto::find($photo->id));
        $this->assertSame([], $this->gallery($customer));
    }

    public function test_permissions_per_action(): void
    {
        $photo = $this->showcase($this->customer());

        $default = User::create(['name' => 'Kasir A', 'email' => 'a@test.local', 'password' => 'x']);
        $default->assignRole('kasir');
        $noMenu = User::create(['name' => 'Kasir C', 'email' => 'c@test.local', 'password' => 'x', 'menu_access' => ['SomeOtherResource']]);
        $noMenu->assignRole('kasir');

        $this->actingAs($default, 'web');
        $this->assertTrue(CustomerGalleryPhotoResource::canViewAny());
        $this->assertTrue(CustomerGalleryPhotoResource::canCreate());
        $this->assertTrue(CustomerGalleryPhotoResource::canEdit($photo));
        $this->assertTrue(CustomerGalleryPhotoResource::canDelete($photo));

        $this->actingAs($noMenu, 'web');
        $this->assertFalse(CustomerGalleryPhotoResource::canViewAny());
        $this->assertFalse(CustomerGalleryPhotoResource::canCreate());
        $this->assertFalse(CustomerGalleryPhotoResource::canDelete($photo));
    }
}
