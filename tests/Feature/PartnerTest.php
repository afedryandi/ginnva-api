<?php

namespace Tests\Feature;

use App\Filament\Resources\PartnerResource;
use App\Filament\Resources\PartnerResource\Pages\CreatePartner;
use App\Filament\Resources\PartnerResource\Pages\EditPartner;
use App\Filament\Resources\PartnerResource\Pages\ListPartners;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\PartnerPointTransaction;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Partner: akun mitra referral — login & penonaktifan, API profil/poin/
 * referral/ganti password milik partner sendiri, dan pengelolaan di Filament
 * (buat akun, validasi, ubah profil/password, nonaktifkan, hapus hanya
 * kalau belum punya riwayat, unduh QR, izin per aksi, audit).
 */
class PartnerTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Role::findOrCreate('partner', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function partner(array $overrides = []): Partner
    {
        return Partner::createAccount(array_merge([
            'business_name' => 'Mitra ' . uniqid(), 'email' => uniqid() . '@mitra.test', 'password' => 'rahasia123',
            'phone' => '08' . random_int(100000000, 999999999),
        ], $overrides));
    }

    private function user(string $role, ?array $menuAccess = null): User
    {
        $user = User::create(['name' => ucfirst($role) . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'menu_access' => $menuAccess]);
        $user->assignRole($role);

        return $user;
    }

    private function asAdmin(): User
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');

        return $admin;
    }

    private function asPartner(Partner $partner): self
    {
        return $this->actingAs($partner->user, 'api');
    }

    private function referredBooking(Partner $partner, array $overrides = []): Booking
    {
        $customer = Customer::create(['name' => 'Budi Pelanggan', 'phone_number' => '0812' . random_int(1000000, 9999999)]);

        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(), 'customer_id' => $customer->id, 'store_id' => $this->store->id,
            'service_type' => 'Pelindung Cat (PPF)', 'product_ppf' => true, 'preferred_date' => now()->toDateString(),
            'status' => 'completed', 'partner_id' => $partner->id, 'transaction_amount' => 5000000,
        ], $overrides));
    }

    private function points(Partner $partner, int $points, string $type = 'earn', ?string $reference = null, ?int $referenceId = null): PartnerPointTransaction
    {
        return PartnerPointTransaction::create([
            'partner_id' => $partner->id, 'type' => $type, 'points' => $points, 'description' => 'Tes poin',
            'reference_type' => $reference, 'reference_id' => $referenceId,
        ]);
    }

    // ------------------------------------------------------------- login

    public function test_active_partner_can_login_but_a_deactivated_one_cannot(): void
    {
        $partner = $this->partner(['email' => 'aktif@mitra.test', 'password' => 'rahasia123']);

        $this->postJson('/api/staff/auth/login', ['email' => 'aktif@mitra.test', 'password' => 'rahasia123'])
            ->assertSuccessful()->assertJsonPath('success', true);
        $this->postJson('/api/staff/auth/login', ['email' => 'aktif@mitra.test', 'password' => 'salah'])->assertStatus(401);

        $partner->update(['status' => 'inactive']);
        $this->postJson('/api/staff/auth/login', ['email' => 'aktif@mitra.test', 'password' => 'rahasia123'])
            ->assertStatus(403)->assertJsonPath('success', false);
    }

    // ------------------------------------------------------------- API partner

    public function test_partner_endpoints_require_login(): void
    {
        foreach (['me', 'points', 'redemptions', 'referrals'] as $path) {
            $this->getJson("/api/partner/{$path}")->assertStatus(401);
        }
    }

    public function test_a_non_partner_account_is_forbidden(): void
    {
        $staff = $this->user('kasir');

        $this->actingAs($staff, 'api')->getJson('/api/partner/me')->assertStatus(403);
    }

    public function test_a_token_issued_before_deactivation_stops_working(): void
    {
        $partner = $this->partner();
        $this->asPartner($partner)->getJson('/api/partner/me')->assertSuccessful();

        $partner->update(['status' => 'inactive']);

        $this->asPartner($partner->fresh())->getJson('/api/partner/me')->assertStatus(403);
        $this->asPartner($partner->fresh())->getJson('/api/partner/points')->assertStatus(403);
    }

    public function test_me_returns_own_profile_with_referral_code_and_balance(): void
    {
        $partner = $this->partner(['business_name' => 'Auto Mitra', 'phone' => '081200001111']);
        $partner->update(['points_balance' => 250]);

        $this->asPartner($partner)->getJson('/api/partner/me')->assertSuccessful()
            ->assertJsonPath('data.id', $partner->id)
            ->assertJsonPath('data.business_name', 'Auto Mitra')
            ->assertJsonPath('data.referral_code', $partner->referral_code)
            ->assertJsonPath('data.points_balance', 250)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_points_history_is_only_my_own_newest_first_and_paginated(): void
    {
        $mine = $this->partner();
        $other = $this->partner();
        $this->points($other, 999);
        foreach (range(1, 52) as $n) {
            $row = $this->points($mine, $n);
            $row->forceFill(['created_at' => now()->subMinutes(60 - $n)])->save();
        }

        $page1 = $this->asPartner($mine)->getJson('/api/partner/points')->assertSuccessful();
        $this->assertCount(50, $page1->json('transactions'));
        $this->assertTrue($page1->json('has_more'));
        $this->assertSame(52, $page1->json('total'));
        $this->assertSame(52, $page1->json('transactions.0.points'), 'Terbaru di atas.');
        $this->assertNotContains(999, collect($page1->json('transactions'))->pluck('points')->all());

        $page2 = $this->asPartner($mine)->getJson('/api/partner/points?page=2')->assertSuccessful();
        $this->assertCount(2, $page2->json('transactions'));
        $this->assertFalse($page2->json('has_more'));
    }

    public function test_referrals_list_shows_my_bookings_with_customer_name_and_points_from_the_ledger(): void
    {
        $mine = $this->partner();
        $other = $this->partner();
        $booking = $this->referredBooking($mine);
        $this->referredBooking($other);
        $this->points($mine, 50, 'earn', 'booking', $booking->id);

        $data = $this->asPartner($mine)->getJson('/api/partner/referrals')->assertSuccessful()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($booking->id, $data[0]['id']);
        $this->assertSame('Budi Pelanggan', $data[0]['customer_name'], 'Nama customer tampil walau booking dibuat lewat aplikasi (kolom customer_name kosong).');
        $this->assertSame(50, $data[0]['points_earned']);
        $this->assertSame(5000000.0, $data[0]['transaction_amount']);
    }

    public function test_referral_of_a_deleted_customer_shows_a_generic_name(): void
    {
        $partner = $this->partner();
        $booking = $this->referredBooking($partner);
        $booking->customer->delete();

        $this->asPartner($partner)->getJson('/api/partner/referrals')->assertSuccessful()
            ->assertJsonPath('data.0.customer_name', 'Pelanggan Terhapus');
    }

    public function test_profile_update_changes_name_and_phone_but_not_code_or_balance(): void
    {
        $partner = $this->partner(['phone' => '081200001111']);
        $code = $partner->referral_code;

        $this->asPartner($partner)->putJson('/api/partner/profile', [
            'business_name' => 'Nama Baru', 'phone' => '081200002222', 'referral_code' => 'HACKED01', 'points_balance' => 99999,
        ])->assertSuccessful()->assertJsonPath('data.business_name', 'Nama Baru');

        $fresh = $partner->fresh();
        $this->assertSame('081200002222', $fresh->phone);
        $this->assertSame($code, $fresh->referral_code);
        $this->assertSame(0, $fresh->points_balance);
    }

    public function test_profile_update_validation_and_unique_phone(): void
    {
        $partner = $this->partner();
        $other = $this->partner(['phone' => '081299990000']);

        $this->asPartner($partner)->putJson('/api/partner/profile', ['business_name' => '', 'phone' => '0811'])->assertStatus(422);
        $this->asPartner($partner)->putJson('/api/partner/profile', ['business_name' => 'X', 'phone' => '081299990000'])
            ->assertStatus(422)->assertJsonPath('errors.phone.0', 'Nomor telepon ini sudah terdaftar di akun partner lain.');
        $this->asPartner($partner)->putJson('/api/partner/profile', ['business_name' => 'X', 'phone' => $partner->phone])->assertSuccessful();
    }

    public function test_change_password_requires_the_current_password_and_confirmation(): void
    {
        $partner = $this->partner(['password' => 'rahasia123']);

        $this->asPartner($partner)->postJson('/api/partner/change-password', [
            'current_password' => 'salah', 'password' => 'passwordbaru1', 'password_confirmation' => 'passwordbaru1',
        ])->assertStatus(422)->assertJsonPath('message', 'Password lama yang Anda masukkan salah.');

        $this->asPartner($partner)->postJson('/api/partner/change-password', [
            'current_password' => 'rahasia123', 'password' => 'pendek', 'password_confirmation' => 'pendek',
        ])->assertStatus(422);

        $this->asPartner($partner)->postJson('/api/partner/change-password', [
            'current_password' => 'rahasia123', 'password' => 'passwordbaru1', 'password_confirmation' => 'beda',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('rahasia123', $partner->user->fresh()->password));

        $this->asPartner($partner)->postJson('/api/partner/change-password', [
            'current_password' => 'rahasia123', 'password' => 'passwordbaru1', 'password_confirmation' => 'passwordbaru1',
        ])->assertSuccessful();

        $this->assertTrue(Hash::check('passwordbaru1', $partner->user->fresh()->password));
    }

    // ------------------------------------------------------------- Filament

    public function test_filament_list_renders_and_filters(): void
    {
        $this->asAdmin();
        $active = $this->partner(['source' => 'giias']);
        $inactive = $this->partner(['status' => 'inactive']);
        $influencer = $this->partner(['type' => 'influencer', 'source' => 'partner']);

        Livewire::test(ListPartners::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$active, $inactive, $influencer])
            ->filterTable('status', 'inactive')
            ->assertCanSeeTableRecords([$inactive])->assertCanNotSeeTableRecords([$active, $influencer]);

        Livewire::test(ListPartners::class)->filterTable('type', 'influencer')
            ->assertCanSeeTableRecords([$influencer])->assertCanNotSeeTableRecords([$active, $inactive]);

        Livewire::test(ListPartners::class)->filterTable('source', 'giias')
            ->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$inactive, $influencer]);
    }

    public function test_admin_creates_a_partner_with_login_account_and_referral_code(): void
    {
        $this->asAdmin();

        Livewire::test(CreatePartner::class)
            ->fillForm([
                'email' => 'baru@mitra.test', 'password' => 'rahasia123', 'passwordConfirmation' => 'rahasia123',
                'business_name' => 'Komunitas Mobil', 'phone' => '081377778888', 'status' => 'active', 'type' => 'komunitas',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $partner = Partner::where('business_name', 'Komunitas Mobil')->firstOrFail();
        $this->assertSame('komunitas', $partner->type);
        $this->assertNull($partner->source, 'Dibuat manual.');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $partner->referral_code);
        $this->assertTrue($partner->user->hasRole('partner'));
        $this->assertTrue(Hash::check('rahasia123', $partner->user->password));

        $this->postJson('/api/staff/auth/login', ['email' => 'baru@mitra.test', 'password' => 'rahasia123'])->assertSuccessful();
    }

    public function test_create_validation_email_unique_password_length_and_confirmation(): void
    {
        $this->asAdmin();
        $existing = $this->partner(['email' => 'dipakai@mitra.test']);
        $base = ['business_name' => 'X', 'status' => 'active', 'type' => 'partner'];

        Livewire::test(CreatePartner::class)
            ->fillForm([...$base, 'email' => '', 'password' => '', 'passwordConfirmation' => '', 'business_name' => ''])
            ->call('create')
            ->assertHasFormErrors(['email' => 'required', 'password' => 'required', 'passwordConfirmation' => 'required', 'business_name' => 'required']);

        Livewire::test(CreatePartner::class)
            ->fillForm([...$base, 'email' => 'dipakai@mitra.test', 'password' => 'rahasia123', 'passwordConfirmation' => 'rahasia123'])
            ->call('create')
            ->assertHasFormErrors(['email' => 'unique']);

        Livewire::test(CreatePartner::class)
            ->fillForm([...$base, 'email' => 'a@mitra.test', 'password' => 'pendek', 'passwordConfirmation' => 'pendek'])
            ->call('create')
            ->assertHasFormErrors(['password' => 'min']);

        Livewire::test(CreatePartner::class)
            ->fillForm([...$base, 'email' => 'a@mitra.test', 'password' => 'rahasia123', 'passwordConfirmation' => 'beda12345'])
            ->call('create')
            ->assertHasFormErrors(['password' => 'same']);

        $this->assertSame(1, Partner::count());
        $this->assertNotNull($existing);
    }

    public function test_edit_updates_profile_and_account_name_keeps_email_and_password_when_blank(): void
    {
        $this->asAdmin();
        $partner = $this->partner(['email' => 'tetap@mitra.test', 'password' => 'rahasia123']);

        Livewire::test(EditPartner::class, ['record' => $partner->getKey()])
            ->assertFormSet(['email' => 'tetap@mitra.test'])
            ->fillForm(['business_name' => 'Nama Diganti', 'phone' => '081355550000', 'type' => 'influencer', 'password' => '', 'passwordConfirmation' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $partner->fresh()->load('user');
        $this->assertSame('Nama Diganti', $fresh->business_name);
        $this->assertSame('Nama Diganti', $fresh->user->name);
        $this->assertSame('influencer', $fresh->type);
        $this->assertSame('tetap@mitra.test', $fresh->user->email);
        $this->assertTrue(Hash::check('rahasia123', $fresh->user->password), 'Password lama tidak berubah kalau dikosongkan.');
        $this->assertTrue(Activity::where('log_name', 'partner')->where('subject_id', $partner->id)->where('event', 'updated')->exists());
    }

    public function test_edit_can_reset_the_password(): void
    {
        $this->asAdmin();
        $partner = $this->partner(['password' => 'rahasia123']);

        Livewire::test(EditPartner::class, ['record' => $partner->getKey()])
            ->fillForm(['password' => 'baruaman123', 'passwordConfirmation' => 'baruaman123'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('baruaman123', $partner->user->fresh()->password));
    }

    public function test_deactivating_from_the_edit_form_blocks_login(): void
    {
        $this->asAdmin();
        $partner = $this->partner(['email' => 'nonaktif@mitra.test', 'password' => 'rahasia123']);

        Livewire::test(EditPartner::class, ['record' => $partner->getKey()])
            ->fillForm(['status' => 'inactive'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->postJson('/api/staff/auth/login', ['email' => 'nonaktif@mitra.test', 'password' => 'rahasia123'])->assertStatus(403);
    }

    public function test_hard_delete_is_closed_once_the_partner_has_history(): void
    {
        $admin = $this->asAdmin();
        $clean = $this->partner();
        $withPoints = $this->partner();
        $this->points($withPoints, 10);
        $withBooking = $this->partner();
        $this->referredBooking($withBooking);

        $this->assertTrue((bool) PartnerResource::canDelete($clean));
        $this->assertFalse((bool) PartnerResource::canDelete($withPoints), 'Riwayat poin akan musnah kalau dihapus.');
        $this->assertFalse((bool) PartnerResource::canDelete($withBooking), 'Atribusi referral booking akan hilang.');
        $this->assertTrue($withPoints->hasHistory());
        $this->assertFalse($clean->hasHistory());
        $this->assertNotNull($admin);
    }

    public function test_qr_download_returns_a_pdf_named_after_the_referral_code(): void
    {
        $this->asAdmin();
        $partner = $this->partner(['source' => 'giias']);

        Livewire::test(ListPartners::class)
            ->callTableAction('download_qr', $partner)
            ->assertFileDownloaded("QR-Referral-{$partner->referral_code}.pdf");
    }

    public function test_permissions_per_action(): void
    {
        $partner = $this->partner();
        $withMenu = $this->user('kasir');
        $noMenu = $this->user('kasir', ['SomeOtherResource']);

        $this->actingAs($withMenu, 'web');
        $this->assertTrue(PartnerResource::canViewAny());
        $this->assertTrue((bool) PartnerResource::canEdit($partner), 'Mengubah data partner = tugas rutin staf.');
        $this->assertFalse((bool) PartnerResource::canCreate(), 'Membuat akun login baru hanya full-access / izin eksplisit.');
        $this->assertFalse((bool) PartnerResource::canDelete($partner));

        $this->actingAs($noMenu, 'web');
        $this->assertFalse((bool) PartnerResource::canViewAny());
        $this->assertFalse((bool) PartnerResource::canEdit($partner));

        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue((bool) PartnerResource::canCreate());
    }
}
