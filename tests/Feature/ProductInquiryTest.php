<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductInquiryResource;
use App\Filament\Resources\ProductInquiryResource\Pages\EditProductInquiry;
use App\Filament\Resources\ProductInquiryResource\Pages\ListProductInquiries;
use App\Filament\Widgets\PerluPerhatianWidget;
use App\Models\Customer;
use App\Models\ProductInquiry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Inquiry Produk: form publik (validasi, nomor AVL unik, throttle),
 * notifikasi ke staf berhak, tindak lanjut di Filament (status, catatan
 * internal, filter, badge, widget "Perlu Perhatian"), izin per aksi, audit,
 * dan anonimisasi saat customer menghapus akunnya.
 */
class ProductInquiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function inquiry(array $overrides = []): ProductInquiry
    {
        return ProductInquiry::create(array_merge([
            'customer_name' => 'Budi', 'customer_contact' => '08123456789',
            'message' => 'Apakah Color Change sudah tersedia?', 'status' => 'new',
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

    // ------------------------------------------------------------- form publik

    public function test_public_form_creates_an_inquiry_with_a_unique_avl_number(): void
    {
        $response = $this->postJson('/api/inquiry/submit', [
            'customer_name' => 'Siti', 'customer_contact' => 'siti@example.com', 'message' => 'Harga Architectural Film?',
        ])->assertStatus(201)->assertJsonPath('success', true);

        $number = $response->json('data.inquiry_number');
        $this->assertMatchesRegularExpression('/^AVL-\d{6}-[A-Z0-9]{4}$/', $number);

        $inquiry = ProductInquiry::where('inquiry_number', $number)->firstOrFail();
        $this->assertSame('new', $inquiry->status);
        $this->assertSame('Siti', $inquiry->customer_name);
        $this->assertSame('Harga Architectural Film?', $inquiry->message);

        $second = $this->postJson('/api/inquiry/submit', ['customer_name' => 'Ani', 'customer_contact' => '0811'])->assertStatus(201);
        $this->assertNotSame($number, $second->json('data.inquiry_number'));
    }

    public function test_message_is_optional_but_name_and_contact_are_required(): void
    {
        $this->postJson('/api/inquiry/submit', ['customer_name' => 'Siti', 'customer_contact' => '0811'])->assertStatus(201);
        $this->assertNull(ProductInquiry::firstOrFail()->message);

        $this->postJson('/api/inquiry/submit', ['customer_contact' => '0811'])
            ->assertStatus(422)->assertJsonPath('success', false);
        $this->postJson('/api/inquiry/submit', ['customer_name' => 'Siti'])->assertStatus(422);
        $this->postJson('/api/inquiry/submit', ['customer_name' => str_repeat('a', 256), 'customer_contact' => '0811'])->assertStatus(422);
        $this->postJson('/api/inquiry/submit', ['customer_name' => 'Siti', 'customer_contact' => '0811', 'message' => str_repeat('a', 2001)])->assertStatus(422);

        $this->assertSame(1, ProductInquiry::count());
    }

    public function test_public_form_is_throttled_to_five_per_minute(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/inquiry/submit', ['customer_name' => "Orang {$i}", 'customer_contact' => '0811'])->assertStatus(201);
        }

        $this->postJson('/api/inquiry/submit', ['customer_name' => 'Terlalu banyak', 'customer_contact' => '0811'])->assertStatus(429);
        $this->assertSame(5, ProductInquiry::count());
    }

    // ------------------------------------------------------------- notifikasi

    public function test_new_inquiry_notifies_entitled_active_staff_only(): void
    {
        $admin = $this->user('super_admin');
        $withMenu = $this->user('kasir');
        $noMenu = $this->user('kasir', ['SomeOtherResource']);
        $inactive = $this->user('super_admin', null, ['is_active' => false]);

        $this->inquiry();

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(1, $withMenu->notifications()->count());
        $this->assertSame(0, $noMenu->notifications()->count(), 'Tanpa akses menu Inquiry Produk tidak diberi tahu.');
        $this->assertSame(0, $inactive->notifications()->count(), 'Akun nonaktif tidak diberi tahu.');
        $this->assertSame('Inquiry Produk Baru', $admin->notifications()->first()->data['title']);
    }

    // ------------------------------------------------------------- Filament

    public function test_filament_list_renders_and_filters_by_status(): void
    {
        $this->asAdmin();
        $new = $this->inquiry();
        $contacted = $this->inquiry(['status' => 'contacted']);
        $closed = $this->inquiry(['status' => 'closed']);

        Livewire::test(ListProductInquiries::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$new, $contacted, $closed])
            ->filterTable('status', 'contacted')
            ->assertCanSeeTableRecords([$contacted])->assertCanNotSeeTableRecords([$new, $closed]);
    }

    public function test_follow_up_updates_status_and_notes_without_touching_customer_data(): void
    {
        $this->asAdmin();
        $inquiry = $this->inquiry();

        Livewire::test(EditProductInquiry::class, ['record' => $inquiry->getKey()])
            ->fillForm(['status' => 'contacted', 'notes' => 'Sudah dihubungi via WhatsApp, stok indent.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $inquiry->fresh();
        $this->assertSame('contacted', $fresh->status);
        $this->assertSame('Sudah dihubungi via WhatsApp, stok indent.', $fresh->notes);
        $this->assertSame('Budi', $fresh->customer_name);
        $this->assertSame('Apakah Color Change sudah tersedia?', $fresh->message);
        $this->assertTrue(Activity::where('log_name', 'product_inquiry')->where('subject_id', $inquiry->id)->where('event', 'updated')->exists());
    }

    public function test_customer_fields_cannot_be_changed_from_the_form(): void
    {
        $this->asAdmin();
        $inquiry = $this->inquiry();

        Livewire::test(EditProductInquiry::class, ['record' => $inquiry->getKey()])
            ->fillForm(['status' => 'closed', 'customer_name' => 'Diubah', 'message' => 'Diubah'])
            ->call('save');

        $fresh = $inquiry->fresh();
        $this->assertSame('closed', $fresh->status);
        $this->assertSame('Budi', $fresh->customer_name, 'Data dari customer dikunci (disabled).');
        $this->assertSame('Apakah Color Change sudah tersedia?', $fresh->message);
    }

    public function test_status_is_required(): void
    {
        $this->asAdmin();
        $inquiry = $this->inquiry();

        Livewire::test(EditProductInquiry::class, ['record' => $inquiry->getKey()])
            ->fillForm(['status' => null])
            ->call('save')
            ->assertHasFormErrors(['status' => 'required']);
    }

    public function test_navigation_badge_and_dashboard_widget_count_only_new_inquiries(): void
    {
        $this->asAdmin();
        $this->inquiry();
        $this->inquiry();
        $this->inquiry(['status' => 'contacted']);
        $this->inquiry(['status' => 'closed']);

        $this->assertSame('2', ProductInquiryResource::getNavigationBadge());

        $items = collect(Livewire::test(PerluPerhatianWidget::class)->instance()->getItems());
        $row = $items->firstWhere('label', 'Inquiry Produk Belum Ditindaklanjuti');
        $this->assertNotNull($row);
        $this->assertSame(2, $row['count']);
    }

    public function test_navigation_badge_disappears_when_all_are_handled(): void
    {
        $this->asAdmin();
        $this->inquiry(['status' => 'closed']);

        $this->assertNull(ProductInquiryResource::getNavigationBadge());
    }

    public function test_admin_cannot_create_inquiries_from_the_panel_but_can_delete(): void
    {
        $admin = $this->asAdmin();
        $inquiry = $this->inquiry();

        $this->assertFalse(ProductInquiryResource::canCreate());

        Livewire::test(ListProductInquiries::class)->callTableAction('delete', $inquiry);

        $this->assertNull(ProductInquiry::find($inquiry->id));
    }

    public function test_permissions_per_action(): void
    {
        $inquiry = $this->inquiry();
        $withMenu = $this->user('kasir');
        $noMenu = $this->user('kasir', ['SomeOtherResource']);

        $this->actingAs($withMenu, 'web');
        $this->assertTrue(ProductInquiryResource::canViewAny());
        $this->assertTrue(ProductInquiryResource::canEdit($inquiry));
        $this->assertFalse((bool) ProductInquiryResource::canDelete($inquiry), 'Hapus hanya untuk full-access / izin eksplisit.');

        $this->actingAs($noMenu, 'web');
        $this->assertFalse((bool) ProductInquiryResource::canViewAny());
        $this->assertFalse((bool) ProductInquiryResource::canEdit($inquiry));
        $this->assertFalse((bool) ProductInquiryResource::canDelete($inquiry));

        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue((bool) ProductInquiryResource::canDelete($inquiry));
    }

    // ------------------------------------------------------------- privasi

    public function test_deleting_a_customer_account_anonymises_matching_inquiries_only(): void
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '08123456789', 'email' => 'budi@example.com']);
        $byPhone = $this->inquiry(['customer_contact' => '08123456789']);
        $byEmail = $this->inquiry(['customer_contact' => 'budi@example.com']);
        $other = $this->inquiry(['customer_name' => 'Orang Lain', 'customer_contact' => '0899999999']);

        $token = JWTAuth::fromUser($customer);
        $this->withHeader('Authorization', "Bearer {$token}")->deleteJson('/api/customer/auth/account')->assertSuccessful();

        foreach ([$byPhone, $byEmail] as $anonymised) {
            $fresh = $anonymised->fresh();
            $this->assertSame('Pelanggan Terhapus', $fresh->customer_name);
            $this->assertSame('-', $fresh->customer_contact);
            $this->assertSame('Apakah Color Change sudah tersedia?', $fresh->message, 'Pertanyaan produknya tetap utuh.');
        }

        $this->assertSame('Orang Lain', $other->fresh()->customer_name);
        $this->assertSame('0899999999', $other->fresh()->customer_contact);
    }
}
