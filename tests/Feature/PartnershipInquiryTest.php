<?php

namespace Tests\Feature;

use App\Filament\Resources\PartnershipInquiryResource;
use App\Filament\Resources\PartnershipInquiryResource\Pages\EditPartnershipInquiry;
use App\Filament\Resources\PartnershipInquiryResource\Pages\ListPartnershipInquiries;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\PartnershipInquiry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Kemitraan & Sales Referral: pengajuan franchise (form publik, customer
 * opsional, notifikasi), pendaftaran partner real-time dari /giias dan
 * /partner (akun + kode referral, idempoten per nomor WA, email opsional,
 * lookup kode), serta tindak lanjut & konversi "Jadikan Partner" di Filament.
 */
class PartnershipInquiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Role::findOrCreate('partner', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function franchise(array $overrides = []): PartnershipInquiry
    {
        return PartnershipInquiry::create(array_merge([
            'category' => 'franchise', 'applicant_name' => 'Rina', 'phone_number' => '081234567890',
            'email' => 'rina@example.com', 'city' => 'Bandung', 'message' => 'Tertarik buka cabang', 'status' => 'new',
        ], $overrides));
    }

    private function user(string $role, ?array $menuAccess = null, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x',
            'menu_access' => $menuAccess,
        ], $extra));
        $user->assignRole($role);

        return $user;
    }

    private function asAdmin(): User
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');

        return $admin;
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function signupFlows(): array
    {
        return [
            'giias'   => ['/api/giias/partner-signup', 'giias', '/giias?ref='],
            'partner' => ['/api/partner-signup', 'partner', '/partner?ref='],
        ];
    }

    // ------------------------------------------------------------- pengajuan franchise

    public function test_guest_franchise_application_is_stored_as_new(): void
    {
        $this->postJson('/api/partnership/submit', [
            'applicant_name' => 'Rina', 'phone_number' => '081234567890', 'email' => 'rina@example.com',
            'city' => 'Bandung', 'message' => 'Tertarik buka cabang',
        ])->assertStatus(201)->assertJsonPath('success', true);

        $inquiry = PartnershipInquiry::firstOrFail();
        $this->assertSame('franchise', $inquiry->category);
        $this->assertSame('new', $inquiry->status);
        $this->assertNull($inquiry->customer_id);
    }

    public function test_customer_token_links_the_application_to_the_customer(): void
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);

        $this->withHeader('Authorization', 'Bearer ' . JWTAuth::fromUser($customer))
            ->postJson('/api/partnership/submit', ['applicant_name' => 'Budi', 'phone_number' => '0811', 'email' => 'b@example.com', 'city' => 'Jakarta'])
            ->assertStatus(201);

        $this->assertSame($customer->id, PartnershipInquiry::firstOrFail()->customer_id);
    }

    public function test_a_bad_token_is_still_accepted_as_guest(): void
    {
        $this->withHeader('Authorization', 'Bearer token-rusak')
            ->postJson('/api/partnership/submit', ['applicant_name' => 'Budi', 'phone_number' => '0811', 'email' => 'b@example.com', 'city' => 'Jakarta'])
            ->assertStatus(201);

        $this->assertNull(PartnershipInquiry::firstOrFail()->customer_id);
    }

    public function test_franchise_validation(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class); // lebih dari 5 request di sini
        $valid = ['applicant_name' => 'Rina', 'phone_number' => '0811', 'email' => 'rina@example.com', 'city' => 'Bandung'];

        foreach (['applicant_name', 'phone_number', 'email', 'city'] as $field) {
            $this->postJson('/api/partnership/submit', array_diff_key($valid, [$field => 1]))
                ->assertStatus(422)->assertJsonPath('success', false);
        }
        $this->postJson('/api/partnership/submit', [...$valid, 'email' => 'bukan-email'])->assertStatus(422);
        $this->postJson('/api/partnership/submit', [...$valid, 'message' => str_repeat('a', 2001)])->assertStatus(422);
        $this->assertSame(0, PartnershipInquiry::count());
    }

    public function test_franchise_submit_is_throttled_to_five_per_minute(): void
    {
        $valid = ['applicant_name' => 'Rina', 'phone_number' => '0811', 'email' => 'rina@example.com', 'city' => 'Bandung'];

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/partnership/submit', $valid)->assertStatus(201);
        }
        $this->postJson('/api/partnership/submit', $valid)->assertStatus(429);
    }

    public function test_new_application_notifies_entitled_active_staff_only(): void
    {
        $admin = $this->user('super_admin');
        $withMenu = $this->user('kasir');
        $noMenu = $this->user('kasir', ['SomeOtherResource']);
        $inactive = $this->user('super_admin', null, ['is_active' => false]);

        $this->franchise();

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(1, $withMenu->notifications()->count());
        $this->assertSame(0, $noMenu->notifications()->count());
        $this->assertSame(0, $inactive->notifications()->count());

        $data = $admin->notifications()->first()->data;
        $this->assertSame('Pengajuan Kemitraan Baru', $data['title']);
        $this->assertStringContainsString('Franchise', $data['body']);
    }

    // ------------------------------------------------------------- daftar partner real-time

    #[DataProvider('signupFlows')]
    public function test_signup_creates_partner_account_referral_code_and_inquiry_trail(string $url, string $source, string $linkPath): void
    {
        $response = $this->postJson($url, [
            'name' => 'Andi', 'phone' => '0812-3456-7890', 'email' => 'andi@example.com',
            'car_brand' => 'Toyota', 'dealer_name' => 'Auto2000 PIK',
        ])->assertStatus(201)->assertJsonPath('success', true);

        $code = $response->json('data.referral_code');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $code);
        $this->assertStringEndsWith($linkPath . $code, $response->json('data.referral_link'));
        $this->assertStringStartsWith('data:image/png;base64,', $response->json('data.qr_data_uri'));

        $partner = Partner::where('referral_code', $code)->firstOrFail();
        $this->assertSame('Andi - Auto2000 PIK', $partner->business_name);
        $this->assertSame('6281234567890', $partner->phone, 'Nomor WA dinormalisasi ke format 62.');
        $this->assertSame($source, $partner->source);
        $this->assertSame('active', $partner->status);
        $this->assertTrue($partner->user->hasRole('partner'));
        $this->assertSame('andi@example.com', $partner->user->email);

        $inquiry = PartnershipInquiry::where('partner_id', $partner->id)->firstOrFail();
        $this->assertSame('sales', $inquiry->category);
        $this->assertSame($source, $inquiry->source);
        $this->assertSame('deal', $inquiry->status);
        $this->assertSame('Toyota', $inquiry->car_brand);
        $this->assertSame('Auto2000 PIK', $inquiry->dealer_name);
    }

    #[DataProvider('signupFlows')]
    public function test_signup_without_email_uses_a_placeholder_account_email_but_keeps_the_inquiry_clean(string $url): void
    {
        $code = $this->postJson($url, ['name' => 'Tanpa Email', 'phone' => '081355550000', 'car_brand' => 'Honda'])
            ->assertStatus(201)->json('data.referral_code');

        $partner = Partner::where('referral_code', $code)->firstOrFail();
        $this->assertStringEndsWith('@no-reply.ginnva.id', $partner->user->email);
        $this->assertSame('Tanpa Email', $partner->business_name, 'Tanpa dealer, nama usaha = nama saja.');
        $this->assertNull(PartnershipInquiry::where('partner_id', $partner->id)->firstOrFail()->email, 'Email palsu tidak masuk catatan pengajuan.');
    }

    #[DataProvider('signupFlows')]
    public function test_signup_is_idempotent_per_whatsapp_number_regardless_of_format(string $url): void
    {
        $first = $this->postJson($url, ['name' => 'Andi', 'phone' => '081234567890', 'car_brand' => 'Toyota'])->assertStatus(201);

        $second = $this->postJson($url, ['name' => 'Andi Lagi', 'phone' => '+62 812-3456-7890', 'car_brand' => 'Toyota'])->assertStatus(200);

        $this->assertSame($first->json('data.referral_code'), $second->json('data.referral_code'));
        $this->assertSame(1, Partner::count());
        $this->assertSame(1, PartnershipInquiry::count());
        $this->assertSame(1, User::where('email', 'like', '%no-reply.ginnva.id')->count());
    }

    #[DataProvider('signupFlows')]
    public function test_signup_is_also_idempotent_by_email_for_an_existing_partner(string $url): void
    {
        $first = $this->postJson($url, ['name' => 'Andi', 'phone' => '081234567890', 'email' => 'andi@example.com', 'car_brand' => 'Toyota'])->assertStatus(201);

        $second = $this->postJson($url, ['name' => 'Andi', 'phone' => '081299990000', 'email' => 'andi@example.com', 'car_brand' => 'Toyota'])->assertStatus(200);

        $this->assertSame($first->json('data.referral_code'), $second->json('data.referral_code'));
        $this->assertSame(1, Partner::count());
    }

    #[DataProvider('signupFlows')]
    public function test_signup_rejects_an_email_owned_by_a_non_partner_account(string $url): void
    {
        $this->user('kasir', null, ['email' => 'staf@example.com']);

        $this->postJson($url, ['name' => 'Andi', 'phone' => '081234567890', 'email' => 'staf@example.com', 'car_brand' => 'Toyota'])
            ->assertStatus(422)->assertJsonPath('success', false)->assertJsonPath('errors.email.0', 'Email sudah digunakan.');

        $this->assertSame(0, Partner::count());
        $this->assertSame(0, PartnershipInquiry::count());
    }

    #[DataProvider('signupFlows')]
    public function test_signup_validation(string $url): void
    {
        $valid = ['name' => 'Andi', 'phone' => '081234567890', 'car_brand' => 'Toyota'];

        foreach (['name', 'phone', 'car_brand'] as $field) {
            $this->postJson($url, array_diff_key($valid, [$field => 1]))->assertStatus(422)->assertJsonPath('success', false);
        }
        $this->postJson($url, [...$valid, 'email' => 'bukan-email'])->assertStatus(422);

        $this->assertSame(0, Partner::count());
    }

    #[DataProvider('signupFlows')]
    public function test_signup_is_throttled_to_five_per_minute(string $url): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson($url, ['name' => "Sales {$i}", 'phone' => "08130000000{$i}", 'car_brand' => 'Toyota'])->assertStatus(201);
        }

        $this->postJson($url, ['name' => 'Keenam', 'phone' => '081300000099', 'car_brand' => 'Toyota'])->assertStatus(429);
    }

    public function test_lookup_returns_only_public_sales_data_and_works_across_both_endpoints(): void
    {
        $code = $this->postJson('/api/giias/partner-signup', [
            'name' => 'Andi', 'phone' => '081234567890', 'email' => 'andi@example.com', 'car_brand' => 'Toyota', 'dealer_name' => 'Auto2000',
        ])->json('data.referral_code');

        foreach (["/api/giias/partner-lookup/{$code}", "/api/partner-lookup/{$code}"] as $url) {
            $data = $this->getJson($url)->assertSuccessful()->json('data');

            $this->assertSame(['name' => 'Andi', 'car_brand' => 'Toyota', 'dealer_name' => 'Auto2000'], $data);
        }

        $this->getJson('/api/giias/partner-lookup/TIDAKADA')->assertStatus(404)->assertJsonPath('success', false);
        $this->getJson('/api/partner-lookup/TIDAKADA')->assertStatus(404);
    }

    public function test_lookup_for_an_admin_created_partner_falls_back_to_business_name(): void
    {
        $partner = Partner::createAccount(['business_name' => 'Mitra Manual', 'email' => 'manual@example.com', 'password' => 'rahasia123']);

        $this->getJson("/api/partner-lookup/{$partner->referral_code}")->assertSuccessful()
            ->assertExactJson(['success' => true, 'data' => ['name' => 'Mitra Manual', 'car_brand' => null, 'dealer_name' => null]]);
    }

    // ------------------------------------------------------------- Filament

    public function test_filament_list_renders_and_filters(): void
    {
        $this->asAdmin();
        $franchise = $this->franchise();
        $sales = $this->franchise(['category' => 'sales', 'source' => 'giias', 'city' => null, 'status' => 'deal', 'car_brand' => 'Toyota']);
        $rejected = $this->franchise(['status' => 'rejected']);

        Livewire::test(ListPartnershipInquiries::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$franchise, $sales, $rejected])
            ->filterTable('category', 'sales')
            ->assertCanSeeTableRecords([$sales])->assertCanNotSeeTableRecords([$franchise, $rejected]);

        Livewire::test(ListPartnershipInquiries::class)
            ->filterTable('source', 'giias')
            ->assertCanSeeTableRecords([$sales])->assertCanNotSeeTableRecords([$franchise]);

        Livewire::test(ListPartnershipInquiries::class)
            ->filterTable('status', 'rejected')
            ->assertCanSeeTableRecords([$rejected])->assertCanNotSeeTableRecords([$franchise, $sales]);
    }

    public function test_follow_up_updates_status_and_notes_and_is_audited_without_touching_applicant_data(): void
    {
        $this->asAdmin();
        $inquiry = $this->franchise();

        Livewire::test(EditPartnershipInquiry::class, ['record' => $inquiry->getKey()])
            ->fillForm(['status' => 'contacted', 'notes' => 'Sudah ditelepon, minta proposal.', 'applicant_name' => 'Diubah'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $inquiry->fresh();
        $this->assertSame('contacted', $fresh->status);
        $this->assertSame('Sudah ditelepon, minta proposal.', $fresh->notes);
        $this->assertSame('Rina', $fresh->applicant_name, 'Data pemohon dikunci.');
        $this->assertTrue(Activity::where('log_name', 'partnership_inquiry')->where('subject_id', $inquiry->id)->where('event', 'updated')->exists());
    }

    public function test_status_is_required(): void
    {
        $this->asAdmin();
        $inquiry = $this->franchise();

        Livewire::test(EditPartnershipInquiry::class, ['record' => $inquiry->getKey()])
            ->fillForm(['status' => null])
            ->call('save')
            ->assertHasFormErrors(['status' => 'required']);
    }

    public function test_make_partner_action_is_only_for_deal_without_partner(): void
    {
        $this->asAdmin();
        $new = $this->franchise();
        $deal = $this->franchise(['status' => 'deal']);
        $converted = $this->franchise(['status' => 'deal', 'partner_id' => Partner::createAccount([
            'business_name' => 'Sudah Jadi', 'email' => 'sudah@example.com', 'password' => 'rahasia123',
        ])->id]);

        Livewire::test(ListPartnershipInquiries::class)
            ->assertTableActionHidden('jadikan_partner', $new)
            ->assertTableActionVisible('jadikan_partner', $deal)
            ->assertTableActionHidden('jadikan_partner', $converted);
    }

    public function test_make_partner_creates_account_links_the_inquiry_and_hides_the_button(): void
    {
        $this->asAdmin();
        $deal = $this->franchise(['status' => 'deal', 'source' => null]);

        Livewire::test(ListPartnershipInquiries::class)
            ->callTableAction('jadikan_partner', $deal, data: [
                'business_name' => 'Rina Auto Care', 'email' => 'rina.partner@example.com',
                'phone' => '081234567890', 'password' => 'rahasia123',
            ])
            ->assertHasNoTableActionErrors();

        $deal->refresh();
        $partner = Partner::findOrFail($deal->partner_id);
        $this->assertSame('Rina Auto Care', $partner->business_name);
        $this->assertSame('active', $partner->status);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $partner->referral_code);
        $this->assertTrue($partner->user->hasRole('partner'));
        $this->assertSame('rina.partner@example.com', $partner->user->email);
        $this->assertTrue(Activity::where('log_name', 'partnership_inquiry')->where('subject_id', $deal->id)->exists());

        Livewire::test(ListPartnershipInquiries::class)->assertTableActionHidden('jadikan_partner', $deal->fresh());
    }

    public function test_make_partner_form_is_prefilled_and_validates_email_and_password(): void
    {
        $this->asAdmin();
        $this->user('kasir', null, ['email' => 'dipakai@example.com']);
        $deal = $this->franchise(['status' => 'deal']);

        Livewire::test(ListPartnershipInquiries::class)
            ->mountTableAction('jadikan_partner', $deal)
            ->assertTableActionDataSet(['business_name' => 'Rina', 'email' => 'rina@example.com', 'phone' => '081234567890']);

        Livewire::test(ListPartnershipInquiries::class)
            ->callTableAction('jadikan_partner', $deal, data: ['business_name' => 'X', 'email' => 'dipakai@example.com', 'password' => 'rahasia123'])
            ->assertHasTableActionErrors(['email' => 'unique']);

        Livewire::test(ListPartnershipInquiries::class)
            ->callTableAction('jadikan_partner', $deal, data: ['business_name' => 'X', 'email' => 'baru@example.com', 'password' => 'pendek'])
            ->assertHasTableActionErrors(['password' => 'min']);

        $this->assertNull($deal->fresh()->partner_id);
        $this->assertSame(0, Partner::count());
    }

    public function test_navigation_badge_counts_only_new_applications(): void
    {
        $this->asAdmin();
        $this->franchise();
        $this->franchise();
        $this->franchise(['status' => 'contacted']);
        $this->franchise(['status' => 'deal']);

        $this->assertSame('2', PartnershipInquiryResource::getNavigationBadge());
    }

    public function test_admin_cannot_create_but_can_delete_and_permissions_are_enforced(): void
    {
        $inquiry = $this->franchise();
        $withMenu = $this->user('kasir');
        $noMenu = $this->user('kasir', ['SomeOtherResource']);

        $this->assertFalse(PartnershipInquiryResource::canCreate());

        $this->actingAs($withMenu, 'web');
        $this->assertTrue(PartnershipInquiryResource::canViewAny());
        $this->assertTrue(PartnershipInquiryResource::canEdit($inquiry));
        $this->assertFalse((bool) PartnershipInquiryResource::canDelete($inquiry));

        $this->actingAs($noMenu, 'web');
        $this->assertFalse((bool) PartnershipInquiryResource::canViewAny());
        $this->assertFalse((bool) PartnershipInquiryResource::canEdit($inquiry));
        $this->assertFalse((bool) PartnershipInquiryResource::canDelete($inquiry));

        $this->asAdmin();
        Livewire::test(ListPartnershipInquiries::class)->callTableAction('delete', $inquiry);
        $this->assertNull(PartnershipInquiry::find($inquiry->id));
    }
}
