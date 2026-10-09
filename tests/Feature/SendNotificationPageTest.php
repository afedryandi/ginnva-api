<?php

namespace Tests\Feature;

use App\Filament\Pages\SendNotification;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\DeviceToken;
use App\Models\Partner;
use App\Models\PartnerNotification;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Kirim Notifikasi (halaman admin): akses lewat kotak menu, kirim ke semua pelanggan (broadcast) atau pelanggan terpilih,
 * ke semua partner atau partner terpilih, hanya ke token milik kelompok penerimanya (tidak menyasar staf / kelompok lain),
 * riwayat tersimpan dengan pengirimnya, deep link (hanya rute sah, ID hanya untuk rute yang memerlukannya), token mati
 * dibersihkan, hasil terkirim/gagal dilaporkan, dan validasi form.
 */
class SendNotificationPageTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->fakeExpo();
    }

    /** Expo membalas "ok" untuk setiap token; token yang diawali "dead" dibalas DeviceNotRegistered. */
    private function fakeExpo(): void
    {
        Http::fake(['exp.host/*' => function (Request $request) {
            $results = collect($request->data())->map(fn ($message) => str_starts_with($message['to'], 'dead')
                ? ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]
                : ['status' => 'ok'])->all();

            return Http::response(['data' => $results]);
        }]);
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

    private function customer(string $name, ?string $token = null): Customer
    {
        $customer = Customer::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '@test.local', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        if ($token) {
            DeviceToken::create(['customer_id' => $customer->id, 'token' => $token, 'platform' => 'android']);
        }

        return $customer;
    }

    private function partner(string $name, ?string $token = null): Partner
    {
        $partner = Partner::createAccount(['business_name' => $name, 'email' => uniqid() . '@example.com', 'password' => 'rahasia123']);

        if ($token) {
            DeviceToken::create(['user_id' => $partner->user_id, 'token' => $token, 'platform' => 'android']);
        }

        return $partner;
    }

    private function sentTokens(): array
    {
        $tokens = [];
        Http::assertSent(function (Request $request) use (&$tokens) {
            if (str_contains($request->url(), 'exp.host')) {
                $tokens = array_merge($tokens, collect($request->data())->pluck('to')->all());
            }

            return true;
        });
        sort($tokens);

        return $tokens;
    }

    // ------------------------------------------------------------- akses

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->as($this->user('super_admin'));
        $this->assertTrue(SendNotification::canAccess());

        $this->as($this->user('kasir', ['SendNotification']));
        $this->assertTrue(SendNotification::canAccess());

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(SendNotification::canAccess());
    }

    public function test_the_form_starts_as_a_customer_broadcast(): void
    {
        $this->as($this->user('super_admin'));

        Livewire::test(SendNotification::class)
            ->assertSuccessful()
            ->assertFormSet(['audience' => 'customer', 'broadcast' => true]);
    }

    // ------------------------------------------------------------- pelanggan

    public function test_a_broadcast_reaches_only_customer_tokens_and_keeps_one_history_row(): void
    {
        $this->customer('Budi Santoso', 'ExponentPushToken[cust-1]');
        $this->customer('Siti Aminah', 'ExponentPushToken[cust-2]');
        $staff = $this->user('kasir');
        DeviceToken::create(['user_id' => $staff->id, 'token' => 'ExponentPushToken[staff-1]', 'platform' => 'android']);
        $partner = $this->partner('Mitra Bengkel', 'ExponentPushToken[partner-1]');
        $admin = $this->as($this->user('super_admin'));

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'title' => 'Promo Spesial', 'body' => 'Diskon 20% PPF bulan ini', 'broadcast' => true])
            ->call('send')
            ->assertNotified('Terkirim: 2 perangkat');

        $this->assertSame(['ExponentPushToken[cust-1]', 'ExponentPushToken[cust-2]'], $this->sentTokens(), 'Token staf dan partner tidak ikut.');
        $row = CustomerNotification::firstOrFail();
        $this->assertNull($row->customer_id, 'Broadcast = satu baris untuk semua.');
        $this->assertSame(['Promo Spesial', 'Diskon 20% PPF bulan ini', $admin->id], [$row->title, $row->body, $row->sent_by]);
        $this->assertSame(1, CustomerNotification::count());
    }

    public function test_a_targeted_send_reaches_only_the_chosen_customers(): void
    {
        $a = $this->customer('Budi Santoso', 'ExponentPushToken[a]');
        $b = $this->customer('Siti Aminah', 'ExponentPushToken[b]');
        $c = $this->customer('Tidak Dipilih', 'ExponentPushToken[c]');
        $this->as($this->user('super_admin'));

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'title' => 'Halo', 'body' => 'Pesan pribadi', 'broadcast' => false, 'customer_ids' => [$a->id, $b->id]])
            ->call('send')
            ->assertNotified('Terkirim: 2 perangkat');

        $this->assertSame(['ExponentPushToken[a]', 'ExponentPushToken[b]'], $this->sentTokens());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], CustomerNotification::pluck('customer_id')->all());
        $this->assertSame(0, CustomerNotification::where('customer_id', $c->id)->count());
    }

    public function test_without_any_token_the_history_is_still_saved(): void
    {
        $this->customer('Budi Santoso');
        $this->as($this->user('super_admin'));

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'title' => 'Halo', 'body' => 'Tanpa perangkat', 'broadcast' => true])
            ->call('send')
            ->assertNotified('Terkirim: 0 perangkat');

        $this->assertSame(1, CustomerNotification::count());
        Http::assertNothingSent();
    }

    public function test_dead_tokens_are_reported_as_failed_and_removed(): void
    {
        $this->customer('Budi Santoso', 'ExponentPushToken[ok-1]');
        $this->customer('Siti Aminah', 'dead-token-1');
        $this->as($this->user('super_admin'));

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'title' => 'Halo', 'body' => 'Uji', 'broadcast' => true])
            ->call('send')
            ->assertNotified('Terkirim: 1 perangkat, gagal: 1');

        $this->assertSame(0, DeviceToken::where('token', 'dead-token-1')->count(), 'Token tidak terdaftar dibersihkan.');
        $this->assertSame(1, DeviceToken::where('token', 'ExponentPushToken[ok-1]')->count());
    }

    public function test_the_form_resets_after_a_successful_send(): void
    {
        $this->as($this->user('super_admin'));

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'title' => 'Halo', 'body' => 'Uji', 'broadcast' => true])
            ->call('send')
            ->assertFormSet(['audience' => 'customer', 'broadcast' => true, 'title' => null]);
    }

    // ------------------------------------------------------------- deep link

    public function test_a_deep_link_carries_the_route_and_only_the_id_it_needs(): void
    {
        $this->customer('Budi Santoso', 'ExponentPushToken[a]');
        $this->as($this->user('super_admin'));

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'title' => 'Booking Anda', 'body' => 'Cek status', 'broadcast' => true, 'deep_link_route' => '/account/my-bookings', 'deep_link_param_id' => 42])
            ->call('send');
        $this->assertSame(['route' => '/account/my-bookings', 'params' => ['id' => '42']], CustomerNotification::latest('id')->firstOrFail()->data);

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'title' => 'Berita', 'body' => 'Baca', 'broadcast' => true, 'deep_link_route' => '/news', 'deep_link_param_id' => 99])
            ->call('send');
        $this->assertSame(['route' => '/news'], CustomerNotification::latest('id')->firstOrFail()->data, 'ID basi tidak ikut untuk rute yang tidak memerlukannya.');

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'title' => 'Tanpa Link', 'body' => 'Biasa', 'broadcast' => true])
            ->call('send');
        $this->assertEmpty(CustomerNotification::latest('id')->firstOrFail()->data);
    }

    public function test_an_unknown_deep_link_route_is_rejected(): void
    {
        $this->as($this->user('super_admin'));

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'title' => 'Halo', 'body' => 'Uji', 'broadcast' => true, 'deep_link_route' => '/admin/rahasia'])
            ->call('send')
            ->assertHasFormErrors(['deep_link_route']);

        $this->assertSame(0, CustomerNotification::count());
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------- partner

    public function test_a_partner_broadcast_reaches_only_partner_tokens(): void
    {
        $this->customer('Budi Santoso', 'ExponentPushToken[cust-1]');
        $this->partner('Mitra Bengkel', 'ExponentPushToken[partner-1]');
        $this->partner('Mitra Dealer', 'ExponentPushToken[partner-2]');
        $admin = $this->as($this->user('super_admin'));

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'partner', 'title' => 'Info Mitra', 'body' => 'Program baru', 'broadcast_partner' => true])
            ->call('send')
            ->assertNotified('Terkirim: 2 perangkat');

        $this->assertSame(['ExponentPushToken[partner-1]', 'ExponentPushToken[partner-2]'], $this->sentTokens());
        $row = PartnerNotification::firstOrFail();
        $this->assertNull($row->partner_id);
        $this->assertSame([$admin->id, 'Info Mitra'], [$row->sent_by, $row->title]);
        $this->assertSame(0, CustomerNotification::count());
    }

    public function test_a_targeted_partner_send_reaches_only_the_chosen_partner(): void
    {
        $bengkel = $this->partner('Mitra Bengkel', 'ExponentPushToken[partner-1]');
        $this->partner('Mitra Dealer', 'ExponentPushToken[partner-2]');
        $this->as($this->user('super_admin'));

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'partner', 'title' => 'Halo', 'body' => 'Khusus Anda', 'broadcast_partner' => false, 'partner_ids' => [$bengkel->id]])
            ->call('send')
            ->assertNotified('Terkirim: 1 perangkat');

        $this->assertSame(['ExponentPushToken[partner-1]'], $this->sentTokens());
        $this->assertSame([$bengkel->id], PartnerNotification::pluck('partner_id')->all());
    }

    // ------------------------------------------------------------- validasi

    public function test_form_validation(): void
    {
        $this->as($this->user('super_admin'));
        $valid = ['audience' => 'customer', 'title' => 'Halo', 'body' => 'Uji', 'broadcast' => true];

        Livewire::test(SendNotification::class)->fillForm(array_merge($valid, ['title' => '']))->call('send')->assertHasFormErrors(['title' => 'required']);
        Livewire::test(SendNotification::class)->fillForm(array_merge($valid, ['title' => str_repeat('a', 201)]))->call('send')->assertHasFormErrors(['title' => 'max']);
        Livewire::test(SendNotification::class)->fillForm(array_merge($valid, ['body' => '']))->call('send')->assertHasFormErrors(['body' => 'required']);
        Livewire::test(SendNotification::class)->fillForm(array_merge($valid, ['body' => str_repeat('a', 501)]))->call('send')->assertHasFormErrors(['body' => 'max']);
        Livewire::test(SendNotification::class)->fillForm(array_merge($valid, ['broadcast' => false, 'customer_ids' => []]))->call('send')->assertHasFormErrors(['customer_ids']);
        Livewire::test(SendNotification::class)->fillForm(['audience' => 'partner', 'title' => 'Halo', 'body' => 'Uji', 'broadcast_partner' => false, 'partner_ids' => []])->call('send')->assertHasFormErrors(['partner_ids']);

        $this->assertSame(0, CustomerNotification::count() + PartnerNotification::count());
        Http::assertNothingSent();
    }

    public function test_a_deleted_customer_cannot_be_targeted(): void
    {
        $gone = $this->customer('Akan Dihapus', 'ExponentPushToken[gone]');
        $gone->update(['name' => null]);
        $gone->delete();
        $this->as($this->user('super_admin'));

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'title' => 'Halo', 'body' => 'Uji', 'broadcast' => false, 'customer_ids' => [$gone->id]])
            ->call('send');

        $this->assertSame(0, CustomerNotification::count(), 'Tidak ada riwayat untuk akun terhapus.');
    }
}
