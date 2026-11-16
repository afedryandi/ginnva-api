<?php

namespace Tests\Feature;

use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Daftar Booking Instalasi (redesain): tab status, aksi dikelompokkan, kolom ringkas. */
class BookingListRedesignTest extends TestCase
{
    use RefreshDatabase;

    private Booking $pending;
    private Booking $confirmed;
    private Booking $oldCompleted;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        $make = fn (string $status, string $date) => Booking::create([
            'booking_number' => 'BKG-LR-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true,
            'preferred_date' => $date,
            'status' => $status,
        ]);

        $this->pending = $make('pending', now()->addDays(3)->toDateString());
        $this->confirmed = $make('confirmed', now()->addDays(4)->toDateString());
        $this->oldCompleted = $make('completed', now()->subDays(30)->toDateString());
    }

    public function test_the_default_tab_is_active_and_hides_old_completed_bookings(): void
    {
        Livewire::test(ListBookings::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->pending, $this->confirmed])
            ->assertCanNotSeeTableRecords([$this->oldCompleted]);
    }

    public function test_the_all_tab_and_each_status_tab_filter_the_list(): void
    {
        Livewire::test(ListBookings::class)
            ->set('activeTab', 'semua')
            ->assertCanSeeTableRecords([$this->pending, $this->confirmed, $this->oldCompleted])
            ->set('activeTab', 'completed')
            ->assertCanSeeTableRecords([$this->oldCompleted])
            ->assertCanNotSeeTableRecords([$this->pending, $this->confirmed])
            ->set('activeTab', 'pending')
            ->assertCanSeeTableRecords([$this->pending])
            ->assertCanNotSeeTableRecords([$this->confirmed]);
    }

    public function test_the_tabs_carry_counts(): void
    {
        $tabs = Livewire::test(ListBookings::class)->instance()->getTabs();

        $this->assertSame(['aktif', 'semua', 'pending', 'confirmed', 'completed', 'cancelled'], array_keys($tabs));
        $this->assertSame(1, $tabs['pending']->getBadge());
        $this->assertSame(1, $tabs['completed']->getBadge());
        $this->assertSame(2, $tabs['aktif']->getBadge());
    }

    public function test_row_actions_still_exist_inside_the_more_menu(): void
    {
        Livewire::test(ListBookings::class)
            ->assertTableActionExists('view')
            ->assertTableActionExists('edit')
            ->assertTableActionExists('quickCancel')
            ->assertTableActionExists('receive_down_payment')
            ->assertTableActionExists('whatsapp');
    }

    public function test_the_table_has_no_status_filter_anymore(): void
    {
        $filters = Livewire::test(ListBookings::class)->instance()->getTable()->getFilters();

        $this->assertArrayNotHasKey('status', $filters);
        $this->assertArrayHasKey('pending_sla', $filters);
    }
}
