<?php

namespace Tests\Feature;

use App\Filament\Pages\NotificationCatalogPage;
use App\Models\User;
use App\Support\NotificationCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Notifikasi Fase 0 (2026-10-21): Katalog Notifikasi. Tes pengaman terpenting: setiap berkas di app/ yang memanggil
 * PushNotificationService::sendTo*() harus terdaftar di NotificationCatalog, supaya pengirim baru tidak lolos tanpa dicatat.
 */
class NotificationCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const IGNORED_SOURCES = ['Services/PushNotificationService.php', 'Services/StaffNotifier.php'];

    /** @return list<string> berkas (relatif ke app/) yang memanggil sendTo*() di kode (komentar dan definisi fungsi diabaikan) */
    private function scanCallers(): array
    {
        $found = [];
        $pattern = '/(sendToStoreStaff|sendToUsers|sendToBookingWatchers|sendToCustomer)\(|StaffNotifier::class\)->(toStoreStaff|toUsers|toBookingWatchers)\(/';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));

            if (in_array($relative, self::IGNORED_SOURCES, true)) {
                continue;
            }

            foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $line) {
                $trimmed = ltrim($line);

                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*') || str_contains($line, ' function ')) {
                    continue;
                }

                if (preg_match($pattern, $line)) {
                    $found[$relative] = true;
                }
            }
        }

        return array_keys($found);
    }

    public function test_every_file_that_sends_a_push_is_listed_in_the_catalog(): void
    {
        $catalogSources = collect(NotificationCatalog::all())->pluck('source')->unique()->values()->all();
        $callers = $this->scanCallers();

        $this->assertNotEmpty($callers);
        $this->assertSame([], array_values(array_diff($callers, $catalogSources)), 'Berkas pengirim push yang belum didaftarkan di App\\Support\\NotificationCatalog.');
        $this->assertSame([], array_values(array_diff($catalogSources, $callers)), 'Berkas di katalog yang tidak lagi mengirim push (hapus dari katalog).');
    }

    public function test_catalog_entries_are_well_formed_and_not_duplicated(): void
    {
        $entries = NotificationCatalog::all();
        $keys = [];

        foreach ($entries as $entry) {
            $this->assertContains($entry['audience'], array_keys(NotificationCatalogPage::AUDIENCE_LABELS));
            $this->assertNotSame('', trim($entry['title']));
            $this->assertFileExists(app_path($entry['source']));
            $this->assertIsBool($entry['panel_inbox']);

            $key = $entry['audience'] . '|' . $entry['source'] . '|' . $entry['title'];
            $this->assertNotContains($key, $keys, 'Entri ganda: ' . $key);
            $keys[] = $key;
        }
    }

    public function test_the_page_shows_the_summary_and_search_filters_the_list(): void
    {
        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin')), 'web');

        $component = Livewire::test(NotificationCatalogPage::class)
            ->assertSuccessful()
            ->assertSee('Titik pengiriman')
            ->assertSee('Push saja')
            ->assertSee('Booking Dikonfirmasi');

        $summary = $component->instance()->getSummary();
        $this->assertSame(count(NotificationCatalog::all()), $summary['total']);
        $this->assertSame($summary['customer'] + $summary['staff'], $summary['total']);
        $this->assertGreaterThan(0, $summary['staff_push_only'], 'Temuan N1: ada pengirim staf yang hanya push.');

        $component->set('search', 'Gaji Sudah Dibayar')->assertSee('Gaji Sudah Dibayar')->assertDontSee('Booking Dikonfirmasi');
        $component->set('search', 'tidak-ada-yang-cocok-xyz')->assertSee('Tidak ada yang cocok');
    }
}
