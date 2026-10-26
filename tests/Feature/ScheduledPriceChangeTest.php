<?php

namespace Tests\Feature;

use App\Filament\Resources\ScheduledPriceChangeResource;
use App\Filament\Resources\ScheduledPriceChangeResource\Pages\CreateScheduledPriceChange;
use App\Filament\Resources\ScheduledPriceChangeResource\Pages\ListScheduledPriceChanges;
use App\Models\CustomerGroup;
use App\Models\FilmProduct;
use App\Models\FilmProductGroupPrice;
use App\Models\FilmProductPrice;
use App\Models\ScheduledPriceChange;
use App\Models\User;
use App\Services\ScheduledPriceChangeService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Perubahan Harga Terjadwal: rumus persen dan pembulatan, yang diubah (harga dasar, per ukuran, per grup bila dipilih), pratinjau tanpa
 * menulis, penerapan satu kali, perintah harian, jejak riwayat, dan halaman pengelolaan (akses penuh saja). "Hari ini" dibekukan di 20 Nov 2026.
 */
class ScheduledPriceChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'kasir'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-11-20 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]), fn (User $u) => $u->assignRole('super_admin'));
    }

    private function product(string $sku, float $base, array $sizes = [], bool $active = true): FilmProduct
    {
        $product = FilmProduct::create(['sku' => $sku, 'name' => "Produk {$sku}", 'product_type' => 'ppf', 'position' => 'front', 'base_price' => $base, 'is_active' => $active]);
        foreach ($sizes as $size => $price) {
            FilmProductPrice::create(['film_product_id' => $product->id, 'vehicle_size' => $size, 'price' => $price]);
        }

        return $product;
    }

    private function change(array $extra = []): ScheduledPriceChange
    {
        return ScheduledPriceChange::create(array_merge([
            'name' => 'Kenaikan 5%', 'effective_date' => '2026-11-20', 'percent' => 5, 'rounding_step' => 1000, 'status' => 'scheduled',
        ], $extra));
    }

    // ------------------------------------------------------------- rumus

    public function test_the_new_price_follows_the_percent_and_rounding(): void
    {
        $this->assertSame(105000.0, ScheduledPriceChangeService::newPrice(100000, 5, 1000));
        $this->assertSame(162750.0, ScheduledPriceChangeService::newPrice(155000, 5, 0));
        $this->assertSame(90000.0, ScheduledPriceChangeService::newPrice(100000, -10, 0));
        $this->assertSame(100000.0, ScheduledPriceChangeService::newPrice(100000, 2, 10000), 'Dibulatkan ke Rp10.000 terdekat.');
        $this->assertSame(0.0, ScheduledPriceChangeService::newPrice(1000, -99.9, 1000), 'Tidak pernah negatif.');
    }

    public function test_an_invalid_percent_is_rejected(): void
    {
        $service = app(ScheduledPriceChangeService::class);

        foreach ([0, 400, -95] as $percent) {
            try {
                $service->preview(['percent' => $percent]);
                $this->fail("Persen {$percent} harus ditolak.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Persen', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------- yang berubah

    public function test_applying_changes_the_base_and_size_prices_but_not_group_prices_by_default(): void
    {
        $product = $this->product('PPF-A', 2000000, ['M' => 2200000, 'L' => 2400000]);
        $group = CustomerGroup::create(['name' => 'Korporat', 'is_active' => true, 'sort_order' => 1]);
        $groupPrice = FilmProductGroupPrice::create(['film_product_id' => $product->id, 'customer_group_id' => $group->id, 'price' => 1800000]);

        $count = app(ScheduledPriceChangeService::class)->apply($this->change());

        $this->assertSame(3, $count);
        $this->assertEquals(2100000, (float) $product->fresh()->base_price);
        $this->assertEquals(2310000, (float) FilmProductPrice::where('vehicle_size', 'M')->value('price'));
        $this->assertEquals(2520000, (float) FilmProductPrice::where('vehicle_size', 'L')->value('price'));
        $this->assertEquals(1800000, (float) $groupPrice->fresh()->price, 'Harga grup tidak ikut kecuali dipilih.');
    }

    public function test_group_prices_follow_only_when_included(): void
    {
        $product = $this->product('PPF-A', 2000000);
        $group = CustomerGroup::create(['name' => 'Korporat', 'is_active' => true, 'sort_order' => 1]);
        $groupPrice = FilmProductGroupPrice::create(['film_product_id' => $product->id, 'customer_group_id' => $group->id, 'price' => 1800000]);

        app(ScheduledPriceChangeService::class)->apply($this->change(['include_group_prices' => true]));

        $this->assertEquals(1890000, (float) $groupPrice->fresh()->price);
    }

    public function test_zero_prices_and_inactive_products_are_skipped_unless_selected(): void
    {
        $active = $this->product('PPF-ON', 1000000, ['M' => 0]);
        $inactive = $this->product('PPF-OFF', 1000000, [], active: false);

        app(ScheduledPriceChangeService::class)->apply($this->change());

        $this->assertEquals(1050000, (float) $active->fresh()->base_price);
        $this->assertEquals(0, (float) FilmProductPrice::where('film_product_id', $active->id)->value('price'), 'Harga nol dilewati.');
        $this->assertEquals(1000000, (float) $inactive->fresh()->base_price, 'Produk nonaktif tidak ikut bila tidak dipilih.');

        app(ScheduledPriceChangeService::class)->apply($this->change(['name' => 'Khusus', 'product_ids' => [$inactive->id]]));
        $this->assertEquals(1050000, (float) $inactive->fresh()->base_price, 'Produk yang dipilih ikut walau nonaktif.');
    }

    public function test_selecting_products_leaves_the_others_untouched(): void
    {
        $a = $this->product('PPF-A', 1000000);
        $b = $this->product('PPF-B', 1000000);

        app(ScheduledPriceChangeService::class)->apply($this->change(['product_ids' => [$a->id]]));

        $this->assertEquals(1050000, (float) $a->fresh()->base_price);
        $this->assertEquals(1000000, (float) $b->fresh()->base_price);
    }

    public function test_the_preview_writes_nothing(): void
    {
        $product = $this->product('PPF-A', 2000000, ['M' => 2200000]);

        $preview = app(ScheduledPriceChangeService::class)->preview(['percent' => 5, 'rounding_step' => 1000]);

        $this->assertSame(2, $preview['count']);
        $this->assertSame(1, $preview['products']);
        $this->assertEquals(2100000, $preview['sample'][0]['new']);
        $this->assertEquals(2000000, (float) $product->fresh()->base_price);
    }

    // ------------------------------------------------------------- penerapan

    public function test_a_change_is_applied_only_once(): void
    {
        $product = $this->product('PPF-A', 1000000);
        $change = $this->change();
        $service = app(ScheduledPriceChangeService::class);

        $this->assertSame(1, $service->apply($change));
        $this->assertNull($service->apply($change), 'Kedua kalinya tidak menerapkan lagi.');

        $this->assertEquals(1050000, (float) $product->fresh()->base_price);
        $change->refresh();
        $this->assertSame('applied', $change->status);
        $this->assertSame(1, $change->affected_prices);
        $this->assertNotNull($change->applied_at);
    }

    public function test_the_old_and_new_prices_are_kept_in_the_activity_history(): void
    {
        $product = $this->product('PPF-A', 1000000, ['M' => 1100000]);
        Activity::query()->delete();

        app(ScheduledPriceChangeService::class)->apply($this->change());

        $log = Activity::where('log_name', 'film_product_price')->latest('id')->first();
        $this->assertNotNull($log, 'Perubahan harga per ukuran tercatat.');
        $this->assertEquals(1100000, (float) $log->properties['old']['price']);
        $this->assertEquals(1155000, (float) $log->properties['attributes']['price']);
        $this->assertTrue(Activity::where('subject_type', FilmProduct::class)->where('subject_id', $product->id)->exists(), 'Harga dasar produk juga tercatat.');
    }

    public function test_due_changes_are_applied_and_the_rest_are_left_alone(): void
    {
        $this->product('PPF-A', 1000000);
        $due = $this->change(['name' => 'Jatuh tempo', 'effective_date' => '2026-11-19']);
        $future = $this->change(['name' => 'Nanti', 'effective_date' => '2026-12-01']);
        $cancelled = $this->change(['name' => 'Batal', 'effective_date' => '2026-11-10', 'status' => 'cancelled']);

        $result = app(ScheduledPriceChangeService::class)->runDue();

        $this->assertCount(1, $result['applied']);
        $this->assertSame([], $result['failures']);
        $this->assertSame('applied', $due->fresh()->status);
        $this->assertSame('scheduled', $future->fresh()->status);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }

    public function test_the_daily_command_applies_due_changes_and_notifies_full_access(): void
    {
        $admin = $this->admin();
        $product = $this->product('PPF-A', 1000000);
        $this->change(['effective_date' => '2026-11-20']);

        $this->artisan('prices:apply-scheduled')->assertSuccessful();

        $this->assertEquals(1050000, (float) $product->fresh()->base_price);
        $this->assertSame(1, $admin->notifications()->count());
    }

    public function test_the_daily_command_is_quiet_when_nothing_is_due(): void
    {
        $this->change(['effective_date' => '2026-12-01']);

        $this->artisan('prices:apply-scheduled')->expectsOutputToContain('Tidak ada perubahan harga')->assertSuccessful();
    }

    // ------------------------------------------------------------- halaman

    public function test_only_full_access_can_open_the_page(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->assertTrue(ScheduledPriceChangeResource::canViewAny());

        $staff = User::create(['name' => 'Kasir', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]);
        $staff->assignRole('kasir');
        $this->actingAs($staff, 'web');
        $this->assertFalse(ScheduledPriceChangeResource::canViewAny());
        $this->assertFalse(ScheduledPriceChangeResource::canCreate());
    }

    public function test_creating_a_change_schedules_it_for_the_current_user(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'web');
        $this->product('PPF-A', 1000000);

        Livewire::test(CreateScheduledPriceChange::class)
            ->fillForm(['name' => 'Kenaikan Desember', 'effective_date' => '2026-12-01', 'percent' => 5, 'rounding_step' => 1000, 'product_ids' => [], 'include_group_prices' => false])
            ->call('create')
            ->assertHasNoFormErrors();

        $change = ScheduledPriceChange::firstOrFail();
        $this->assertSame('scheduled', $change->status);
        $this->assertSame($admin->id, $change->created_by);
        $this->assertNull($change->product_ids, 'Kosong berarti semua produk aktif.');
    }

    public function test_a_zero_percent_or_past_date_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'web');

        Livewire::test(CreateScheduledPriceChange::class)
            ->fillForm(['name' => 'Nol', 'effective_date' => '2026-12-01', 'percent' => 0, 'rounding_step' => 0])
            ->call('create')
            ->assertHasFormErrors(['percent']);

        Livewire::test(CreateScheduledPriceChange::class)
            ->fillForm(['name' => 'Kemarin', 'effective_date' => '2026-11-01', 'percent' => 5, 'rounding_step' => 0])
            ->call('create')
            ->assertHasFormErrors(['effective_date']);

        $this->assertSame(0, ScheduledPriceChange::count());
    }

    public function test_the_apply_now_and_cancel_buttons_follow_the_status_and_date(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->product('PPF-A', 1000000);
        $today = $this->change(['effective_date' => '2026-11-20']);
        $later = $this->change(['name' => 'Nanti', 'effective_date' => '2026-12-05']);

        Livewire::test(ListScheduledPriceChanges::class)
            ->assertTableActionVisible('apply_now', $today)
            ->assertTableActionHidden('apply_now', $later)
            ->assertTableActionVisible('cancel', $later)
            ->callTableAction('cancel', $later)
            ->callTableAction('apply_now', $today);

        $this->assertSame('cancelled', $later->fresh()->status);
        $this->assertSame('applied', $today->fresh()->status);

        Livewire::test(ListScheduledPriceChanges::class)
            ->assertTableActionHidden('cancel', $today->fresh())
            ->assertTableActionHidden('apply_now', $today->fresh());
    }

    public function test_an_applied_change_can_no_longer_be_edited(): void
    {
        $this->actingAs($this->admin(), 'web');

        $this->assertTrue(ScheduledPriceChangeResource::canEdit($this->change()));
        $this->assertFalse(ScheduledPriceChangeResource::canEdit($this->change(['status' => 'applied'])));
        $this->assertFalse(ScheduledPriceChangeResource::canDelete($this->change()));
    }
}
