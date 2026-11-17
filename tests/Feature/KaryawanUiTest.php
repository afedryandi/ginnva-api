<?php

namespace Tests\Feature;

use App\Filament\Resources\AttendanceCorrectionRequestResource\Pages\ListAttendanceCorrectionRequests;
use App\Filament\Resources\AttendanceResource\Pages\ListAttendances;
use App\Filament\Resources\FieldDutyRequestResource\Pages\ListFieldDutyRequests;
use App\Filament\Resources\LeaveRequestResource\Pages\ListLeaveRequests;
use App\Filament\Resources\OvertimeRequestResource\Pages\ListOvertimeRequests;
use App\Filament\Resources\TechnicianResource\Pages\ListTechnicians;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Store;
use App\Models\User;
use App\Support\CleanUi;
use Filament\Facades\Filament;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Perombakan tampilan menu Karyawan & Absensi: gaya bersih, filter lipat, tab status, nama pengguna dengan email sebagai keterangan. */
class KaryawanUiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Http::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x', 'store_id' => $store->id, 'is_active' => true]);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        return $admin;
    }

    public function test_the_clean_ui_applies_to_every_redesigned_employee_screen(): void
    {
        foreach ([
            new ListAttendances(), new ListLeaveRequests(), new ListOvertimeRequests(), new ListFieldDutyRequests(),
            new ListAttendanceCorrectionRequests(), new ListUsers(), new ListTechnicians(),
        ] as $page) {
            $this->assertTrue(CleanUi::appliesTo($page), $page::class);
        }
    }

    public function test_filters_are_folded_above_the_list_on_the_employee_screens(): void
    {
        $this->admin();

        foreach ([ListAttendances::class, ListLeaveRequests::class, ListUsers::class, ListTechnicians::class] as $list) {
            $table = Livewire::test($list)->assertSuccessful()->instance()->getTable();

            $this->assertSame(FiltersLayout::AboveContentCollapsible, $table->getFiltersLayout(), $list);
        }
    }

    public function test_employee_lists_open_on_all_rows_and_have_status_tabs(): void
    {
        $this->admin();

        foreach ([
            ListAttendances::class => ['semua', 'telat', 'alpha', 'manual'],
            ListLeaveRequests::class => ['semua', 'menunggu', 'disetujui', 'ditolak'],
            ListUsers::class => ['semua', 'aktif', 'nonaktif', 'kontrak'],
            ListTechnicians::class => ['semua', 'aktif', 'review', 'nonaktif'],
        ] as $list => $keys) {
            $page = Livewire::test($list)->assertSuccessful()->instance();

            $this->assertSame('semua', $page->getDefaultActiveTab(), $list);
            $this->assertSame($keys, array_keys($page->getTabs()), $list);
        }
    }

    public function test_the_user_list_shows_name_with_email_and_still_searches_by_email(): void
    {
        $admin = $this->admin();
        $other = User::create(['name' => 'Budi Santoso', 'email' => 'budi.s@test.local', 'password' => 'x', 'store_id' => $admin->store_id, 'is_active' => true]);

        Livewire::test(ListUsers::class)
            ->assertSee('budi.s@test.local')
            ->searchTable('budi.s@test')
            ->assertCanSeeTableRecords([$other])
            ->assertCanNotSeeTableRecords([$admin]);
    }
}
