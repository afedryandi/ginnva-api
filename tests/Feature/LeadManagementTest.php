<?php

namespace Tests\Feature;

use App\Filament\Pages\LeadFunnelReport;
use App\Filament\Resources\ProductInquiryResource\Pages\EditProductInquiry;
use App\Filament\Resources\ProductInquiryResource\Pages\ListProductInquiries;
use App\Models\PartnershipInquiry;
use App\Models\ProductInquiry;
use App\Models\User;
use App\Services\LeadFunnelService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Marketing/Konten Fase 1 "Lead terkelola" (2026-10-23): pemilik lead, hasil menang/kalah + alasan, waktu respons pertama,
 * pengingat lead telat, dan angka Dasbor Lead.
 */
class LeadManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        config(['marketing.lead_response_sla_hours' => 24]);
    }

    private function staff(string $role = 'super_admin'): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]);
        \Spatie\Permission\Models\Role::findOrCreate($role, 'web');
        $user->assignRole($role);

        return $user;
    }

    private function inquiry(array $over = []): ProductInquiry
    {
        return ProductInquiry::create($over + ['customer_name' => 'Budi', 'customer_contact' => '0812', 'status' => 'new', 'channel' => 'web_form']);
    }

    private function partnership(array $over = []): PartnershipInquiry
    {
        return PartnershipInquiry::create($over + [
            'applicant_name' => 'Sinta', 'phone_number' => '0813', 'email' => 'sinta@example.com', 'city' => 'Bandung',
            'status' => 'new', 'channel' => 'web_form',
        ]);
    }

    // ------------------------------------------------------------- observer: waktu respons & hasil

    public function test_contacted_at_is_stamped_once_when_the_status_first_leaves_new(): void
    {
        $inquiry = $this->inquiry();
        $this->assertNull($inquiry->contacted_at);

        Carbon::setTestNow('2026-10-23 10:00:00');
        $inquiry->update(['status' => 'contacted']);
        $this->assertSame('2026-10-23 10:00:00', $inquiry->fresh()->contacted_at->toDateTimeString());

        Carbon::setTestNow('2026-10-24 10:00:00');
        $inquiry->fresh()->update(['status' => 'closed']);
        $this->assertSame('2026-10-23 10:00:00', $inquiry->fresh()->contacted_at->toDateTimeString(), 'Tidak ditimpa.');

        $partnership = $this->partnership();
        $partnership->update(['notes' => 'catatan saja']);
        $this->assertNull($partnership->fresh()->contacted_at, 'Mengubah catatan bukan menindak.');
        $partnership->update(['status' => 'contacted']);
        $this->assertNotNull($partnership->fresh()->contacted_at);

        Carbon::setTestNow();
    }

    public function test_outcome_sets_the_timestamp_and_the_reason_only_applies_to_lost(): void
    {
        $inquiry = $this->inquiry();

        $inquiry->update(['outcome' => 'lost', 'outcome_reason' => 'harga']);
        $inquiry = $inquiry->fresh();
        $this->assertNotNull($inquiry->outcome_at);
        $this->assertSame('harga', $inquiry->outcome_reason);

        $inquiry->update(['outcome' => 'won']);
        $inquiry = $inquiry->fresh();
        $this->assertNull($inquiry->outcome_reason, 'Alasan hanya untuk kalah.');
        $this->assertNotNull($inquiry->outcome_at);

        $inquiry->update(['outcome' => null]);
        $this->assertNull($inquiry->fresh()->outcome_at);
    }

    // ------------------------------------------------------------- panel

    public function test_the_panel_requires_a_reason_when_marking_a_lead_lost(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = $this->staff();
        $this->actingAs($admin, 'web');
        $inquiry = $this->inquiry();

        Livewire::test(EditProductInquiry::class, ['record' => $inquiry->getKey()])
            ->fillForm(['outcome' => 'lost', 'outcome_reason' => null])
            ->call('save')
            ->assertHasFormErrors(['outcome_reason' => 'required']);

        Livewire::test(EditProductInquiry::class, ['record' => $inquiry->getKey()])
            ->fillForm(['outcome' => 'lost', 'outcome_reason' => 'harga', 'owner_user_id' => $admin->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $inquiry = $inquiry->fresh();
        $this->assertSame('lost', $inquiry->outcome);
        $this->assertSame($admin->id, $inquiry->owner_user_id);
    }

    public function test_the_list_can_be_filtered_by_outcome_and_owner(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = $this->staff();
        $this->actingAs($admin, 'web');
        $won = $this->inquiry(['outcome' => 'won', 'owner_user_id' => $admin->id]);
        $open = $this->inquiry(['customer_name' => 'Terbuka']);

        Livewire::test(ListProductInquiries::class)
            ->filterTable('outcome', 'won')
            ->assertCanSeeTableRecords([$won])
            ->assertCanNotSeeTableRecords([$open])
            ->removeTableFilter('outcome')
            ->filterTable('owner_user_id', $admin->id)
            ->assertCanSeeTableRecords([$won])
            ->assertCanNotSeeTableRecords([$open]);
    }

    // ------------------------------------------------------------- angka corong

    public function test_the_funnel_summary_counts_responses_outcomes_and_groups_by_channel(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $created = Carbon::parse('2026-10-20 08:00:00');

        // Direspons 2 jam, menang, kanal web, kampanye A.
        $a = $this->inquiry(['channel' => 'web_form', 'utm_campaign' => 'A']);
        $a->forceFill(['created_at' => $created, 'contacted_at' => $created->copy()->addHours(2), 'status' => 'contacted', 'outcome' => 'won', 'outcome_at' => now()])->saveQuietly();
        // Direspons 50 jam (lewat target), kalah karena harga, kanal WhatsApp.
        $b = $this->inquiry(['channel' => 'whatsapp']);
        $b->forceFill(['created_at' => $created, 'contacted_at' => $created->copy()->addHours(50), 'status' => 'contacted', 'outcome' => 'lost', 'outcome_reason' => 'harga', 'outcome_at' => now()])->saveQuietly();
        // Belum ditindak dan telat.
        $c = $this->inquiry(['channel' => 'web_form', 'utm_campaign' => 'A']);
        $c->forceFill(['created_at' => $created])->saveQuietly();
        // Kemitraan: tanpa kolom hasil, status deal dibaca menang.
        $d = $this->partnership(['channel' => 'web_form']);
        $d->forceFill(['created_at' => $created, 'status' => 'deal', 'contacted_at' => $created->copy()->addHours(10)])->saveQuietly();

        $r = app(LeadFunnelService::class)->summarize(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

        $this->assertSame(4, $r['totals']['total']);
        $this->assertSame(3, $r['totals']['responded']);
        $this->assertSame(10.0, $r['totals']['median_response_hours']);
        $this->assertSame(2, $r['totals']['within_sla']);
        $this->assertSame(66.7, $r['totals']['within_sla_percent']);
        $this->assertSame(1, $r['totals']['overdue_unanswered']);
        $this->assertSame(2, $r['totals']['won']);
        $this->assertSame(1, $r['totals']['lost']);
        $this->assertSame(66.7, $r['totals']['win_rate_percent']);
        $this->assertSame(1, $r['totals']['open']);

        $byChannel = collect($r['by_channel'])->keyBy('key');
        $this->assertSame(3, $byChannel['web_form']['total']);
        $this->assertSame(1, $byChannel['whatsapp']['lost']);

        $this->assertSame(['A'], collect($r['by_campaign'])->pluck('key')->all());
        $this->assertSame(2, $r['by_campaign'][0]['total']);
        $this->assertSame('Harga di atas anggaran', $r['lost_reasons'][0]['reason']);

        $onlyPartnership = app(LeadFunnelService::class)->summarize(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), 'partnership');
        $this->assertSame(1, $onlyPartnership['totals']['total']);

        $outside = app(LeadFunnelService::class)->summarize(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
        $this->assertSame(0, $outside['totals']['total']);
        $this->assertNull($outside['totals']['win_rate_percent']);

        Carbon::setTestNow();
    }

    public function test_the_lead_dashboard_page_renders_for_admin_and_is_hidden_without_menu_access(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = $this->staff();
        $this->actingAs($admin, 'web');
        $this->inquiry(['utm_campaign' => 'ppf-h10']);

        Livewire::test(LeadFunnelReport::class)
            ->assertSuccessful()
            ->assertSee('Lead Masuk')
            ->assertSee('ppf-h10');

        $this->assertTrue(LeadFunnelReport::canAccess());

        $restricted = $this->staff('kasir');
        $restricted->update(['menu_access' => []]);
        $this->actingAs($restricted->fresh(), 'web');
        $this->assertFalse(LeadFunnelReport::canAccess());
    }

    // ------------------------------------------------------------- pengingat

    public function test_the_reminder_targets_stale_untouched_leads_and_goes_to_the_owner_first(): void
    {
        $owner = $this->staff('super_admin');
        $other = $this->staff('super_admin');

        $stale = $this->inquiry(['owner_user_id' => $owner->id]);
        $stale->forceFill(['created_at' => now()->subHours(30)])->saveQuietly();

        $fresh = $this->inquiry(['owner_user_id' => $other->id]);   // baru masuk, belum telat
        $handled = $this->inquiry(['owner_user_id' => $other->id]);
        $handled->forceFill(['created_at' => now()->subHours(40), 'status' => 'contacted', 'contacted_at' => now()->subHours(20)])->saveQuietly();

        DB::table('notifications')->delete();
        $this->artisan('leads:remind-stale')->assertSuccessful();

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $owner->id)->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $other->id)->count());
    }

    public function test_the_reminder_for_an_unowned_stale_partnership_goes_to_staff_with_the_menu(): void
    {
        $admin = $this->staff('super_admin');
        $lead = $this->partnership();
        $lead->forceFill(['created_at' => now()->subHours(30)])->saveQuietly();

        DB::table('notifications')->delete();
        $this->artisan('leads:remind-stale')->assertSuccessful();

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $admin->id)->count());

        // Setelah ditindak, tidak diingatkan lagi.
        $lead->fresh()->update(['status' => 'contacted']);
        DB::table('notifications')->delete();
        $this->artisan('leads:remind-stale')->assertSuccessful();
        $this->assertSame(0, DB::table('notifications')->count());
    }
}
