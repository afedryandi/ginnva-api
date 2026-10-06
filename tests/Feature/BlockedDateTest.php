<?php

namespace Tests\Feature;

use App\Filament\Resources\BlockedDateResource\Pages\CreateBlockedDate;
use App\Filament\Resources\BlockedDateResource\Pages\EditBlockedDate;
use App\Filament\Resources\BlockedDateResource\Pages\ListBlockedDates;
use App\Filament\Widgets\BlockedDateCalendarWidget;
use App\Models\BlockedDate;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tanggal Tidak Tersedia: blokir satu tanggal / rentang, penolakan (lampau,
 * duplikat, rentang terbalik), edit & hapus, scoping toko, widget kalender,
 * jejak audit, dan efeknya ke ketersediaan toko (Store::isClosedOn + API publik).
 */
class BlockedDateTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('store_manager', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);

        $this->admin = $this->user('super_admin', null);
        $this->actingAs($this->admin, 'web');
    }

    private function user(string $role, ?int $storeId, ?array $menuAccess = null): User
    {
        $user = User::create([
            'name' => ucfirst($role) . ' ' . uniqid(),
            'email' => uniqid() . '@test.local',
            'password' => 'x',
            'store_id' => $storeId,
            'menu_access' => $menuAccess,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function date(int $daysAhead): string
    {
        return now()->addDays($daysAhead)->toDateString();
    }

    private function blocked(Store $store, int $daysAhead, ?string $reason = 'Libur'): BlockedDate
    {
        return BlockedDate::create(['store_id' => $store->id, 'date' => $this->date($daysAhead), 'reason' => $reason]);
    }

    public function test_list_shows_upcoming_blocked_dates_by_default(): void
    {
        $upcoming = $this->blocked($this->storeA, 5);
        $past = BlockedDate::create(['store_id' => $this->storeA->id, 'date' => now()->subDays(5)->toDateString(), 'reason' => 'Lama']);

        Livewire::test(ListBlockedDates::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$upcoming])
            ->assertCanNotSeeTableRecords([$past]);
    }

    public function test_admin_blocks_a_single_date(): void
    {
        Livewire::test(CreateBlockedDate::class)
            ->fillForm(['store_id' => $this->storeA->id, 'date' => $this->date(4), 'reason' => 'Maintenance'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, BlockedDate::where('store_id', $this->storeA->id)->count());
        $this->assertTrue($this->storeA->fresh()->isClosedOn(now()->addDays(4)));
    }

    public function test_admin_blocks_a_date_range_as_one_row_per_day(): void
    {
        Livewire::test(CreateBlockedDate::class)
            ->fillForm(['store_id' => $this->storeA->id, 'date' => $this->date(4), 'date_end' => $this->date(6), 'reason' => 'Lebaran'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            [$this->date(4), $this->date(5), $this->date(6)],
            BlockedDate::where('store_id', $this->storeA->id)->orderBy('date')->get()->map(fn ($b) => $b->date->toDateString())->all()
        );
    }

    public function test_past_reversed_and_duplicate_blocks_are_rejected(): void
    {
        // Tanggal lampau.
        Livewire::test(CreateBlockedDate::class)
            ->fillForm(['store_id' => $this->storeA->id, 'date' => now()->subDay()->toDateString()])
            ->call('create')
            ->assertHasFormErrors(['date']);

        // Rentang terbalik.
        Livewire::test(CreateBlockedDate::class)
            ->fillForm(['store_id' => $this->storeA->id, 'date' => $this->date(6), 'date_end' => $this->date(4)])
            ->call('create')
            ->assertHasFormErrors(['date_end']);

        // Duplikat tanggal mulai.
        $this->blocked($this->storeA, 4);
        Livewire::test(CreateBlockedDate::class)
            ->fillForm(['store_id' => $this->storeA->id, 'date' => $this->date(4)])
            ->call('create')
            ->assertHasFormErrors(['date']);

        $this->assertSame(1, BlockedDate::count());
    }

    public function test_range_overlapping_an_existing_block_creates_nothing(): void
    {
        $this->blocked($this->storeA, 5); // tanggal tengah sudah diblokir

        Livewire::test(CreateBlockedDate::class)
            ->fillForm(['store_id' => $this->storeA->id, 'date' => $this->date(4), 'date_end' => $this->date(6)])
            ->call('create')
            ->assertHasFormErrors(['date']);

        $this->assertSame(1, BlockedDate::where('store_id', $this->storeA->id)->count(), 'Tidak boleh ada separuh rentang yang tersimpan.');
    }

    public function test_same_date_can_be_blocked_for_different_stores(): void
    {
        $this->blocked($this->storeA, 4);

        Livewire::test(CreateBlockedDate::class)
            ->fillForm(['store_id' => $this->storeB->id, 'date' => $this->date(4)])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, BlockedDate::count());
    }

    public function test_edit_changes_reason_and_delete_reopens_the_date(): void
    {
        $block = $this->blocked($this->storeA, 4, 'Salah ketik');
        $this->assertTrue($this->storeA->fresh()->isClosedOn(now()->addDays(4)));

        Livewire::test(EditBlockedDate::class, ['record' => $block->getKey()])
            ->fillForm(['reason' => 'Libur nasional'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Libur nasional', $block->fresh()->reason);

        $block->delete();
        $this->assertFalse($this->storeA->fresh()->isClosedOn(now()->addDays(4)), 'Menghapus blokir membuka tanggal itu lagi.');
    }

    public function test_store_manager_sees_only_own_store_and_blocks_for_own_store(): void
    {
        $mine = $this->blocked($this->storeA, 4);
        $others = $this->blocked($this->storeB, 4);

        $this->actingAs($this->user('store_manager', $this->storeA->id), 'web');

        Livewire::test(ListBlockedDates::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$others]);

        // store_id di form dikunci (disabled): tersimpan sebagai toko sendiri.
        Livewire::test(CreateBlockedDate::class)
            ->fillForm(['date' => $this->date(8), 'reason' => 'Renovasi kecil'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(BlockedDate::where('store_id', $this->storeA->id)->whereDate('date', $this->date(8))->exists());
        $this->assertFalse(BlockedDate::where('store_id', $this->storeB->id)->whereDate('date', $this->date(8))->exists());
    }

    public function test_block_and_unblock_are_recorded_in_activity_log(): void
    {
        $block = $this->blocked($this->storeA, 4);
        $block->delete();

        $descriptions = Activity::where('log_name', 'blocked_date')->orderBy('id')->pluck('description')->all();

        $this->assertCount(2, $descriptions);
        $this->assertStringContainsString('diblokir', $descriptions[0]);
        $this->assertStringContainsString('kembali tersedia', $descriptions[1]);
    }

    public function test_public_api_reports_blocked_dates_and_reopens_after_delete(): void
    {
        $block = $this->blocked($this->storeA, 4);

        $this->assertContains($this->date(4), $this->getJson("/api/stores/{$this->storeA->id}/blocked-dates")->json('data'));

        $block->delete();

        $this->assertNotContains($this->date(4), $this->getJson("/api/stores/{$this->storeA->id}/blocked-dates")->json('data'));
    }

    public function test_calendar_widget_marks_blocked_days_and_locks_store_for_staff(): void
    {
        // Hari ini pasti ada di bulan yang tampil.
        $today = BlockedDate::create(['store_id' => $this->storeA->id, 'date' => now()->toDateString(), 'reason' => 'Libur nasional']);

        $component = Livewire::test(BlockedDateCalendarWidget::class)->assertSuccessful();
        $days = collect($component->instance()->getCalendarDays());
        $this->assertNotEmpty($days);
        $this->assertSame(0, $days->count() % 7);
        $this->assertTrue($days->firstWhere('date', $today->date->toDateString())['blocked']);
        $component->assertSee('Libur nasional');

        // Staf toko B tidak boleh melihat toko A walau memanipulasi storeId.
        $this->actingAs($this->user('store_manager', $this->storeB->id), 'web');
        BlockedDate::create(['store_id' => $this->storeB->id, 'date' => now()->toDateString(), 'reason' => 'Libur toko B']);

        // Diuji langsung di level class (render Livewire di test tidak
        // mempertahankan user pada request update widget ini).
        $widget = new BlockedDateCalendarWidget();
        $widget->mount();
        $widget->storeId = $this->storeA->id; // manipulasi manual

        $this->assertSame($this->storeB->id, $widget->effectiveStoreId());

        $reasons = collect($widget->getCalendarDays())->where('blocked', true)->pluck('reason')->all();
        $this->assertContains('Libur toko B', $reasons);
        $this->assertNotContains('Libur nasional', $reasons);
    }
}
