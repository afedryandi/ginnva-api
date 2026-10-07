<?php

namespace Tests\Feature;

use App\Filament\Pages\CustomerSatisfactionReport;
use App\Filament\Resources\StoreReviewResource;
use App\Filament\Resources\StoreReviewResource\Pages\ListStoreReviews;
use App\Filament\Resources\StoreReviewResource\Pages\ViewStoreReview;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\StoreReview;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Review Toko: customer mengirim ulasan (hanya booking miliknya yang sudah
 * "Serah Terima"), agregat toko, notifikasi review negatif ke staf yang
 * berhak, daftar & tindak lanjut di Filament, scoping toko, dan laporan
 * Kepuasan Pelanggan.
 */
class StoreReviewTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('store_manager', 'web');

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    private function customer(): Customer
    {
        return Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
    }

    private function user(string $role, ?int $storeId, ?array $menuAccess = null): User
    {
        $user = User::create([
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x',
            'store_id' => $storeId, 'menu_access' => $menuAccess,
        ]);
        $user->assignRole($role);

        return $user;
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
            'current_stage'  => 'completed',
        ], $overrides));
    }

    private function review(string $sentiment, ?Store $store = null, array $overrides = []): StoreReview
    {
        $customer = $this->customer();

        return StoreReview::create(array_merge([
            'booking_id'  => $this->booking($customer, ['store_id' => ($store ?? $this->store)->id])->id,
            'customer_id' => $customer->id,
            'store_id'    => ($store ?? $this->store)->id,
            'sentiment'   => $sentiment,
            'tags'        => [],
            'comment'     => 'Ulasan ' . $sentiment,
        ], $overrides));
    }

    // ------------------------------------------------------------- API customer

    public function test_customer_submits_a_positive_review_and_store_aggregates_update(): void
    {
        $customer = $this->customer();
        $booking = $this->booking($customer);

        $response = $this->actingAs($customer, 'customer')->postJson("/api/customer/bookings/{$booking->id}/review", [
            'sentiment' => 'positive', 'tags' => ['pelayanan_ramah', 'hasil_rapi'], 'comment' => 'Mantap',
        ])->assertStatus(201);

        $this->assertTrue($response->json('data.suggest_google_review'));

        $review = StoreReview::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(['pelayanan_ramah', 'hasil_rapi'], $review->tags);
        $this->assertSame($this->store->id, $review->store_id);
        $this->assertSame(1, (int) $this->store->fresh()->reviews_count);
        $this->assertSame(1, (int) $this->store->fresh()->positive_reviews_count);
    }

    public function test_neutral_and_negative_reviews_do_not_suggest_google_nor_count_as_positive(): void
    {
        foreach (['neutral', 'negative'] as $sentiment) {
            $customer = $this->customer();
            $booking = $this->booking($customer);

            $this->actingAs($customer, 'customer')->postJson("/api/customer/bookings/{$booking->id}/review", ['sentiment' => $sentiment])
                ->assertStatus(201)->assertJsonPath('data.suggest_google_review', false);
        }

        $store = $this->store->fresh();
        $this->assertSame(2, (int) $store->reviews_count);
        $this->assertSame(0, (int) $store->positive_reviews_count);
    }

    public function test_review_rules_login_ownership_stage_duplicate_and_validation(): void
    {
        $customer = $this->customer();
        $stranger = $this->customer();
        $booking = $this->booking($customer);
        $notDone = $this->booking($customer, ['current_stage' => 'qc']);
        $post = fn (Customer $who, Booking $b, array $data) => $this->actingAs($who, 'customer')->postJson("/api/customer/bookings/{$b->id}/review", $data);

        $this->postJson("/api/customer/bookings/{$booking->id}/review", ['sentiment' => 'positive'])->assertStatus(401);
        $post($stranger, $booking, ['sentiment' => 'positive'])->assertStatus(404);
        $post($customer, $notDone, ['sentiment' => 'positive'])->assertStatus(422);

        $post($customer, $booking, ['sentiment' => 'bagus-sekali'])->assertStatus(422);
        $post($customer, $booking, ['sentiment' => 'positive', 'tags' => ['bukan-tag']])->assertStatus(422);
        $post($customer, $booking, ['sentiment' => 'positive', 'comment' => str_repeat('a', 2001)])->assertStatus(422);
        $this->assertSame(0, StoreReview::count());

        $post($customer, $booking, ['sentiment' => 'positive'])->assertStatus(201);
        $post($customer, $booking, ['sentiment' => 'negative'])->assertStatus(409);
        $this->assertSame(1, StoreReview::count());
    }

    public function test_chat_endpoint_exposes_submitted_review_to_the_customer(): void
    {
        $customer = $this->customer();
        $booking = $this->booking($customer);

        $this->actingAs($customer, 'customer')->getJson("/api/customer/bookings/{$booking->id}/messages")
            ->assertSuccessful()->assertJsonPath('data.has_review', false);

        $this->actingAs($customer, 'customer')->postJson("/api/customer/bookings/{$booking->id}/review", ['sentiment' => 'neutral', 'comment' => 'Biasa'])->assertStatus(201);

        $this->actingAs($customer, 'customer')->getJson("/api/customer/bookings/{$booking->id}/messages")
            ->assertSuccessful()
            ->assertJsonPath('data.has_review', true)
            ->assertJsonPath('data.review.sentiment', 'neutral')
            ->assertJsonPath('data.review.comment', 'Biasa');
    }

    public function test_negative_review_notifies_entitled_staff_only(): void
    {
        $admin = $this->user('super_admin', null);
        $managerSameStore = $this->user('store_manager', $this->store->id);
        $managerNoMenu = $this->user('store_manager', $this->store->id, ['SomeOtherResource']);
        $managerOtherStore = $this->user('store_manager', $this->otherStore->id);

        $this->review('positive');
        foreach ([$admin, $managerSameStore, $managerNoMenu, $managerOtherStore] as $u) {
            $this->assertSame(0, $u->notifications()->count(), 'Review positif tidak memicu notifikasi.');
        }

        $this->review('negative');

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(1, $managerSameStore->notifications()->count());
        $this->assertSame(0, $managerNoMenu->notifications()->count(), 'Tanpa akses menu Review Toko tidak diberi tahu.');
        $this->assertSame(0, $managerOtherStore->notifications()->count(), 'Toko lain tidak diberi tahu.');
    }

    // ------------------------------------------------------------- Filament

    public function test_filament_list_filters_view_and_follow_up_flow(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = $this->user('super_admin', null);
        $this->actingAs($admin, 'web');

        $positive = $this->review('positive');
        $negative = $this->review('negative', null, ['tags' => ['proses_lambat'], 'comment' => 'Terlalu lama']);

        Livewire::test(ListStoreReviews::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$positive, $negative])
            ->filterTable('sentiment', 'negative')
            ->assertCanSeeTableRecords([$negative])
            ->assertCanNotSeeTableRecords([$positive]);

        Livewire::test(ViewStoreReview::class, ['record' => $negative->getKey()])->assertSuccessful();

        // Aksi tindak lanjut: hanya review negatif yang belum ditindaklanjuti; catatan wajib.
        Livewire::test(ListStoreReviews::class)
            ->assertTableActionHidden('mark_followed_up', $positive)
            ->callTableAction('mark_followed_up', $negative, data: ['follow_up_note' => '']) // catatan kosong ditolak
            ->assertHasTableActionErrors(['follow_up_note' => 'required']);
        $this->assertNull($negative->fresh()->followed_up_at);

        Livewire::test(ListStoreReviews::class)
            ->callTableAction('mark_followed_up', $negative, data: ['follow_up_note' => 'Sudah dihubungi via WhatsApp']);

        $fresh = $negative->fresh();
        $this->assertNotNull($fresh->followed_up_at);
        $this->assertSame($admin->id, $fresh->followed_up_by);
        $this->assertSame('Sudah dihubungi via WhatsApp', $fresh->follow_up_note);

        // Instance segar: helper menilai visibilitas dari model yang diberikan.
        Livewire::test(ListStoreReviews::class)->assertTableActionHidden('mark_followed_up', $negative->fresh());
    }

    public function test_navigation_badge_counts_only_unhandled_negative_reviews(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user('super_admin', null), 'web');

        $this->review('positive');
        $this->review('negative');
        $this->review('negative', null, ['followed_up_at' => now(), 'follow_up_note' => 'Selesai']);

        $this->assertSame('1', StoreReviewResource::getNavigationBadge());
    }

    public function test_store_manager_sees_only_own_store_reviews(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $mine = $this->review('positive');
        $others = $this->review('positive', $this->otherStore);

        $this->actingAs($this->user('store_manager', $this->store->id), 'web');

        Livewire::test(ListStoreReviews::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$others]);
    }

    public function test_satisfaction_report_summarises_reviews_and_scopes_stores(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->review('positive', null, ['tags' => ['hasil_rapi', 'pelayanan_ramah']]);
        $this->review('positive', null, ['tags' => ['hasil_rapi']]);
        $this->review('negative', null, ['tags' => ['proses_lambat']]);
        $this->review('neutral', $this->otherStore);

        $this->actingAs($this->user('super_admin', null), 'web');
        $all = Livewire::test(CustomerSatisfactionReport::class)->assertSuccessful()->instance()->getResult();

        $this->assertSame(4, $all['total']);
        $this->assertSame(2, $all['positive']);
        $this->assertSame(1, $all['neutral']);
        $this->assertSame(1, $all['negative']);
        $this->assertSame(1, $all['unfollowedNegativeCount']);
        $this->assertSame(2, $all['tagCounts']['hasil_rapi']);

        $this->actingAs($this->user('store_manager', $this->store->id), 'web');
        $mine = Livewire::test(CustomerSatisfactionReport::class)->assertSuccessful()->instance()->getResult();

        $this->assertSame(3, $mine['total'], 'Staf toko hanya melihat ulasan tokonya sendiri.');
        $this->assertSame(0, $mine['neutral']);
    }
}
