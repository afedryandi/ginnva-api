<?php

namespace Tests\Feature;

use App\Models\AttendanceDeductionRule;
use App\Models\Booking;
use App\Models\CompanyPolicy;
use App\Models\Customer;
use App\Models\GuideNote;
use App\Models\NotificationCampaign;
use App\Models\NotificationDelivery;
use App\Models\PolicyAcknowledgment;
use App\Models\ProductImportLog;
use App\Models\SalaryGrade;
use App\Models\SafetyIncident;
use App\Models\ScheduledPriceChange;
use App\Models\Store;
use App\Models\TransactionApprovalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Perintah demo:lengkapi mengisi menu-menu yang kosong. Di database uji tidak ada data master (akun, kategori, bahan baku), jadi bagian yang
 * butuh data master gagal dan dilewati; yang diuji di sini adalah bagian mandiri, keamanan (tanpa kampanye terjadwal / harga aktif), kemampuan
 * dijalankan ulang, dan pembersihan.
 */
class DemoLengkapiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    public function test_it_fills_the_standalone_sections(): void
    {
        $this->artisan('demo:lengkapi')->assertSuccessful();

        $this->assertSame(4, User::where('email', 'like', '%@lengkapi.ginnva.test')->count());
        $this->assertSame(4, Booking::withoutGlobalScopes()->where('booking_number', 'like', 'DEMO-LGK-%')->count());
        $this->assertSame(3, TransactionApprovalRequest::count());
        $this->assertSame(1, AttendanceDeductionRule::count());
        $this->assertSame(4, SalaryGrade::count());
        $this->assertSame(3, CompanyPolicy::count());
        $this->assertSame(2, PolicyAcknowledgment::count());
        $this->assertSame(3, SafetyIncident::withoutGlobalScopes()->count());
        $this->assertSame(2, ProductImportLog::count());
        $this->assertSame(1, GuideNote::count());
        $this->assertSame(5, NotificationDelivery::count());
    }

    public function test_no_campaign_is_scheduled_and_no_price_change_is_pending(): void
    {
        $this->artisan('demo:lengkapi')->assertSuccessful();

        $this->assertSame(3, NotificationCampaign::count());
        $this->assertSame(0, NotificationCampaign::whereIn('status', [NotificationCampaign::SCHEDULED, NotificationCampaign::SENDING])->count(), 'Penjadwal tidak boleh menemukan kampanye demo.');
        $this->assertSame(0, NotificationDelivery::where('status', NotificationDelivery::ACCEPTED)->count(), 'Pengecek tanda terima tidak boleh menemukan pengiriman demo.');
        $this->assertSame(2, ScheduledPriceChange::count());
        $this->assertSame(0, ScheduledPriceChange::where('status', ScheduledPriceChange::STATUS_SCHEDULED)->count(), 'Tidak boleh ada perubahan harga yang menunggu diterapkan.');
    }

    public function test_it_can_run_twice_without_duplicating_and_does_not_touch_the_activity_log(): void
    {
        $this->artisan('demo:lengkapi')->assertSuccessful();
        $this->artisan('demo:lengkapi')->assertSuccessful();

        $this->assertSame(4, User::where('email', 'like', '%@lengkapi.ginnva.test')->count());
        $this->assertSame(3, CompanyPolicy::count());
        $this->assertSame(0, Activity::count());
    }

    public function test_cleanup_removes_everything_it_made(): void
    {
        $this->artisan('demo:lengkapi')->assertSuccessful();
        $this->artisan('demo:lengkapi', ['--cleanup' => true])->assertSuccessful();

        $this->assertSame(0, User::where('email', 'like', '%@lengkapi.ginnva.test')->count());
        $this->assertSame(0, Booking::withoutGlobalScopes()->where('booking_number', 'like', 'DEMO-LGK-%')->count());
        $this->assertSame(0, Customer::where('name', 'Demo Pelanggan Lengkapi')->count());
        $this->assertSame(0, TransactionApprovalRequest::count());
        $this->assertSame(0, CompanyPolicy::count());
        $this->assertSame(0, PolicyAcknowledgment::count());
        $this->assertSame(0, SalaryGrade::count());
        $this->assertSame(0, AttendanceDeductionRule::count());
        $this->assertSame(0, NotificationCampaign::count());
        $this->assertSame(0, NotificationDelivery::count());
        $this->assertSame(0, ScheduledPriceChange::count());
        $this->assertSame(0, ProductImportLog::count());
        $this->assertSame(0, GuideNote::count());
    }

    public function test_it_refuses_to_run_outside_a_demo_database(): void
    {
        config(['database.connections.' . config('database.default') . '.database' => 'ginnva_production']);

        $this->artisan('demo:lengkapi')->assertFailed();

        $this->assertSame(0, User::where('email', 'like', '%@lengkapi.ginnva.test')->count());
    }
}
