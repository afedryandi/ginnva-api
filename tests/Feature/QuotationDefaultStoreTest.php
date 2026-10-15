<?php

namespace Tests\Feature;

use App\Mail\NewQuotationMail;
use App\Models\FilmProduct;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Quotation dari pelanggan (web dan aplikasi mobile) selalu masuk ke toko Ginnva House, tidak bergantung toko yang dikirim klien
 * (keputusan pemilik 2026-10-15).
 */
class QuotationDefaultStoreTest extends TestCase
{
    use RefreshDatabase;

    private Store $house;
    private Store $other;
    private Vehicle $vehicle;
    private FilmProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');

        $this->house = Store::create(['city' => 'Jakarta', 'address' => 'Jl. House 1', 'name' => 'Ginnva House', 'is_active' => true]);
        $this->other = Store::create(['city' => 'Bandung', 'address' => 'Jl. Lain 2', 'name' => 'Toko Lain', 'is_active' => true]);
        $this->vehicle = Vehicle::create(['brand' => 'Toyota', 'model' => 'Raize', 'size_category' => 'M']);
        $this->product = FilmProduct::create([
            'sku' => 'WF-D1', 'name' => 'Produk D1', 'product_type' => 'window_film',
            'position' => 'front', 'base_price' => 100_000, 'is_active' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'vehicle_id' => $this->vehicle->id,
            'customer_name' => 'Budi',
            'customer_phone' => '081234567890',
            'items' => [['film_product_id' => $this->product->id]],
        ], $overrides);
    }

    private function storeOf(string $number): ?int
    {
        return Quotation::where('quotation_number', $number)->firstOrFail()->store_id;
    }

    private function staff(string $role, ?int $storeId): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $storeId]);
        $user->assignRole($role);

        return $user;
    }

    public function test_a_submission_without_a_store_goes_to_ginnva_house(): void
    {
        $number = $this->postJson('/api/quotation/submit', $this->payload())->assertStatus(201)->json('data.quotation_number');

        $this->assertSame($this->house->id, $this->storeOf($number));
    }

    public function test_the_store_sent_by_the_client_is_ignored(): void
    {
        $number = $this->postJson('/api/quotation/submit', $this->payload(['store_id' => $this->other->id]))->assertStatus(201)->json('data.quotation_number');

        $this->assertSame($this->house->id, $this->storeOf($number));
    }

    public function test_a_bad_store_id_from_an_old_client_does_not_fail_the_submission(): void
    {
        $number = $this->postJson('/api/quotation/submit', $this->payload(['store_id' => 999999]))->assertStatus(201)->json('data.quotation_number');

        $this->assertSame($this->house->id, $this->storeOf($number));
    }

    public function test_the_store_name_is_matched_case_insensitively_and_prefers_an_active_store(): void
    {
        $this->house->update(['name' => 'GINNVA HOUSE', 'is_active' => false]);
        $active = Store::create(['city' => 'Jakarta', 'address' => 'Jl. House 9', 'name' => 'ginnva house', 'is_active' => true]);

        $number = $this->postJson('/api/quotation/submit', $this->payload())->assertStatus(201)->json('data.quotation_number');

        $this->assertSame($active->id, $this->storeOf($number));
    }

    public function test_the_target_store_name_is_configurable(): void
    {
        config(['quotation.store_name' => 'Toko Lain']);

        $number = $this->postJson('/api/quotation/submit', $this->payload())->assertStatus(201)->json('data.quotation_number');

        $this->assertSame($this->other->id, $this->storeOf($number));
    }

    public function test_when_the_store_does_not_exist_the_lead_is_still_accepted_without_a_store(): void
    {
        $this->house->delete();

        $number = $this->postJson('/api/quotation/submit', $this->payload())->assertStatus(201)->json('data.quotation_number');

        $this->assertNull($this->storeOf($number));
    }

    public function test_only_ginnva_house_staff_are_emailed_and_other_stores_see_nothing(): void
    {
        Mail::fake();
        $houseStaff = $this->staff('kasir', $this->house->id);
        $otherStaff = $this->staff('kasir', $this->other->id);

        $number = $this->postJson('/api/quotation/submit', $this->payload(['store_id' => $this->other->id]))->assertStatus(201)->json('data.quotation_number');

        Mail::assertSent(NewQuotationMail::class, fn ($m) => $m->hasTo($houseStaff->email));
        Mail::assertNotSent(NewQuotationMail::class, fn ($m) => $m->hasTo($otherStaff->email));

        $id = Quotation::where('quotation_number', $number)->value('id');
        $this->actingAs($otherStaff, 'api')->getJson("/api/staff/quotations/{$id}")->assertStatus(403);
        $this->actingAs($houseStaff, 'api')->getJson("/api/staff/quotations/{$id}")->assertOk();
    }
}
