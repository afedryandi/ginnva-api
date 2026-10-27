<?php

namespace Tests\Feature;

use App\Filament\Resources\QcChecklistItemResource;
use App\Filament\Resources\QcChecklistItemResource\Pages\CreateQcChecklistItem;
use App\Filament\Resources\QcChecklistItemResource\Pages\ListQcChecklistItems;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\QcChecklistItem;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Quality Check dengan daftar periksa (Fase 3A pengerjaan ulang booking, 2026-10-17): pemeriksa adalah Leader / Store Manager /
 * akses penuh dan bukan pengerja unit; lulus mengisi ready_at dan memberi tahu pelanggan; gagal tidak.
 */
class QualityCheckTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
    }

    private function user(string $role, string $name = 'Staff'): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::create(['name' => $name, 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'join_date' => '2025-01-01', 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function booking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'booking_number' => 'BKG-QC-' . uniqid(),
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => now()->addDay()->toDateString(),
            'status' => 'confirmed',
            'current_stage' => 'ppf_installation',
        ], $overrides));
    }

    /** @return array<int, array{item_id: int, passed: bool, note?: string}> */
    private function answers(Booking $booking, bool $allPass = true): array
    {
        $items = QcChecklistItem::whereIn('service_key', $booking->serviceKeys())->where('is_active', true)->get();

        return $items->values()->map(fn (QcChecklistItem $item, int $i) => [
            'item_id' => $item->id,
            'passed' => $allPass || $i > 0,
            'note' => ($allPass || $i > 0) ? null : 'Ada gelembung di kap mesin',
        ])->all();
    }

    public function test_defaults_are_seeded_for_all_four_services(): void
    {
        foreach (['kaca_film', 'ppf', 'detailing', 'premium_wash'] as $key) {
            $this->assertGreaterThan(0, QcChecklistItem::where('service_key', $key)->count(), $key);
        }
    }

    public function test_checklist_follows_the_services_on_the_booking(): void
    {
        $booking = $this->booking(['product_detailing' => true]); // PPF + Detailing
        $manager = $this->user('store_manager');

        $services = $this->actingAs($manager, 'api')->getJson("/api/staff/bookings/{$booking->id}/qc")->assertOk()->json('data.services');

        $this->assertSame(['ppf', 'detailing'], collect($services)->pluck('key')->all());
        $this->assertNotEmpty($services[0]['items']);
    }

    public function test_passing_sets_ready_at_and_notifies_the_customer(): void
    {
        $booking = $this->booking();
        $manager = $this->user('store_manager');

        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/qc", ['results' => $this->answers($booking)])
            ->assertCreated()->assertJsonPath('data.passed', true);

        $this->assertNotNull($booking->fresh()->ready_at);
        $this->assertTrue($booking->qcResults()->first()->passed);
        $this->assertSame($manager->id, $booking->qcResults()->first()->inspector_user_id);
        $this->assertTrue(CustomerNotification::where('customer_id', $this->customer->id)->where('title', 'Kendaraan Siap Diambil')->exists());
    }

    public function test_failing_does_not_set_ready_at_and_a_later_pass_does(): void
    {
        $booking = $this->booking();
        $manager = $this->user('store_manager');

        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/qc", ['results' => $this->answers($booking, false)])
            ->assertCreated()->assertJsonPath('data.passed', false);
        $this->assertNull($booking->fresh()->ready_at);
        $this->assertFalse(CustomerNotification::where('title', 'Kendaraan Siap Diambil')->exists());

        $latest = $this->actingAs($manager, 'api')->getJson("/api/staff/bookings/{$booking->id}/qc")->json('data.latest');
        $this->assertFalse($latest['passed']);
        $this->assertSame('Ada gelembung di kap mesin', $latest['failed_items'][0]['note']);

        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/qc", ['results' => $this->answers($booking)])->assertCreated();
        $this->assertNotNull($booking->fresh()->ready_at);
        $this->assertSame(2, $booking->qcResults()->count());
    }

    public function test_all_items_must_be_answered_and_failures_need_a_note(): void
    {
        $booking = $this->booking();
        $manager = $this->user('store_manager');
        $answers = $this->answers($booking);

        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/qc", ['results' => array_slice($answers, 1)])->assertStatus(422);

        $answers[0]['passed'] = false; // tanpa catatan
        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/qc", ['results' => $answers])->assertStatus(422);

        $this->assertSame(0, $booking->qcResults()->count());
    }

    public function test_inspector_cannot_be_the_installer_working_on_the_unit(): void
    {
        $booking = $this->booking();
        $leader = $this->user('installer_leader', 'Leader');
        $booking->installers()->attach($leader->id);

        $this->actingAs($leader, 'api')->postJson("/api/staff/bookings/{$booking->id}/qc", ['results' => $this->answers($booking)])->assertStatus(403);
        $this->assertFalse($this->actingAs($leader, 'api')->getJson("/api/staff/bookings/{$booking->id}/qc")->json('data.can_inspect'));

        // Leader lain (bukan pengerja) boleh.
        $other = $this->user('installer_leader', 'Leader Lain');
        $this->actingAs($other, 'api')->postJson("/api/staff/bookings/{$booking->id}/qc", ['results' => $this->answers($booking)])->assertCreated();
    }

    public function test_regular_installers_and_other_stores_cannot_inspect(): void
    {
        $booking = $this->booking();
        $installer = $this->user('installer');
        $booking->installers()->attach($installer->id);

        $this->actingAs($installer, 'api')->postJson("/api/staff/bookings/{$booking->id}/qc", ['results' => $this->answers($booking)])->assertStatus(403);

        $otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $outsider = $this->user('store_manager', 'Luar');
        $outsider->update(['store_id' => $otherStore->id]);
        // Scope toko menyembunyikan booking toko lain (404, bukan 403).
        $this->actingAs($outsider, 'api')->getJson("/api/staff/bookings/{$booking->id}/qc")->assertStatus(404);
    }

    public function test_qc_needs_all_product_stages_finished_and_a_confirmed_booking(): void
    {
        $manager = $this->user('store_manager');

        $early = $this->booking(['current_stage' => 'ppf_washing']);
        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$early->id}/qc", ['results' => $this->answers($early)])->assertStatus(403);

        $pending = $this->booking(['status' => 'pending']);
        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$pending->id}/qc", ['results' => $this->answers($pending)])->assertStatus(403);
    }

    public function test_light_only_bookings_can_be_inspected(): void
    {
        $booking = $this->booking(['service_type' => 'Premium Wash', 'product_ppf' => false, 'product_premium_wash' => true, 'current_stage' => null]);
        $manager = $this->user('store_manager');

        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/qc", ['results' => $this->answers($booking)])->assertCreated();
    }

    public function test_completion_requires_a_passed_qc_only_when_the_flag_is_on(): void
    {
        $manager = $this->user('store_manager');
        $booking = $this->booking(['current_stage' => 'qc']);

        // Bawaan: tidak diwajibkan.
        $this->assertNull($booking->completionBlocker());

        config(['booking.qc_required' => true]);
        $this->assertStringContainsString('Quality Check', (string) $booking->completionBlocker());

        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/qc", ['results' => $this->answers($booking, false)])->assertCreated();
        $this->assertNotNull($booking->fresh()->completionBlocker(), 'QC terbaru gagal masih memblokir.');

        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/qc", ['results' => $this->answers($booking)])->assertCreated();
        $this->assertNull($booking->fresh()->completionBlocker());
    }

    public function test_admin_menu_lists_the_checklist_and_only_full_access_can_edit(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = $this->user('super_admin');
        $admin->update(['menu_access' => null]);
        $this->actingAs($admin, 'web');

        Livewire::test(ListQcChecklistItems::class)->assertSuccessful()->assertSee('Tepi film rapi')->assertActionExists('create');
        $this->assertTrue(QcChecklistItemResource::canCreate());

        $staff = $this->user('store_manager');
        $this->actingAs($staff, 'web');
        $this->assertFalse(QcChecklistItemResource::canCreate());
        $this->assertFalse(QcChecklistItemResource::canEdit(QcChecklistItem::first()));
    }

    public function test_admin_can_add_a_checklist_item_from_the_panel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = $this->user('super_admin');
        $admin->update(['menu_access' => null]);
        $this->actingAs($admin, 'web');
        $serviceKey = QcChecklistItem::first()->service_key;

        Livewire::test(CreateQcChecklistItem::class)
            ->fillForm(['service_key' => $serviceKey, 'label' => 'Tidak ada gelembung udara', 'is_required' => true, 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(QcChecklistItemResource::getUrl('index'));

        $created = QcChecklistItem::where('label', 'Tidak ada gelembung udara')->firstOrFail();
        $this->assertSame((int) QcChecklistItem::where('service_key', $serviceKey)->where('id', '!=', $created->id)->max('sort_order') + 1, $created->sort_order, 'Butir baru di urutan paling akhir layanannya.');
    }

    public function test_items_can_be_reordered_by_dragging_and_only_within_the_order_given(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = $this->user('super_admin');
        $admin->update(['menu_access' => null]);
        $this->actingAs($admin, 'web');

        $serviceKey = QcChecklistItem::first()->service_key;
        $ids = QcChecklistItem::where('service_key', $serviceKey)->orderBy('sort_order')->pluck('id')->all();
        $reversed = array_reverse($ids);

        Livewire::test(ListQcChecklistItems::class)->call('reorderTable', $reversed);

        $this->assertSame($reversed, QcChecklistItem::where('service_key', $serviceKey)->orderBy('sort_order')->pluck('id')->all());
    }

    public function test_default_checklist_numbers_start_at_one_for_each_service(): void
    {
        foreach (QcChecklistItem::pluck('service_key')->unique() as $key) {
            $this->assertSame(1, (int) QcChecklistItem::where('service_key', $key)->min('sort_order'), "Urutan {$key} dimulai dari 1.");
        }
    }
}
