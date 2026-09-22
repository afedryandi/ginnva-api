<?php

namespace Tests\Feature;

use App\Models\EmployeeCareerHistory;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit Majoo f57 ("Perpindahan Karyawan + Riwayat Karir"), dibangun
 * 2026-09-22. Fokus test: baris riwayat cuma tercatat saat store_id
 * BERUBAH (bukan create baru, bukan update kolom lain), dan menyertakan
 * alasan kalau diisi via $pendingTransferReason.
 */
class EmployeeCareerHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_transfer_creates_history_row(): void
    {
        $storeA = Store::create(['name' => 'Toko A', 'is_active' => true]);
        $storeB = Store::create(['name' => 'Toko B', 'is_active' => true]);
        $user = User::create(['name' => 'Budi', 'email' => 'budi@test.local', 'password' => 'x', 'store_id' => $storeA->id]);

        $user->pendingTransferReason = 'Restrukturisasi toko';
        $user->update(['store_id' => $storeB->id]);

        $this->assertDatabaseHas('employee_career_histories', [
            'user_id' => $user->id,
            'previous_store_id' => $storeA->id,
            'new_store_id' => $storeB->id,
            'reason' => 'Restrukturisasi toko',
        ]);
    }

    public function test_no_history_row_when_store_id_unchanged(): void
    {
        $store = Store::create(['name' => 'Toko A', 'is_active' => true]);
        $user = User::create(['name' => 'Budi', 'email' => 'budi2@test.local', 'password' => 'x', 'store_id' => $store->id]);

        $user->update(['name' => 'Budi Santoso']); // kolom lain, bukan store_id

        $this->assertEquals(0, EmployeeCareerHistory::where('user_id', $user->id)->count());
    }

    public function test_no_history_row_created_on_initial_creation(): void
    {
        $store = Store::create(['name' => 'Toko A', 'is_active' => true]);

        $user = User::create(['name' => 'Budi', 'email' => 'budi3@test.local', 'password' => 'x', 'store_id' => $store->id]);

        $this->assertEquals(0, EmployeeCareerHistory::where('user_id', $user->id)->count());
    }
}
