<?php

namespace Tests\Feature;

use App\Models\PriceRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Jejak audit aturan harga (koefisien ukuran kendaraan x bagian mobil): siapa mengubah apa, sebelum/sesudah.
 */
class PriceRuleAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_changes_to_a_price_rule_are_logged_with_who_and_before_after(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin-pr@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        $rule = PriceRule::create(['vehicle_size' => 'tes_ukuran', 'car_part' => 'tes_bagian', 'coefficient' => 1.50]);
        $rule->update(['coefficient' => 2.00]);
        $rule->delete();

        $logs = Activity::where('log_name', 'price_rule')->where('subject_id', $rule->id)->orderBy('id')->get();

        $this->assertSame(['created', 'updated', 'deleted'], $logs->pluck('event')->all());
        $this->assertSame([$admin->id, $admin->id, $admin->id], $logs->pluck('causer_id')->all());
        $this->assertStringContainsString('diubah', $logs[1]->description);
        $this->assertEquals(1.5, (float) $logs[1]->properties['old']['coefficient']);
        $this->assertEquals(2.0, (float) $logs[1]->properties['attributes']['coefficient']);
    }

    public function test_an_unchanged_save_writes_no_log(): void
    {
        $rule = PriceRule::create(['vehicle_size' => 'tes_ukuran2', 'car_part' => 'tes_bagian2', 'coefficient' => 1.25]);
        $before = Activity::where('log_name', 'price_rule')->count();

        $rule->update(['coefficient' => 1.25]);

        $this->assertSame($before, Activity::where('log_name', 'price_rule')->count());
    }
}
