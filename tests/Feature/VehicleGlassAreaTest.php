<?php

namespace Tests\Feature;

use App\Filament\Resources\VehicleGlassAreaResource\Pages\CreateVehicleGlassArea;
use App\Filament\Resources\VehicleGlassAreaResource\Pages\EditVehicleGlassArea;
use App\Filament\Resources\VehicleGlassAreaResource\Pages\ListVehicleGlassAreas;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGlassArea;
use App\Services\GlassAreaImportService;
use App\Services\GoogleSheetsService;
use App\Services\PricelistDataService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Master Data Fase 2 (2026-10-21): luas kaca di database. Impor dari Google Sheet dengan pratinjau (baru, sama, berubah, bermasalah,
 * ganda, tanpa kendaraan di katalog), tanpa menimpa tanpa izin; daftar harga membaca database di balik bendera dengan hasil yang
 * sama persis seperti dari Sheet.
 */
class VehicleGlassAreaTest extends TestCase
{
    use RefreshDatabase;

    /** Isi sheet "Data Ukuran" contoh: merek, tipe, depan, samping, belakang, sunroof. */
    private array $sheetRows = [
        ['Honda', 'Brio', 0.9, 1.6, 0.7, 0],
        ['Toyota', 'Avanza', 1.0, '1,8', 0.8, 0],
        ['HONDA', 'brio  ', 0.9, 1.6, 0.7, 0],         // ganda (huruf berbeda, nilai sama)
        ['Mazda', 'CX-5', 1.2, 2.1, 0.9, 0.5],
        ['Kia', '', 1.0, 1.0, 1.0, 0],                 // tipe kosong
        ['BMW', 'X5', 'abc', 2.0, 1.0, 0],             // bukan angka
        ['Suzuki', 'Ertiga', 0, 0, 0, 0],              // semua nol
        [],                                            // baris kosong
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Vehicle::query()->delete();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        config(['features.pricelist_from_database' => false]);
        parent::tearDown();
    }

    private function fakeSheet(array $rows): void
    {
        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getValues')->with('Data Ukuran!A2:F2000')->andReturn($rows);
        $this->app->instance(GoogleSheetsService::class, $mock);
    }

    private function admin(): User
    {
        return tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin'));
    }

    public function test_analysis_classifies_rows_and_reports_problems(): void
    {
        Vehicle::create(['brand' => 'Honda', 'model' => 'Brio', 'size_category' => 'S']);
        Vehicle::create(['brand' => 'Toyota', 'model' => 'Avanza', 'size_category' => 'M']);
        $this->fakeSheet($this->sheetRows);

        $result = app(GlassAreaImportService::class)->analyze($this->sheetRows);
        $s = $result['summary'];

        $this->assertSame(7, $s['total_rows'], 'Baris kosong tidak dihitung.');
        $this->assertSame(3, $s['valid']);
        $this->assertSame(3, $s['new']);
        $this->assertSame(3, $s['invalid'], 'Tipe kosong, bukan angka, dan semua nol.');
        $this->assertSame(1, $s['duplicates']);
        $this->assertSame(1, $s['without_vehicle'], 'Hanya Mazda CX-5 yang belum ada di katalog.');
        $this->assertCount(4, $result['problems']);
        $this->assertEquals(1.8, collect($result['entries'])->firstWhere('model', 'Avanza')['values']['side_sqm'], 'Koma desimal diterima.');
    }

    public function test_import_creates_rows_with_normalised_keys_and_is_idempotent(): void
    {
        $service = app(GlassAreaImportService::class);
        $entries = $service->analyze($this->sheetRows)['entries'];

        $this->assertSame(['created' => 3, 'updated' => 0, 'skipped' => 0], $service->import($entries, false));
        $this->assertSame(3, VehicleGlassArea::count());
        $this->assertSame('import', VehicleGlassArea::first()->source);

        $again = $service->analyze($this->sheetRows);
        $this->assertSame(3, $again['summary']['unchanged']);
        $this->assertSame(['created' => 0, 'updated' => 0, 'skipped' => 3], $service->import($again['entries'], true));
        $this->assertSame(3, VehicleGlassArea::count());
    }

    public function test_changed_rows_are_updated_only_with_permission(): void
    {
        $service = app(GlassAreaImportService::class);
        $service->import($service->analyze($this->sheetRows)['entries'], false);

        $changed = $this->sheetRows;
        $changed[0] = ['Honda', 'Brio', 1.1, 1.6, 0.7, 0];
        $analysis = $service->analyze($changed);

        $this->assertSame(1, $analysis['summary']['changed']);

        $this->assertSame(['created' => 0, 'updated' => 0, 'skipped' => 3], $service->import($analysis['entries'], false));
        $this->assertEquals(0.9, (float) VehicleGlassArea::where('brand_key', 'honda')->first()->front_sqm, 'Tanpa izin tidak ditimpa.');

        $this->assertSame(['created' => 0, 'updated' => 1, 'skipped' => 2], $service->import($analysis['entries'], true));
        $this->assertEquals(1.1, (float) VehicleGlassArea::where('brand_key', 'honda')->first()->front_sqm);
    }

    public function test_keys_are_normalised_on_save_so_case_and_spaces_cannot_duplicate(): void
    {
        VehicleGlassArea::create(['brand' => 'Honda', 'model' => 'Brio', 'front_sqm' => 1]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        VehicleGlassArea::create(['brand' => ' HONDA ', 'model' => 'brio', 'front_sqm' => 2]);
    }

    public function test_pricelist_gives_identical_answers_from_the_sheet_and_from_the_database(): void
    {
        $this->fakeSheet($this->sheetRows);
        $service = app(GlassAreaImportService::class);
        $service->import($service->analyze($this->sheetRows)['entries'], false);

        $fromSheet = [];
        config(['features.pricelist_from_database' => false]);
        $sheetService = app(PricelistDataService::class);
        $fromSheet = [
            'brands' => $sheetService->getBrands(),
            'models' => $sheetService->getModels('Honda'),
            'sqm' => $sheetService->getCarSqm('Toyota', 'Avanza'),
        ];

        Cache::flush();
        config(['features.pricelist_from_database' => true]);
        $dbService = app(PricelistDataService::class);

        // Sheet berisi baris bermasalah (mis. "Kia", "BMW") yang tidak diimpor; bandingkan hanya yang valid.
        $this->assertEqualsCanonicalizing(['Honda', 'Mazda', 'Toyota'], array_values(array_unique(array_map(fn ($b) => ucfirst(strtolower($b)), $dbService->getBrands()))));
        $this->assertSame(['Brio'], $dbService->getModels('honda'));
        $this->assertEquals($fromSheet['sqm'], $dbService->getCarSqm('Toyota', 'Avanza'));
        $this->assertSame(['sqmDepan' => 1.0, 'sqmSamping' => 1.8, 'sqmBelakang' => 0.8, 'sqmSunroof' => 0.0], $dbService->getCarSqm('toyota', ' AVANZA '));
        $this->assertNull($dbService->getCarSqm('Honda', 'Tidak Ada'));
    }

    public function test_the_flag_off_keeps_reading_the_sheet(): void
    {
        $this->fakeSheet($this->sheetRows);
        config(['features.pricelist_from_database' => false]);

        $this->assertContains('Suzuki', app(PricelistDataService::class)->getBrands(), 'Sheet dibaca apa adanya, termasuk baris yang tidak diimpor.');
    }

    public function test_the_resource_pages_render_and_creating_a_duplicate_is_refused(): void
    {
        $this->actingAs($this->admin(), 'web');
        VehicleGlassArea::create(['brand' => 'Honda', 'model' => 'Brio', 'front_sqm' => 1]);

        Livewire::test(ListVehicleGlassAreas::class)->assertSuccessful()->assertSee('Honda')->assertSee('Impor dari Google Sheet');
        Livewire::test(CreateVehicleGlassArea::class)->assertSuccessful();

        Livewire::test(CreateVehicleGlassArea::class)
            ->fillForm(['brand' => 'HONDA', 'model' => ' brio', 'front_sqm' => 2, 'side_sqm' => 0, 'rear_sqm' => 0, 'sunroof_sqm' => 0])
            ->call('create')
            ->assertNotified('Luas kaca sudah terdaftar');
        $this->assertSame(1, VehicleGlassArea::count());

        $area = VehicleGlassArea::first();
        Livewire::test(EditVehicleGlassArea::class, ['record' => $area->getRouteKey()])->assertSuccessful();
    }

    public function test_the_import_action_imports_from_the_sheet_and_only_full_access_sees_it(): void
    {
        $this->fakeSheet($this->sheetRows);
        $this->actingAs($this->admin(), 'web');

        Livewire::test(ListVehicleGlassAreas::class)
            ->callAction('import_from_sheet', data: ['overwrite' => false])
            ->assertNotified('Impor selesai');
        $this->assertSame(3, VehicleGlassArea::count());

        $kasir = tap(User::create(['name' => 'Kasir', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('kasir'));
        $this->actingAs($kasir, 'web');
        Livewire::test(ListVehicleGlassAreas::class)->assertActionHidden('import_from_sheet');
    }

    public function test_an_unreachable_sheet_fails_with_a_clear_notification_and_saves_nothing(): void
    {
        $mock = Mockery::mock(GoogleSheetsService::class);
        $mock->shouldReceive('getValues')->andThrow(new \RuntimeException('HTTP 403'));
        $this->app->instance(GoogleSheetsService::class, $mock);
        $this->actingAs($this->admin(), 'web');

        Livewire::test(ListVehicleGlassAreas::class)
            ->callAction('import_from_sheet', data: ['overwrite' => false])
            ->assertNotified('Impor gagal');
        $this->assertSame(0, VehicleGlassArea::count());
    }
}
