<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerNotificationResource;
use App\Filament\Resources\CustomerNotificationResource\Pages\ListCustomerNotifications;
use App\Filament\Resources\PartnerNotificationResource;
use App\Filament\Resources\PartnerNotificationResource\Pages\ListPartnerNotifications;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Partner;
use App\Models\PartnerNotification;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Riwayat Notifikasi (pelanggan dan partner): hanya baca, akses lewat kotak menu, daftar dengan target (broadcast / nama /
 * akun terhapus), deep link, pengirim, pencarian + filter + rentang tanggal, detail di modal, dan relasi target dimuat
 * sekaligus (tanpa satu query per baris).
 */
class NotificationHistoryResourcesTest extends TestCase
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

    private function user(string $role, ?array $menuAccess = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => $menuAccess, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function customer(string $name): Customer
    {
        return Customer::create(['name' => $name, 'phone_number' => '0812' . random_int(10000000, 99999999)]);
    }

    private function partner(string $name): Partner
    {
        return Partner::createAccount(['business_name' => $name, 'email' => uniqid() . '@example.com', 'password' => 'rahasia123']);
    }

    private function cn(array $extra = []): CustomerNotification
    {
        return CustomerNotification::create(array_merge(['customer_id' => null, 'title' => 'Promo', 'body' => 'Diskon 20%'], $extra));
    }

    private function pn(array $extra = []): PartnerNotification
    {
        return PartnerNotification::create(array_merge(['partner_id' => null, 'title' => 'Info Mitra', 'body' => 'Program baru'], $extra));
    }

    private function dated($table, int $id, string $at): void
    {
        DB::table($table)->where('id', $id)->update(['created_at' => $at, 'updated_at' => $at]);
    }

    // ------------------------------------------------------------- akses

    public function test_access_is_read_only_and_follows_the_menu_checkbox(): void
    {
        $record = new CustomerNotification();
        $partnerRecord = new PartnerNotification();

        $this->as($this->user('super_admin'));
        foreach ([[CustomerNotificationResource::class, $record], [PartnerNotificationResource::class, $partnerRecord]] as [$resource, $model]) {
            $this->assertTrue($resource::canViewAny());
            $this->assertTrue($resource::canView($model));
            $this->assertFalse($resource::canCreate());
            $this->assertFalse($resource::canEdit($model));
            $this->assertFalse($resource::canDelete($model));
        }

        $this->as($this->user('kasir'));
        $this->assertTrue(CustomerNotificationResource::canViewAny());
        $this->assertTrue(PartnerNotificationResource::canViewAny());

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(CustomerNotificationResource::canViewAny());
        $this->assertFalse(PartnerNotificationResource::canViewAny());
        $this->assertFalse(CustomerNotificationResource::canView($record));
        $this->assertFalse(PartnerNotificationResource::canView($partnerRecord));
    }

    // ------------------------------------------------------------- pelanggan

    public function test_the_customer_history_shows_targets_deep_links_and_the_sender(): void
    {
        $admin = $this->user('super_admin');
        $budi = $this->customer('Budi Santoso');
        $gone = $this->customer('Akan Dihapus');
        $broadcast = $this->cn(['title' => 'Promo Besar', 'data' => ['route' => '/news'], 'sent_by' => $admin->id]);
        $targeted = $this->cn(['customer_id' => $budi->id, 'title' => 'Halo Budi']);
        $ofGone = $this->cn(['customer_id' => $gone->id, 'title' => 'Untuk Akun Lama']);
        $gone->update(['name' => null]);
        $gone->delete();

        $this->as($this->user('kasir'));
        $query = CustomerNotificationResource::getEloquentQuery();
        $broadcast = $query->clone()->findOrFail($broadcast->id);
        $targeted = $query->clone()->findOrFail($targeted->id);
        $ofGone = $query->clone()->findOrFail($ofGone->id);

        Livewire::test(ListCustomerNotifications::class)
            ->assertCanSeeTableRecords([$broadcast, $targeted, $ofGone])
            ->assertTableColumnStateSet('target', 'Broadcast (semua user)', record: $broadcast)
            ->assertTableColumnStateSet('target', 'Budi Santoso', record: $targeted)
            ->assertTableColumnStateSet('target', '(Akun Dihapus)', record: $ofGone)
            ->assertTableColumnStateSet('data', '/news', record: $broadcast)
            ->assertTableColumnStateSet('data', '-', record: $targeted)
            ->assertTableColumnStateSet('sentBy.name', $admin->name, record: $broadcast);
    }

    public function test_the_target_relations_are_loaded_in_one_go(): void
    {
        foreach (range(1, 3) as $i) {
            $this->cn(['customer_id' => $this->customer("Pelanggan {$i}")->id]);
            $this->pn(['partner_id' => $this->partner("Mitra {$i}")->id]);
        }
        $this->as($this->user('kasir'));

        $this->assertTrue(CustomerNotificationResource::getEloquentQuery()->get()->every(fn ($n) => $n->relationLoaded('customer') && $n->relationLoaded('sentBy')));
        $this->assertTrue(PartnerNotificationResource::getEloquentQuery()->get()->every(fn ($n) => $n->relationLoaded('partner') && $n->relationLoaded('sentBy')));
    }

    public function test_the_customer_history_search_filters_and_date_range(): void
    {
        $budi = $this->customer('Budi Santoso');
        $a = $this->cn(['title' => 'Promo Ramadan', 'created_at' => '2026-10-01 09:00:00']);
        $b = $this->cn(['customer_id' => $budi->id, 'title' => 'Servis Anda', 'body' => 'Mobil siap']);
        $c = $this->cn(['title' => 'Berita Baru']);
        $this->dated('customer_notifications', $a->id, '2026-10-01 09:00:00');
        $this->dated('customer_notifications', $b->id, '2026-10-05 09:00:00');
        $this->dated('customer_notifications', $c->id, '2026-10-07 09:00:00');

        $this->as($this->user('kasir'));
        Livewire::test(ListCustomerNotifications::class)->searchTable('Ramadan')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c]);
        Livewire::test(ListCustomerNotifications::class)->filterTable('broadcast')->assertCanSeeTableRecords([$a, $c])->assertCanNotSeeTableRecords([$b]);
        Livewire::test(ListCustomerNotifications::class)->filterTable('targeted')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c]);
        Livewire::test(ListCustomerNotifications::class)
            ->filterTable('created_at', ['from' => '2026-10-02', 'until' => '2026-10-06'])
            ->assertCanSeeTableRecords([$b])
            ->assertCanNotSeeTableRecords([$a, $c]);
    }

    public function test_the_customer_detail_modal_shows_title_body_and_data(): void
    {
        $record = $this->cn(['title' => 'Promo Besar', 'body' => 'Diskon 20% PPF', 'data' => ['route' => '/news']]);

        $this->as($this->user('kasir'));
        Livewire::test(ListCustomerNotifications::class)
            ->mountTableAction('view', $record)
            ->assertTableActionDataSet(['title' => 'Promo Besar', 'body' => 'Diskon 20% PPF']);
    }

    // ------------------------------------------------------------- partner

    public function test_the_partner_history_shows_targets_and_the_sender(): void
    {
        $admin = $this->user('super_admin');
        $mitra = $this->partner('Mitra Bengkel');
        $broadcast = $this->pn(['title' => 'Program Baru', 'sent_by' => $admin->id]);
        $targeted = $this->pn(['partner_id' => $mitra->id, 'title' => 'Khusus Anda']);

        $this->as($this->user('kasir'));
        $query = PartnerNotificationResource::getEloquentQuery();
        $broadcast = $query->clone()->findOrFail($broadcast->id);
        $targeted = $query->clone()->findOrFail($targeted->id);

        Livewire::test(ListPartnerNotifications::class)
            ->assertCanSeeTableRecords([$broadcast, $targeted])
            ->assertTableColumnStateSet('target', 'Broadcast (semua partner)', record: $broadcast)
            ->assertTableColumnStateSet('target', 'Mitra Bengkel', record: $targeted)
            ->assertTableColumnStateSet('sentBy.name', $admin->name, record: $broadcast);
    }

    public function test_the_partner_history_search_filters_and_date_range(): void
    {
        $mitra = $this->partner('Mitra Bengkel');
        $a = $this->pn(['title' => 'Program Lama']);
        $b = $this->pn(['partner_id' => $mitra->id, 'title' => 'Insentif Anda']);
        $this->dated('partner_notifications', $a->id, '2026-10-01 09:00:00');
        $this->dated('partner_notifications', $b->id, '2026-10-05 09:00:00');

        $this->as($this->user('kasir'));
        Livewire::test(ListPartnerNotifications::class)->searchTable('Insentif')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListPartnerNotifications::class)->filterTable('broadcast')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
        Livewire::test(ListPartnerNotifications::class)->filterTable('targeted')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListPartnerNotifications::class)
            ->filterTable('created_at', ['from' => '2026-10-04', 'until' => '2026-10-06'])
            ->assertCanSeeTableRecords([$b])
            ->assertCanNotSeeTableRecords([$a]);
    }
}
