<?php

namespace Tests\Feature;

use App\Filament\Resources\BookingResource;
use App\Filament\Resources\BookingResource\Pages\CreateBooking;
use App\Filament\Resources\BookingResource\Pages\EditBooking;
use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Filament\Resources\BookingResource\Pages\ViewBooking;
use App\Filament\Resources\BookingResource\RelationManagers\CancellationRequestsRelationManager;
use App\Filament\Resources\BookingResource\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\BookingResource\RelationManagers\RescheduleRequestsRelationManager;
use App\Filament\Widgets\BookingStatsWidget;
use App\Filament\Widgets\PerluPerhatianWidget;
use App\Models\Booking;
use App\Models\BookingCancellationRequest;
use App\Models\BookingRescheduleRequest;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Smoke test halaman Filament Booking: semua halaman, filter, relation
 * manager, dan widget baru harus bisa dirender tanpa error (kolom/filter/
 * ikon salah menghasilkan 500 yang tidak tertangkap test service).
 */
class BookingFilamentSmokeTest extends TestCase
{
    use RefreshDatabase;

    private Booking $confirmed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountSeeder::class);
        Http::fake();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);

        $make = fn (array $o) => Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id'    => $customer->id,
            'store_id'       => $store->id,
            'service_type'   => 'Pelindung Cat (PPF)',
            'product_ppf'    => true,
            'preferred_date' => now()->addDays(3)->toDateString(),
            'status'         => 'confirmed',
        ], $o));

        $pending = $make(['status' => 'pending']);
        $this->confirmed = $make(['current_stage' => 'ppf_installation']);
        $make(['preferred_date' => now()->subDays(3)->toDateString()]); // lewat tanggal, belum dikerjakan
        $cancelled = $make(['status' => 'pending']);
        $cancelled->cancelWith('staff', null, 'Uji batal');

        BookingRescheduleRequest::create([
            'booking_id' => $pending->id, 'customer_id' => $customer->id,
            'requested_date' => now()->addDays(9)->toDateString(), 'status' => 'pending',
        ]);
        BookingCancellationRequest::create([
            'booking_id' => $this->confirmed->id, 'customer_id' => $customer->id,
            'reason' => 'Berhalangan', 'status' => 'pending',
        ]);
    }

    public function test_booking_list_renders_with_records(): void
    {
        Livewire::test(ListBookings::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(Booking::whereIn('status', ['pending', 'confirmed'])->get());
    }

    public function test_every_booking_filter_can_be_applied(): void
    {
        foreach (['pending_sla', 'reschedule_requested', 'cancellation_requested', 'overdue_confirmed', 'siap_qc', 'upcoming'] as $filter) {
            Livewire::test(ListBookings::class)
                ->filterTable($filter)
                ->assertSuccessful();
        }
    }

    public function test_siap_qc_filter_finds_booking_at_last_product_stage(): void
    {
        Livewire::test(ListBookings::class)
            ->filterTable('siap_qc')
            ->assertCanSeeTableRecords([$this->confirmed]);
    }

    public function test_booking_view_and_edit_pages_render(): void
    {
        Livewire::test(ViewBooking::class, ['record' => $this->confirmed->getKey()])->assertSuccessful();
        Livewire::test(EditBooking::class, ['record' => $this->confirmed->getKey()])->assertSuccessful();
    }

    public function test_booking_create_page_renders(): void
    {
        Livewire::test(CreateBooking::class)->assertSuccessful();
    }

    public function test_relation_managers_render(): void
    {
        foreach ([MessagesRelationManager::class, RescheduleRequestsRelationManager::class, CancellationRequestsRelationManager::class] as $manager) {
            Livewire::test($manager, ['ownerRecord' => $this->confirmed, 'pageClass' => ViewBooking::class])
                ->assertSuccessful();
        }
    }

    public function test_dashboard_widgets_render(): void
    {
        Livewire::test(PerluPerhatianWidget::class)->assertSuccessful();
        Livewire::test(BookingStatsWidget::class)->assertSuccessful();
    }

    public function test_completed_status_option_is_disabled_until_quality_check(): void
    {
        $this->assertNotNull($this->confirmed->completionBlocker());

        $this->confirmed->update(['current_stage' => 'qc']);
        $this->assertNull($this->confirmed->fresh()->completionBlocker());
        $this->assertTrue(BookingResource::canViewAny());
    }
}
