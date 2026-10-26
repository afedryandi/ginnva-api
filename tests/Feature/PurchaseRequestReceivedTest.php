<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequestResource\Pages\ListPurchaseRequests;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\PurchaseRequest;
use App\Models\RawMaterial;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PushNotificationService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Jumlah diterima pada Permohonan Pembelian (audit ACCURATE 5, "Qty Received"): dicatat saat Tandai Terpenuhi, terlihat di daftar
 * sebagai Diterima dan Kurang, bisa difilter, dan tidak mengubah jurnal serta tagihan yang sudah ada. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class PurchaseRequestReceivedTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->mock(PushNotificationService::class)->shouldReceive('sendToUsers')->andReturnNull();
        $this->actingAs($this->admin(), 'web');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]), fn (User $u) => $u->assignRole('super_admin'));
    }

    private function approved(array $extra = []): PurchaseRequest
    {
        $material = RawMaterial::create(['name' => 'Adhesive', 'code' => 'RM-' . uniqid(), 'category' => 'chemical', 'unit' => 'liter', 'current_stock' => 5]);

        return PurchaseRequest::create(array_merge([
            'store_id' => $this->store->id, 'item_type' => 'raw_material', 'item_id' => $material->id, 'item_name' => 'Adhesive', 'unit' => 'liter',
            'quantity' => 5, 'status' => 'approved', 'requested_by' => auth()->id(),
        ], $extra));
    }

    private function fulfill(PurchaseRequest $request, array $data = [])
    {
        $supplier = Supplier::create(['name' => 'PT Kimia Jaya ' . uniqid()]);

        return Livewire::test(ListPurchaseRequests::class)
            ->callTableAction('fulfill', $request, data: array_merge(['actual_cost' => 250000, 'supplier_id' => $supplier->id], $data));
    }

    public function test_the_received_quantity_is_recorded_and_the_shortfall_shown(): void
    {
        $request = $this->approved();

        $this->fulfill($request, ['quantity_received' => 3])->assertHasNoTableActionErrors();

        $fresh = $request->fresh();
        $this->assertSame('fulfilled', $fresh->status);
        $this->assertEquals(3, (float) $fresh->quantity_received);
        $this->assertSame(2.0, $fresh->shortfall());
    }

    public function test_the_form_proposes_the_requested_quantity_when_nothing_is_typed(): void
    {
        $request = $this->approved(['quantity' => 5]);

        $this->fulfill($request)->assertHasNoTableActionErrors();

        $fresh = $request->fresh();
        $this->assertEquals(5, (float) $fresh->quantity_received);
        $this->assertNull($fresh->shortfall());
    }

    public function test_the_received_quantity_must_be_positive(): void
    {
        $request = $this->approved();

        $this->fulfill($request, ['quantity_received' => 0])->assertHasTableActionErrors(['quantity_received']);
        $this->fulfill($request, ['quantity_received' => null])->assertHasTableActionErrors(['quantity_received']);

        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_the_shortfall_does_not_change_the_journal_or_the_bill(): void
    {
        $request = $this->approved();

        $this->fulfill($request, ['quantity_received' => 3, 'actual_cost' => 180000])->assertHasNoTableActionErrors();

        $entry = JournalEntry::with('lines')->findOrFail($request->fresh()->journal_entry_id);
        $inventory = ChartOfAccount::where('code', '1130')->value('id');
        $this->assertEquals(180000, (float) $entry->lines->firstWhere('chart_of_account_id', $inventory)->debit, 'Jurnal memakai biaya aktual yang diisi, bukan jumlah diterima.');
        $this->assertTrue($entry->isBalanced());
    }

    public function test_the_shortfall_helper_covers_every_case(): void
    {
        $this->assertNull((new PurchaseRequest(['quantity' => 5]))->shortfall(), 'Belum tercatat (permohonan lama).');
        $this->assertNull((new PurchaseRequest(['quantity' => 5, 'quantity_received' => 5]))->shortfall(), 'Diterima penuh.');
        $this->assertNull((new PurchaseRequest(['quantity' => 5, 'quantity_received' => 6]))->shortfall(), 'Diterima lebih.');
        $this->assertSame(1.5, (new PurchaseRequest(['quantity' => 5, 'quantity_received' => 3.5]))->shortfall());
    }

    public function test_the_short_received_filter_lists_only_short_requests(): void
    {
        $short = $this->approved(['status' => 'fulfilled', 'quantity' => 5, 'quantity_received' => 3]);
        $full = $this->approved(['status' => 'fulfilled', 'quantity' => 5, 'quantity_received' => 5]);
        $old = $this->approved(['status' => 'fulfilled', 'quantity' => 5]);

        Livewire::test(ListPurchaseRequests::class)
            ->filterTable('short_received')
            ->assertCanSeeTableRecords([$short])
            ->assertCanNotSeeTableRecords([$full, $old]);
    }

    public function test_the_list_shows_received_and_missing_amounts(): void
    {
        $short = $this->approved(['status' => 'fulfilled', 'quantity' => 5, 'quantity_received' => 3]);

        Livewire::test(ListPurchaseRequests::class)
            ->assertTableColumnFormattedStateSet('quantity_received', '3 liter', $short)
            ->assertSee('Kurang 2 liter');
    }
}
