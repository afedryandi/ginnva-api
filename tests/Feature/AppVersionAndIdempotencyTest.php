<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 0 pengerjaan ulang booking (2026-10-14): versi minimum aplikasi mobile (X-App-Version) dan kunci idempotensi
 * (Idempotency-Key) pada pembuatan booking.
 */
class AppVersionAndIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'store_id' => $this->store->id,
            'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true,
            'preferred_date' => now()->addDays(5)->toDateString(),
        ], $overrides);
    }

    private function book(string $key, array $payload = [], ?Customer $customer = null)
    {
        return $this->actingAs($customer ?? $this->customer, 'customer')
            ->postJson('/api/customer/bookings', $payload ?: $this->payload(), ['Idempotency-Key' => $key]);
    }

    // ------------------------------------------------------------- versi minimum

    public function test_app_older_than_the_minimum_version_gets_426_with_store_link(): void
    {
        config(['app_version.android.min' => '1.2.0', 'app_version.android.store_url' => 'https://play.test/ginnva']);

        $this->getJson('/api/stores', ['X-App-Version' => '1.1.9', 'X-App-Platform' => 'android'])
            ->assertStatus(426)
            ->assertJsonPath('code', 'upgrade_required')
            ->assertJsonPath('min_version', '1.2.0')
            ->assertJsonPath('store_url', 'https://play.test/ginnva');
    }

    public function test_app_at_or_above_the_minimum_version_passes(): void
    {
        config(['app_version.android.min' => '1.2.0']);

        $this->getJson('/api/stores', ['X-App-Version' => '1.2.0', 'X-App-Platform' => 'android'])->assertOk();
        $this->getJson('/api/stores', ['X-App-Version' => '1.10.0', 'X-App-Platform' => 'android'])->assertOk(); // 1.10 > 1.9 (bukan perbandingan teks)
    }

    public function test_clients_without_the_header_or_with_invalid_values_are_never_blocked(): void
    {
        config(['app_version.android.min' => '9.0.0', 'app_version.ios.min' => '9.0.0']);

        $this->getJson('/api/stores')->assertOk(); // aplikasi lama dan ginnva-web tidak mengirim header
        $this->getJson('/api/stores', ['X-App-Version' => 'abc', 'X-App-Platform' => 'android'])->assertOk();
        $this->getJson('/api/stores', ['X-App-Version' => '1.0.0', 'X-App-Platform' => 'windows'])->assertOk();
        $this->getJson('/api/stores', ['X-App-Version' => '1.0.0'])->assertOk();
    }

    public function test_the_minimum_is_per_platform_and_unset_means_no_enforcement(): void
    {
        config(['app_version.android.min' => '2.0.0', 'app_version.ios.min' => null]);

        $this->getJson('/api/stores', ['X-App-Version' => '1.0.0', 'X-App-Platform' => 'ios'])->assertOk();
        $this->getJson('/api/stores', ['X-App-Version' => '1.0.0', 'X-App-Platform' => 'android'])->assertStatus(426);
    }

    public function test_app_config_endpoint_stays_reachable_and_reports_the_state(): void
    {
        config(['app_version.android.min' => '1.2.0', 'app_version.android.latest' => '1.5.0']);

        $this->getJson('/api/app/config', ['X-App-Version' => '1.0.0', 'X-App-Platform' => 'android'])
            ->assertOk()
            ->assertJsonPath('data.upgrade_required', true)
            ->assertJsonPath('data.update_available', true)
            ->assertJsonPath('data.min_version', '1.2.0')
            ->assertJsonPath('data.latest_version', '1.5.0');

        $this->getJson('/api/app/config', ['X-App-Version' => '1.5.0', 'X-App-Platform' => 'android'])
            ->assertJsonPath('data.upgrade_required', false)
            ->assertJsonPath('data.update_available', false);

        $this->getJson('/api/app/config')->assertOk()->assertJsonPath('data.upgrade_required', false);
    }

    // ------------------------------------------------------------- idempotensi

    public function test_retry_with_the_same_key_returns_the_original_booking_without_creating_another(): void
    {
        $first = $this->book('key-abc-12345')->assertStatus(201);
        $second = $this->book('key-abc-12345')->assertStatus(201);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.booking_number'), $second->json('data.booking_number'));
        $this->assertSame('true', $second->headers->get('Idempotent-Replayed'));
        $this->assertNull($first->headers->get('Idempotent-Replayed'));
        $this->assertSame(1, Booking::where('customer_id', $this->customer->id)->count());
    }

    public function test_without_a_key_behaviour_is_unchanged_and_the_duplicate_rule_still_applies(): void
    {
        $this->actingAs($this->customer, 'customer')->postJson('/api/customer/bookings', $this->payload())->assertStatus(201);
        $this->actingAs($this->customer, 'customer')->postJson('/api/customer/bookings', $this->payload())->assertStatus(422);

        $this->assertSame(1, Booking::where('customer_id', $this->customer->id)->count());
        $this->assertSame(0, DB::table('idempotency_keys')->count());
    }

    public function test_same_key_with_a_different_body_is_rejected(): void
    {
        $this->book('key-abc-12345')->assertStatus(201);

        $this->book('key-abc-12345', $this->payload(['preferred_date' => now()->addDays(9)->toDateString()]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'idempotency_key_reused');

        $this->assertSame(1, Booking::where('customer_id', $this->customer->id)->count());
    }

    public function test_keys_are_scoped_per_customer(): void
    {
        $other = Customer::create(['name' => 'Siti', 'phone_number' => '0813' . random_int(1000000, 9999999)]);

        $a = $this->book('shared-key-001')->assertStatus(201);
        $b = $this->book('shared-key-001', [], $other)->assertStatus(201);

        $this->assertNotSame($a->json('data.id'), $b->json('data.id'));
        $this->assertNull($b->headers->get('Idempotent-Replayed'));
    }

    public function test_a_failed_request_does_not_burn_the_key(): void
    {
        $this->book('key-retry-1234', $this->payload(['store_id' => null]))->assertStatus(422);
        $this->assertSame(0, DB::table('idempotency_keys')->count(), 'Gagal tidak boleh menyimpan kunci.');

        $this->book('key-retry-1234')->assertStatus(201); // kunci yang sama boleh dipakai setelah diperbaiki
    }

    public function test_a_request_still_in_progress_gets_409_and_a_stale_one_is_taken_over(): void
    {
        DB::table('idempotency_keys')->insert([
            'scope' => 'customer:' . $this->customer->id, 'key' => 'inflight-key-1', 'method' => 'POST', 'path' => 'api/customer/bookings',
            'request_hash' => hash('sha256', 'x'), 'status' => 'processing', 'created_at' => now(), 'expires_at' => now()->addDay(),
        ]);

        $this->book('inflight-key-1')->assertStatus(422); // isi berbeda dari baris tersimpan
        DB::table('idempotency_keys')->update(['request_hash' => $this->hashFor('inflight-key-1')]);
        $this->book('inflight-key-1')->assertStatus(409)->assertJsonPath('code', 'request_in_progress');

        DB::table('idempotency_keys')->update(['created_at' => now()->subMinutes(5)]);
        $this->book('inflight-key-1')->assertStatus(201); // baris basi diambil alih
    }

    public function test_malformed_keys_are_rejected_and_expired_ones_are_pruned(): void
    {
        $this->book('short')->assertStatus(422)->assertJsonPath('code', 'invalid_idempotency_key');
        $this->book('bad key with spaces!!')->assertStatus(422);

        DB::table('idempotency_keys')->insert([
            ['scope' => 'customer:1', 'key' => 'old-key-0001', 'method' => 'POST', 'path' => 'p', 'request_hash' => 'h', 'status' => 'completed', 'created_at' => now()->subDays(2), 'expires_at' => now()->subDay()],
            ['scope' => 'customer:1', 'key' => 'new-key-0001', 'method' => 'POST', 'path' => 'p', 'request_hash' => 'h', 'status' => 'completed', 'created_at' => now(), 'expires_at' => now()->addDay()],
        ]);

        $this->artisan('idempotency:prune')->assertSuccessful();

        $this->assertSame(['new-key-0001'], DB::table('idempotency_keys')->pluck('key')->all());
    }

    /** Sidik yang sama dengan yang dihitung middleware untuk isi bawaan test ini. */
    private function hashFor(string $key): string
    {
        $input = $this->payload();
        ksort($input);

        return hash('sha256', 'POST|api/customer/bookings|' . json_encode($input) . '|' . json_encode([]));
    }
}
