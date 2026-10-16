<?php

namespace Tests\Feature;

use App\Filament\Resources\CaseStudyResource\Pages\EditCaseStudy;
use App\Models\Booking;
use App\Models\BookingHandover;
use App\Models\BookingIntakePhoto;
use App\Models\CaseStudy;
use App\Models\Customer;
use App\Models\MarketingConsent;
use App\Models\Store;
use App\Models\User;
use App\Services\GalleryNominationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Marketing/Konten Fase 3 "Bukti sosial" (2026-10-25): izin foto pelanggan dicatat saat serah terima (bawaan TIDAK), foto pekerjaan hanya
 * bisa dinominasikan ke galeri dengan izin aktif, dan materi dari booking baru tayang bila plat tertutup stiker dan teks alternatif terisi.
 */
class PhotoConsentGalleryTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Storage::fake('public');

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
    }

    private function user(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::create(['name' => ucfirst($role), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'join_date' => '2025-01-01', 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function booking(): Booking
    {
        return Booking::create([
            'booking_number' => 'BKG-PC-' . uniqid(),
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'vehicle_brand' => 'Toyota',
            'vehicle_model' => 'Raize',
            'preferred_date' => now()->addDay()->toDateString(),
            'status' => 'confirmed',
            'current_stage' => 'qc',
        ]);
    }

    /** Booking dengan serah terima, foto akhir, dan (opsional) izin aktif. */
    private function handedOver(bool $withConsent = true): Booking
    {
        $booking = $this->booking();
        Storage::disk('public')->put('booking-handovers/akhir.jpg', 'jpeg-bytes');
        BookingHandover::create([
            'booking_id' => $booking->id, 'care_instructions_given' => true, 'wet_signature_required' => true, 'wet_signature_received' => true,
            'final_photo_path' => 'booking-handovers/akhir.jpg', 'handed_over_at' => now(),
        ]);

        if ($withConsent) {
            MarketingConsent::create(['booking_id' => $booking->id, 'customer_id' => $this->customer->id, 'granted_at' => now()]);
        }

        return $booking->fresh();
    }

    // ------------------------------------------------------------- izin saat serah terima

    public function test_consent_is_opt_in_and_recorded_only_when_the_customer_agrees(): void
    {
        $manager = $this->user('store_manager');

        $without = $this->booking();
        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$without->id}/handover", ['care_instructions_given' => true, 'wet_signature_received' => true])->assertCreated();
        $this->assertNull($without->fresh()->photoConsent, 'Bawaan tanpa izin.');

        $with = $this->booking();
        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$with->id}/handover", ['care_instructions_given' => true, 'wet_signature_received' => true, 'photo_consent' => true])->assertCreated();

        $consent = $with->fresh()->photoConsent;
        $this->assertNotNull($consent);
        $this->assertTrue($consent->isActive());
        $this->assertSame($manager->id, $consent->recorded_by_user_id);
        $this->assertSame($this->customer->id, $consent->customer_id);

        $this->actingAs($manager, 'api')->getJson("/api/staff/bookings/{$with->id}/handover")->assertOk()->assertJsonPath('data.handover.photo_consent', true);
        $this->actingAs($manager, 'api')->getJson("/api/staff/bookings/{$without->id}/handover")->assertOk()->assertJsonPath('data.handover.photo_consent', false);
    }

    // ------------------------------------------------------------- nominasi

    public function test_nomination_needs_an_active_consent_and_creates_an_inactive_copy(): void
    {
        $booking = $this->handedOver();
        Storage::disk('public')->put('booking-intake/depan.jpg', 'before');
        $before = BookingIntakePhoto::create(['booking_id' => $booking->id, 'angle' => 'depan', 'path' => 'booking-intake/depan.jpg']);

        $case = app(GalleryNominationService::class)->nominate($booking, $before);

        $this->assertFalse($case->is_active);
        $this->assertFalse($case->plate_blurred);
        $this->assertSame($booking->id, $case->booking_id);
        $this->assertSame('ppf', $case->service_type);
        $this->assertSame('Toyota Raize · PPF', $case->title);
        $this->assertNotSame('booking-handovers/akhir.jpg', $case->image, 'Disalin, bukan dipakai bersama.');
        Storage::disk('public')->assertExists($case->image);
        Storage::disk('public')->assertExists($case->before_image);

        $this->expectExceptionMessage('sudah pernah dinominasikan');
        app(GalleryNominationService::class)->nominate($booking);
    }

    public function test_nomination_is_refused_without_consent_or_after_it_is_withdrawn(): void
    {
        $service = app(GalleryNominationService::class);

        try {
            $service->nominate($this->handedOver(withConsent: false));
            $this->fail('Tanpa izin seharusnya ditolak.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('belum memberi izin', $e->getMessage());
        }

        $booking = $this->handedOver();
        $booking->photoConsent->withdraw();

        try {
            $service->nominate($booking->fresh());
            $this->fail('Izin yang ditarik seharusnya ditolak.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ditarik', $e->getMessage());
        }

        $this->assertSame(0, CaseStudy::count());
    }

    // ------------------------------------------------------------- syarat tayang

    public function test_booking_material_only_goes_live_with_consent_covered_plate_and_alt_text(): void
    {
        $case = app(GalleryNominationService::class)->nominate($this->handedOver());

        foreach ([[], ['plate_blurred' => true], ['alt_text' => 'Toyota Raize dengan PPF']] as $partial) {
            $case = $case->fresh(); // tiap percobaan mulai dari keadaan tersimpan, bukan sisa isian percobaan sebelumnya

            try {
                $case->fill($partial + ['is_active' => true])->save();
                $this->fail('Seharusnya ditolak, syarat belum lengkap.');
            } catch (ValidationException) {
                $this->assertFalse($case->fresh()->is_active);
            }
        }

        $case->fill(['plate_blurred' => true, 'alt_text' => 'Toyota Raize dengan PPF', 'is_active' => true])->save();
        $this->assertTrue($case->fresh()->is_active);
        $this->assertNull($case->fresh()->activationBlocker());
    }

    public function test_withdrawing_consent_takes_the_gallery_material_down(): void
    {
        $booking = $this->handedOver();
        $case = app(GalleryNominationService::class)->nominate($booking);
        $case->fill(['plate_blurred' => true, 'alt_text' => 'Foto', 'is_active' => true])->save();

        $this->getJson('/api/case-studies')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.alt_text', 'Foto')->assertJsonPath('data.0.service_type', 'ppf');

        $booking->fresh()->photoConsent->withdraw($this->user('direksi'), 'Pelanggan meminta');

        $this->assertFalse($case->fresh()->is_active);
        $this->assertNotNull($booking->fresh()->photoConsent->withdrawn_at);
        $this->assertSame('Pelanggan meminta', $booking->fresh()->photoConsent->withdrawn_note);
        $this->getJson('/api/case-studies')->assertOk()->assertJsonCount(0, 'data');

        // Tidak bisa ditayangkan lagi.
        $this->expectException(ValidationException::class);
        $case->fresh()->fill(['is_active' => true])->save();
    }

    public function test_legacy_gallery_items_without_a_booking_are_unaffected(): void
    {
        $vehicle = \App\Models\Vehicle::create(['brand' => 'Toyota', 'model' => 'Raize', 'size_category' => 'M']);
        $product = \App\Models\FilmProduct::create(['sku' => 'WF-A1', 'name' => 'A1', 'product_type' => 'window_film', 'position' => 'front', 'base_price' => 1, 'is_active' => true]);

        $case = CaseStudy::create(['vehicle_id' => $vehicle->id, 'film_product_id' => $product->id, 'title' => 'Lama', 'short_title' => 'Lama', 'image' => 'a.jpg', 'is_active' => true]);

        $this->assertTrue($case->fresh()->is_active);
        $this->assertNull($case->activationBlocker());
    }

    public function test_the_panel_refuses_to_publish_material_that_is_not_ready(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user('super_admin'), 'web');
        $case = app(GalleryNominationService::class)->nominate($this->handedOver());

        Livewire::test(EditCaseStudy::class, ['record' => $case->getKey()])
            ->fillForm(['is_active' => true, 'plate_blurred' => false, 'alt_text' => 'Foto'])
            ->call('save');

        $this->assertFalse($case->fresh()->is_active);
    }
}
