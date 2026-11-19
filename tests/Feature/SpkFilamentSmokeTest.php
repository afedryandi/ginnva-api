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

    public function test_the_status_tabs_split_in_progress_from_finished_vehicles(): void
    {
        // Kendaraan belum keluar: ada di tab bawaan "Dikerjakan", tidak di "Selesai".
        Livewire::test(ListSpks::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->spk]);

        Livewire::test(ListSpks::class)->set('activeTab', 'selesai')->assertCanNotSeeTableRecords([$this->spk]);

        $this->spk->update(['checked_out_at' => now()]);

        Livewire::test(ListSpks::class)->set('activeTab', 'dikerjakan')->assertCanNotSeeTableRecords([$this->spk]);
        Livewire::test(ListSpks::class)->set('activeTab', 'selesai')->assertCanSeeTableRecords([$this->spk]);
        Livewire::test(ListSpks::class)->set('activeTab', 'semua')->assertCanSeeTableRecords([$this->spk]);
    }

    public function test_the_damage_filter_and_clean_ui_apply_to_the_spk_list(): void
    {
        Livewire::test(ListSpks::class)
            ->set('activeTab', 'semua')
            ->filterTable('ada_kerusakan', true)
            ->assertCanNotSeeTableRecords([$this->spk]);

        Livewire::test(ListSpks::class)
            ->set('activeTab', 'semua')
            ->filterTable('ada_kerusakan', false)
            ->assertCanSeeTableRecords([$this->spk]);

        $this->assertTrue(\App\Support\CleanUi::appliesTo(new ListSpks()));
    }
    public function test_spk_list_renders_with_record(): void
    {
        Livewire::test(ListSpks::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->spk]);
    }

    public function test_spk_view_page_renders_with_summary_and_checked_items(): void
    {
        Livewire::test(\App\Filament\Resources\SpkResource\Pages\ViewSpk::class, ['record' => $this->spk->getKey()])
            ->assertSuccessful()
            ->assertSee($this->spk->spk_number)
            ->assertSee('PPF');
    }

    public function test_spk_create_and_edit_pages_render(): void
    {
        Livewire::test(CreateSpk::class)->assertSuccessful();
        Livewire::test(EditSpk::class, ['record' => $this->spk->getKey()])->assertSuccessful();
    }

    public function test_the_spk_number_can_be_typed_on_create_but_not_changed_on_edit(): void
    {
        Livewire::test(CreateSpk::class)
            ->assertFormFieldExists('spk_number', fn ($field) => ! $field->isDisabled());

        Livewire::test(EditSpk::class, ['record' => $this->spk->getKey()])
            ->assertFormFieldExists('spk_number', fn ($field) => $field->isDisabled());
    }

    public function test_spk_created_from_booking_prechecks_work_items(): void
    {
        $labels = $this->spk->checklistItems->where('is_checked', true)->pluck('label')->all();

        $this->assertContains('PPF', $labels);
        $this->assertNotContains('Kaca Film', $labels);
    }

    public function test_the_default_work_items_include_premium_wash_and_light_services_are_prechecked(): void
    {
        $this->assertContains('Premium Wash', \App\Models\Spk::DEFAULT_CHECKLIST['pekerjaan']);
        $this->assertContains('Premium Wash', $this->spk->checklistItems->where('category', 'pekerjaan')->pluck('label')->all(), 'SPK baru memuat butir Premium Wash.');

        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id'    => $this->spk->booking->customer_id,
            'store_id'       => $this->spk->store_id,
            'service_type'   => 'Premium Wash',
            'product_premium_wash' => true,
            'product_detailing' => true,
            'preferred_date' => now()->addDays(4)->toDateString(),
            'status'         => 'confirmed',
        ]);

        $labels = app(SpkService::class)->createFromBooking($booking, null)->checklistItems->where('is_checked', true)->pluck('label')->all();

        $this->assertContains('Premium Wash', $labels);
        $this->assertContains('Full Detailing', $labels);
        $this->assertNotContains('PPF', $labels);
    }

    public function test_creating_an_spk_checks_the_booked_services_unless_staff_already_chose(): void
    {
        $booking = fn (array $flags) => Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id'    => $this->spk->booking->customer_id,
            'store_id'       => $this->spk->store_id,
            'service_type'   => 'Layanan Uji',
            'preferred_date' => now()->addDays(5)->toDateString(),
            'status'         => 'confirmed',
        ], $flags));
        $blank = collect(\App\Models\Spk::DEFAULT_CHECKLIST['pekerjaan'])->map(fn ($label) => ['category' => 'pekerjaan', 'label' => $label, 'is_checked' => false])->all();
        $service = app(SpkService::class);

        // Form kosong (seperti dari mobile atau Filament): butir sesuai booking tercentang otomatis.
        $first = $booking(['product_kaca_film' => true, 'product_premium_wash' => true]);
        $spk = $service->create(['store_id' => $first->store_id, 'booking_id' => $first->id, 'customer_name' => 'Budi'], $blank, null);
        $checked = $spk->checklistItems->where('category', 'pekerjaan')->where('is_checked', true)->pluck('label')->sort()->values()->all();
        $this->assertSame(['Kaca Film', 'Premium Wash'], $checked);

        // Staf sudah mencentang sendiri: pilihannya dihormati, tidak ditimpa booking.
        $second = $booking(['product_ppf' => true]);
        $own = $blank;
        $own[0]['is_checked'] = true; // Full Detailing
        $spk = $service->create(['store_id' => $second->store_id, 'booking_id' => $second->id, 'customer_name' => 'Budi'], $own, null);
        $this->assertSame(['Full Detailing'], $spk->checklistItems->where('category', 'pekerjaan')->where('is_checked', true)->pluck('label')->all());
    }

    public function test_spk_pdf_template_renders(): void
    {
        $output = Pdf::loadView('pdf.spk', ['spk' => $this->spk->fresh(['checklistItems', 'damageMarks', 'booking', 'store']), 'isReprint' => false])
            ->setPaper('a4', 'portrait')
            ->output();

        $this->assertStringStartsWith('%PDF', $output);
    }
}
