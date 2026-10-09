<?php

namespace Tests\Feature;

use App\Filament\Resources\PartnerPointTransactionResource;
use App\Filament\Resources\PartnerPointTransactionResource\Pages\CreatePartnerPointTransaction;
use App\Filament\Resources\PartnerPointTransactionResource\Pages\ListPartnerPointTransactions;
use App\Filament\Resources\PartnerPointTransactionResource\Pages\ViewPartnerPointTransaction;
use App\Models\Partner;
use App\Models\PartnerPointTransaction;
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
 * Riwayat Poin Partner (ledger): hanya baca dan tambah (tidak ada ubah / hapus), daftar + pencarian + filter, entri manual
 * admin (tambah / kurangi poin: saldo partner ikut berubah dalam satu transaksi, saldo tidak boleh minus, pelaku tercatat)
 * dan validasi form. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class PartnerPointTransactionResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?array $menuAccess = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => $menuAccess, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function partner(string $name = 'Mitra Bengkel', int $points = 100): Partner
    {
        $partner = Partner::createAccount(['business_name' => $name, 'email' => uniqid() . '@example.com', 'password' => 'rahasia123']);
        $partner->update(['points_balance' => $points]);

        return $partner->fresh();
    }

    private function tx(Partner $partner, string $type = 'earn', int $points = 10, array $extra = []): PartnerPointTransaction
    {
        return PartnerPointTransaction::create(array_merge(['partner_id' => $partner->id, 'type' => $type, 'points' => $points, 'description' => 'Poin booking', 'reference_type' => 'booking'], $extra));
    }

    private function valid(Partner $partner, array $extra = []): array
    {
        return array_merge(['partner_id' => $partner->id, 'type' => 'earn', 'points' => 50, 'description' => 'Referensi sebelum app rilis'], $extra);
    }

    // ------------------------------------------------------------- akses

    public function test_access_rules(): void
    {
        $record = new PartnerPointTransaction();

        $this->as($this->user('super_admin'));
        $this->assertTrue(PartnerPointTransactionResource::canViewAny());
        $this->assertTrue(PartnerPointTransactionResource::canView($record));
        $this->assertTrue(PartnerPointTransactionResource::canCreate());
        $this->assertFalse(PartnerPointTransactionResource::canEdit($record), 'Ledger tidak bisa diubah.');
        $this->assertFalse(PartnerPointTransactionResource::canDelete($record), 'Ledger tidak bisa dihapus.');

        $this->as($this->user('kasir'));
        $this->assertTrue(PartnerPointTransactionResource::canViewAny());
        $this->assertTrue(PartnerPointTransactionResource::canView($record));
        $this->assertFalse(PartnerPointTransactionResource::canCreate(), 'Entri manual default hanya full-access.');

        $this->as($this->user('kasir', null, ['menu_permissions' => ['PartnerPointTransactionResource' => ['create']]]));
        $this->assertTrue(PartnerPointTransactionResource::canCreate());

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(PartnerPointTransactionResource::canViewAny());
        $this->assertFalse(PartnerPointTransactionResource::canView($record));
    }

    public function test_staff_without_the_create_right_cannot_open_the_create_page(): void
    {
        $this->as($this->user('kasir'));
        $this->get(PartnerPointTransactionResource::getUrl('create'))->assertForbidden();
    }

    // ------------------------------------------------------------- daftar

    public function test_list_shows_labels_and_the_actor(): void
    {
        $partner = $this->partner('Mitra Bengkel');
        $admin = $this->user('super_admin');
        $earn = $this->tx($partner, 'earn', 50, ['reference_type' => 'manual', 'description' => 'Bonus', 'created_by' => $admin->id]);
        $spend = $this->tx($partner, 'spend', 20, ['reference_type' => 'reward_redemption', 'description' => 'Tukar reward']);
        $refund = $this->tx($partner, 'earn', 20, ['reference_type' => 'reward_redemption_refund', 'description' => 'Refund']);

        $this->as($this->user('kasir'));
        Livewire::test(ListPartnerPointTransactions::class)
            ->assertCanSeeTableRecords([$earn, $spend, $refund])
            ->assertTableColumnFormattedStateSet('type', 'Dapat Poin', record: $earn)
            ->assertTableColumnFormattedStateSet('type', 'Pakai Poin', record: $spend)
            ->assertTableColumnFormattedStateSet('reference_type', 'Input Manual', record: $earn)
            ->assertTableColumnFormattedStateSet('reference_type', 'Tukar Reward', record: $spend)
            ->assertTableColumnFormattedStateSet('reference_type', 'Refund Reward', record: $refund)
            ->assertTableColumnStateSet('createdBy.name', $admin->name, record: $earn)
            ->assertTableColumnStateSet('partner.business_name', 'Mitra Bengkel', record: $earn);
    }

    public function test_search_and_filters(): void
    {
        $bengkel = $this->partner('Mitra Bengkel');
        $dealer = $this->partner('Mitra Dealer');
        $a = $this->tx($bengkel, 'earn', 10, ['reference_type' => 'booking', 'description' => 'Poin booking BKG-1']);
        $b = $this->tx($dealer, 'spend', 5, ['reference_type' => 'manual', 'description' => 'Koreksi saldo']);

        $this->as($this->user('kasir'));
        Livewire::test(ListPartnerPointTransactions::class)->searchTable('Dealer')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListPartnerPointTransactions::class)->searchTable('BKG-1')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
        Livewire::test(ListPartnerPointTransactions::class)->filterTable('type', 'spend')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListPartnerPointTransactions::class)->filterTable('reference_type', 'booking')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
    }

    public function test_the_view_page_shows_the_translated_details(): void
    {
        $partner = $this->partner('Mitra Bengkel');
        $tx = $this->tx($partner, 'earn', 75, ['reference_type' => 'manual', 'description' => 'Referensi awal']);

        $this->as($this->user('kasir'));
        Livewire::test(ViewPartnerPointTransaction::class, ['record' => $tx->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Mitra Bengkel')
            ->assertSee($partner->referral_code)
            ->assertSee('Dapat Poin')
            ->assertSee('Input Manual Admin')
            ->assertSee('Referensi awal');
    }

    // ------------------------------------------------------------- entri manual

    public function test_a_manual_earn_adds_points_and_logs_the_actor(): void
    {
        $partner = $this->partner('Mitra Bengkel', 100);

        $admin = $this->as($this->user('super_admin'));
        Livewire::test(CreatePartnerPointTransaction::class)
            ->fillForm($this->valid($partner))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(150, $partner->fresh()->points_balance);
        $tx = PartnerPointTransaction::firstOrFail();
        $this->assertSame(['earn', 50, 'manual', null, $admin->id], [$tx->type, $tx->points, $tx->reference_type, $tx->reference_id, $tx->created_by]);
        $this->assertSame('Referensi sebelum app rilis', $tx->description);
        $this->assertNotNull(Activity::where('log_name', 'partner_point_transaction')->where('subject_id', $tx->id)->first(), 'Tercatat di log aktivitas.');
    }

    public function test_a_manual_spend_subtracts_points(): void
    {
        $partner = $this->partner('Mitra Bengkel', 100);

        $this->as($this->user('super_admin'));
        Livewire::test(CreatePartnerPointTransaction::class)
            ->fillForm($this->valid($partner, ['type' => 'spend', 'points' => 40, 'description' => 'Koreksi salah input']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(60, $partner->fresh()->points_balance);
        $this->assertSame('spend', PartnerPointTransaction::firstOrFail()->type);
    }

    public function test_a_spend_larger_than_the_balance_is_refused_without_changes(): void
    {
        $partner = $this->partner('Mitra Bengkel', 30);

        $this->as($this->user('super_admin'));
        Livewire::test(CreatePartnerPointTransaction::class)
            ->fillForm($this->valid($partner, ['type' => 'spend', 'points' => 31]))
            ->call('create')
            ->assertNotified('Saldo poin tidak cukup');

        $this->assertSame(30, $partner->fresh()->points_balance);
        $this->assertSame(0, PartnerPointTransaction::count());

        Livewire::test(CreatePartnerPointTransaction::class)
            ->fillForm($this->valid($partner, ['type' => 'spend', 'points' => 30]))
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame(0, $partner->fresh()->points_balance, 'Menghabiskan seluruh saldo diperbolehkan.');
    }

    public function test_the_balance_always_matches_the_ledger_after_several_entries(): void
    {
        $partner = $this->partner('Mitra Bengkel', 0);
        $this->as($this->user('super_admin'));

        foreach ([['earn', 100], ['spend', 30], ['earn', 15], ['spend', 5]] as [$type, $points]) {
            Livewire::test(CreatePartnerPointTransaction::class)
                ->fillForm($this->valid($partner, ['type' => $type, 'points' => $points]))
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $ledger = PartnerPointTransaction::where('partner_id', $partner->id)->get()
            ->sum(fn ($t) => $t->type === 'earn' ? $t->points : -$t->points);
        $this->assertSame(80, $partner->fresh()->points_balance);
        $this->assertSame(80, $ledger);
    }

    public function test_create_validation(): void
    {
        $partner = $this->partner('Mitra Bengkel', 100);
        $this->as($this->user('super_admin'));

        Livewire::test(CreatePartnerPointTransaction::class)->fillForm($this->valid($partner, ['partner_id' => null]))->call('create')->assertHasFormErrors(['partner_id' => 'required']);
        Livewire::test(CreatePartnerPointTransaction::class)->fillForm($this->valid($partner, ['type' => 'bonus']))->call('create')->assertHasFormErrors(['type']);
        Livewire::test(CreatePartnerPointTransaction::class)->fillForm($this->valid($partner, ['points' => 0]))->call('create')->assertHasFormErrors(['points']);
        Livewire::test(CreatePartnerPointTransaction::class)->fillForm($this->valid($partner, ['points' => 10.5]))->call('create')->assertHasFormErrors(['points']);
        Livewire::test(CreatePartnerPointTransaction::class)->fillForm($this->valid($partner, ['points' => 2000000]))->call('create')->assertHasFormErrors(['points']);
        Livewire::test(CreatePartnerPointTransaction::class)->fillForm($this->valid($partner, ['description' => '']))->call('create')->assertHasFormErrors(['description' => 'required']);
        Livewire::test(CreatePartnerPointTransaction::class)->fillForm($this->valid($partner, ['description' => str_repeat('a', 256)]))->call('create')->assertHasFormErrors(['description' => 'max']);

        $this->assertSame(0, PartnerPointTransaction::count());
        $this->assertSame(100, $partner->fresh()->points_balance);
    }
}
