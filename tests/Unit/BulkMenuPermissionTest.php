<?php

namespace Tests\Unit;

use App\Filament\Resources\UserResource;
use PHPUnit\Framework\TestCase;

/**
 * Pencentangan massal Lihat/Buat/Ubah/Hapus di form Akses Menu: hanya untuk modul yang menunya sudah menyala, Void tidak tersentuh,
 * dan tombol per aksi bekerja sebagai sakelar.
 */
class BulkMenuPermissionTest extends TestCase
{
    public function test_check_all_adds_the_four_actions_to_every_enabled_module_and_keeps_void(): void
    {
        $result = UserResource::bulkPermissionChange(['BookingResource' => ['void'], 'StoreResource' => []], 'all');

        $this->assertEqualsCanonicalizing(['void', 'view', 'create', 'update', 'delete'], $result['BookingResource']);
        $this->assertEqualsCanonicalizing(['view', 'create', 'update', 'delete'], $result['StoreResource']);
    }

    public function test_clear_removes_the_four_actions_but_not_void(): void
    {
        $result = UserResource::bulkPermissionChange(['BookingResource' => ['view', 'update', 'void'], 'StoreResource' => ['delete']], 'none');

        $this->assertSame(['void'], array_values($result['BookingResource']));
        $this->assertSame([], $result['StoreResource']);
    }

    public function test_a_single_action_button_is_a_toggle_across_the_enabled_modules(): void
    {
        $partial = ['A' => ['view'], 'B' => []];

        $on = UserResource::bulkPermissionChange($partial, 'view');
        $this->assertSame(['view'], $on['A'], 'Tidak digandakan.');
        $this->assertSame(['view'], $on['B'], 'Ditambahkan ke yang belum punya.');

        $off = UserResource::bulkPermissionChange($on, 'view');
        $this->assertSame([], $off['A']);
        $this->assertSame([], $off['B'], 'Kalau sudah tercentang di semua, dilepas dari semua.');
    }

    public function test_other_actions_are_left_alone_by_a_single_action_button(): void
    {
        $result = UserResource::bulkPermissionChange(['A' => ['update'], 'B' => ['delete']], 'create');

        $this->assertEqualsCanonicalizing(['update', 'create'], $result['A']);
        $this->assertEqualsCanonicalizing(['delete', 'create'], $result['B']);
    }

    public function test_no_enabled_modules_means_nothing_changes(): void
    {
        $this->assertSame([], UserResource::bulkPermissionChange([], 'all'));
        $this->assertSame([], UserResource::bulkPermissionChange([], 'view'));
    }
}
