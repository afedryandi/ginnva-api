<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\DemoDataGuard;
use App\Models\Booking;
use App\Models\BookingIntakePhoto;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Store;
use App\Models\User;
use App\Services\BookingReminderService;
use App\Services\BookingServiceLevelService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Alur booking end-to-end lewat HTTP seperti aplikasi mobile (pasangan smoke:api yang hanya membaca): pelanggan memesan
 * (booking v2, persetujuan privasi, kunci idempotensi), Store Manager mengonfirmasi, memotret penerimaan, memeriksa QC,
 * mencatat serah terima, menyelesaikan, menandai tidak datang, pengingat, dan laporan SLA. Membuat data bertanda [SMOKE]
 * (pelanggan, kendaraan, booking) dan MEMBERSIHKANNYA di akhir (kecuali --keep). Hanya server dev/test.
 *
 * Langkah yang di sini disiapkan langsung ke database (mis. menandai tahap pengerjaan selesai) diberi label "(siapan)":
 * itu bukan hal yang diuji, hanya mempersingkat alur.
 */
class SmokeFlow extends Command
{
    use DemoDataGuard;

    protected $signature = 'smoke:flow {--keep : Jangan hapus data [SMOKE] di akhir}';

    protected $description = 'Jalankan alur booking end-to-end (pelanggan -> staf -> QC -> serah terima) lewat HTTP dan laporkan yang gagal (hanya server dev)';

    /** PNG 1x1 yang valid untuk unggahan foto. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private int $passed = 0;

    /** @var array<int, string> */
    private array $failures = [];

    private ?User $manager = null;

    private ?Customer $customer = null;

    /** @var array<int, int> */
    private array $bookingIds = [];

    public function handle(): int
    {
        if ($this->refuseOutsideDemoEnvironment()) {
            return self::FAILURE;
        }

        // Tanpa penghitung batas laju yang menetap, dan pesan error asli di respons JSON.
        config(['cache.default' => 'array', 'app.debug' => true]);

        $this->manager = User::role('store_manager')->where('is_active', true)->whereNotNull('store_id')->orderBy('id')->first();
        $store = $this->manager ? Store::where('is_active', true)->find($this->manager->store_id) : null;

        if (! $store) {
            $this->error('Butuh minimal satu Store Manager aktif yang terikat ke toko aktif. Dibatalkan.');

            return self::FAILURE;
        }

        $heavyDate = $this->findHeavyDate($store);
        [$lightDate, $slot] = $this->findLightSlot($store);

        if (! $heavyDate || ! $lightDate) {
            $this->error("Tidak ada tanggal kosong dalam 20 hari ke depan di toko {$store->name} (berat: " . ($heavyDate ?: 'tidak ada') . ', ringan: ' . ($lightDate ?: 'tidak ada') . '). Dibatalkan.');

            return self::FAILURE;
        }

        $this->info("Toko {$store->name}; pemeriksa {$this->manager->name}; tanggal berat {$heavyDate}; tanggal ringan {$lightDate} jam {$slot}");

        $this->customer = Customer::create(['name' => '[SMOKE] Uji Alur', 'phone_number' => '0899' . random_int(1000000, 9999999)]);

        try {
            $this->run_flow($store, $heavyDate, $lightDate, (string) $slot);
        } catch (Throwable $e) {
            $this->markFail('Alur berhenti karena exception tak terduga', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        } finally {
            if ($this->option('keep')) {
                $this->comment('Data [SMOKE] dibiarkan (--keep): pelanggan #' . $this->customer->id);
            } else {
                $this->cleanup();
            }
        }

        $this->newLine();
        $this->info("Selesai: {$this->passed} langkah lolos, " . count($this->failures) . ' gagal.');

        if ($this->failures) {
            $this->error('Yang gagal:');
            foreach ($this->failures as $failure) {
                $this->line("  - {$failure}");
            }

            return self::FAILURE;
        }

        $this->info('Seluruh alur lolos.');

        return self::SUCCESS;
    }

    private function run_flow(Store $store, string $heavyDate, string $lightDate, string $slot): void
    {
        $customer = $this->customer;
        $manager = $this->manager;

        $this->section('Publik');
        $r = $this->httpCall('GET', '/api/booking/services');
        $this->assertResponse('Katalog layanan memuat kebijakan batal dan pemberitahuan privasi', $r, 200, fn ($j) => filled($j['data']['cancellation_policy'] ?? null) && filled($j['data']['privacy_notice']['version'] ?? null) && count($j['data']['services'] ?? []) >= 4);
        $r = $this->httpCall('GET', "/api/stores/{$store->id}/light-slots?date={$lightDate}");
        $this->assertResponse('Slot jam layanan ringan', $r, 200, fn ($j) => count($j['data']['slots'] ?? []) === count(Booking::lightSlots()));
        $this->assertResponse('Tanggal penuh layanan ringan', $this->httpCall('GET', "/api/stores/{$store->id}/light-full-dates"), 200);
        $this->assertResponse('Tanggal penuh dengan durasi PPF 3 hari', $this->httpCall('GET', "/api/stores/{$store->id}/full-dates?duration_days=3"), 200);
        $this->assertResponse('Konfigurasi versi aplikasi', $this->httpCall('GET', '/api/app/config'), 200);

        $this->section('Pelanggan');
        $r = $this->httpCall('POST', '/api/customer/vehicles', $customer, 'customer', ['brand' => 'Toyota', 'model' => 'Avanza', 'plate' => 'B ' . random_int(1000, 9999) . ' SMK']);
        $this->assertResponse('Tambah kendaraan', $r, 201);
        $vehicleId = $r[1]['data']['id'] ?? null;

        $base = ['store_id' => $store->id, 'customer_vehicle_id' => $vehicleId];
        $heavy = $base + ['services' => ['ppf'], 'preferred_date' => $heavyDate];

        $this->assertResponse('Booking v2 tanpa persetujuan privasi ditolak', $this->httpCall('POST', '/api/customer/bookings', $customer, 'customer', $heavy), 422);

        $key = 'smoke-' . bin2hex(random_bytes(6));
        $r = $this->httpCall('POST', '/api/customer/bookings', $customer, 'customer', $heavy + ['privacy_consent' => true], [], ['Idempotency-Key' => $key]);
        $this->assertResponse('Booking PPF (berat) dengan persetujuan', $r, 201, fn ($j) => filled($j['data']['consent_at'] ?? null));
        $heavyId = $r[1]['data']['id'] ?? null;
        $this->track($heavyId);

        $again = $this->httpCall('POST', '/api/customer/bookings', $customer, 'customer', $heavy + ['privacy_consent' => true], [], ['Idempotency-Key' => $key]);
        $this->assertResponse('Kiriman ulang dengan kunci sama mengembalikan booking yang sama', $again, 201, fn ($j) => ($j['data']['id'] ?? null) === $heavyId);
        $this->assertThat('Header Idempotent-Replayed terkirim', ($again[2]['idempotent-replayed'][0] ?? null) === 'true');

        $light = $base + ['services' => ['premium_wash'], 'preferred_date' => $lightDate, 'privacy_consent' => true];
        $this->assertResponse('Layanan ringan tanpa jam ditolak', $this->httpCall('POST', '/api/customer/bookings', $customer, 'customer', $light), 422);
        $r = $this->httpCall('POST', '/api/customer/bookings', $customer, 'customer', $light + ['preferred_time' => $slot]);
        $this->assertResponse('Booking Premium Wash (ringan) dengan jam', $r, 201);
        $lightId = $r[1]['data']['id'] ?? null;
        $this->track($lightId);

        $r = $this->httpCall('GET', '/api/customer/bookings', $customer, 'customer');
        $this->assertResponse('Daftar booking pelanggan tidak membocorkan penanda internal', $r, 200, fn ($j) => collect($j['data'] ?? [])->every(fn ($b) => ! array_key_exists('h2_reminder_sent_at', $b) && ! array_key_exists('dayof_reminder_sent_at', $b)));

        if (! $heavyId || ! $lightId) {
            $this->markFail('Booking tidak terbentuk', 'langkah staf dilewati');

            return;
        }

        $this->section('Staf: konfirmasi dan penerimaan');
        $r = $this->httpCall('GET', "/api/staff/bookings/capacity-overview?store_id={$store->id}&days=7", $manager, 'api');
        $this->assertResponse('Ringkasan kapasitas memuat slot ringan dan installer', $r, 200, fn ($j) => isset($j['data'][0]['light_slots']) && isset($j['data'][0]['installers']) || ($j['data'][0]['closed'] ?? false));

        $r = $this->httpCall('POST', "/api/staff/bookings/{$heavyId}/confirm", $manager, 'api');
        $this->assertResponse('Konfirmasi booking berat', $r, 200, fn ($j) => ($j['data']['status'] ?? null) === 'confirmed' && is_array($j['warnings'] ?? null));
        $this->assertResponse('Konfirmasi booking ringan', $this->httpCall('POST', "/api/staff/bookings/{$lightId}/confirm", $manager, 'api'), 200);

        $this->assertResponse('Foto penerimaan awal belum lengkap', $this->httpCall('GET', "/api/staff/bookings/{$heavyId}/intake-photos", $manager, 'api'), 200, fn ($j) => $j['data']['complete'] === false);
        foreach (array_keys(BookingIntakePhoto::ANGLES) as $angle) {
            $this->assertResponse("Foto sudut {$angle}", $this->httpCall('POST', "/api/staff/bookings/{$heavyId}/intake-photos", $manager, 'api', ['angle' => $angle], ['photo' => $this->png()]), 201);
        }
        $this->assertResponse('Foto penerimaan lengkap', $this->httpCall('GET', "/api/staff/bookings/{$heavyId}/intake-photos", $manager, 'api'), 200, fn ($j) => $j['data']['complete'] === true);
        $this->assertResponse('Foto kerusakan tanpa keterangan ditolak', $this->httpCall('POST', "/api/staff/bookings/{$heavyId}/intake-photos", $manager, 'api', ['angle' => 'kerusakan'], ['photo' => $this->png()]), 422);
        $this->assertResponse('Foto kerusakan dengan keterangan', $this->httpCall('POST', "/api/staff/bookings/{$heavyId}/intake-photos", $manager, 'api', ['angle' => 'kerusakan', 'note' => 'Baret uji'], ['photo' => $this->png()]), 201);

        $this->section('Staf: QC, serah terima, selesai');
        $this->assertResponse('QC belum boleh sebelum tahap pengerjaan selesai', $this->httpCall('GET', "/api/staff/bookings/{$heavyId}/qc", $manager, 'api'), 200, fn ($j) => $j['data']['can_inspect'] === false);

        DB::table('bookings')->where('id', $heavyId)->update(['current_stage' => 'ppf_installation']); // siapan
        $qc = $this->httpCall('GET', "/api/staff/bookings/{$heavyId}/qc", $manager, 'api');
        $this->assertResponse('QC boleh setelah tahap produk selesai (siapan)', $qc, 200, fn ($j) => $j['data']['can_inspect'] === true && ! empty($j['data']['services'][0]['items']));

        $items = collect($qc[1]['data']['services'] ?? [])->flatMap(fn ($s) => $s['items'])->values();
        $failingNoNote = $items->map(fn ($i, $n) => ['item_id' => $i['id'], 'passed' => $n > 0])->all();
        $this->assertResponse('Butir tidak lulus tanpa catatan ditolak', $this->httpCall('POST', "/api/staff/bookings/{$heavyId}/qc", $manager, 'api', ['results' => $failingNoNote]), 422);
        $allPass = $items->map(fn ($i) => ['item_id' => $i['id'], 'passed' => true])->all();
        $this->assertResponse('QC lulus', $this->httpCall('POST', "/api/staff/bookings/{$heavyId}/qc", $manager, 'api', ['results' => $allPass]), 201, fn ($j) => $j['data']['passed'] === true && filled($j['data']['ready_at'] ?? null));

        $r = $this->httpCall('GET', '/api/customer/bookings', $customer, 'customer');
        $this->assertResponse('Pelanggan melihat "siap diambil" (ready_at)', $r, 200, fn ($j) => filled(collect($j['data'] ?? [])->firstWhere('id', $heavyId)['ready_at'] ?? null));
        $this->assertThat('Pelanggan mendapat notifikasi "Kendaraan Siap Diambil"', CustomerNotification::where('customer_id', $customer->id)->where('title', 'Kendaraan Siap Diambil')->exists());

        $this->assertResponse('Serah terima PPF tanpa tanda tangan basah ditolak', $this->httpCall('POST', "/api/staff/bookings/{$heavyId}/handover", $manager, 'api', ['care_instructions_given' => true, 'wet_signature_received' => false]), 422);
        $this->assertResponse('Serah terima PPF dengan tanda tangan basah', $this->httpCall('POST', "/api/staff/bookings/{$heavyId}/handover", $manager, 'api', ['care_instructions_given' => true, 'wet_signature_received' => true]), 201);

        DB::table('bookings')->where('id', $heavyId)->update(['current_stage' => 'qc']); // siapan
        $this->assertResponse('Selesaikan booking berat', $this->httpCall('POST', "/api/staff/bookings/{$heavyId}/complete", $manager, 'api'), 200, fn ($j) => ($j['data']['status'] ?? null) === 'completed');

        $this->assertResponse('Serah terima layanan ringan tanpa tanda tangan', $this->httpCall('POST', "/api/staff/bookings/{$lightId}/handover", $manager, 'api', ['care_instructions_given' => true, 'wet_signature_received' => false]), 201);
        DB::table('bookings')->where('id', $lightId)->update(['current_stage' => 'qc']); // siapan
        $this->assertResponse('Selesaikan booking ringan', $this->httpCall('POST', "/api/staff/bookings/{$lightId}/complete", $manager, 'api'), 200, fn ($j) => ($j['data']['status'] ?? null) === 'completed');

        $installer = User::role('installer')->where('is_active', true)->where('store_id', $store->id)->orderBy('id')->first();
        if ($installer) {
            $this->assertResponse('Installer biasa tidak boleh memeriksa QC', $this->httpCall('POST', "/api/staff/bookings/{$heavyId}/qc", $installer, 'api', ['results' => $allPass]), 403);
        } else {
            $this->comment('  (dilewati) tidak ada installer di toko ini untuk uji penolakan akses');
        }

        $this->section('Tidak datang');
        $noShow = Booking::create([
            'booking_number' => 'SMOKE-' . strtoupper(bin2hex(random_bytes(3))),
            'customer_id' => $customer->id, 'store_id' => $store->id, 'service_type' => 'Premium Wash', 'product_premium_wash' => true,
            'preferred_date' => today()->toDateString(), 'preferred_time' => $slot, 'status' => 'confirmed', // siapan
        ]);
        $this->track($noShow->id);
        $this->assertResponse('Tandai pelanggan tidak datang', $this->httpCall('POST', "/api/staff/bookings/{$noShow->id}/no-show", $manager, 'api'), 200);
        $fresh = $noShow->fresh();
        $this->assertThat('Booking menjadi dibatalkan dengan penanda no_show_at', $fresh->status === 'cancelled' && $fresh->no_show_at !== null);
        $this->assertResponse('Tandai tidak datang kedua kali ditolak', $this->httpCall('POST', "/api/staff/bookings/{$noShow->id}/no-show", $manager, 'api'), 422);

        $this->section('Pengingat dan laporan');
        $reminders = app(BookingReminderService::class);
        $target = Booking::find($lightId);
        DB::table('bookings')->where('id', $lightId)->update(['status' => 'confirmed', 'h1_reminder_sent_at' => null, 'h2_reminder_sent_at' => null, 'dayof_reminder_sent_at' => null]); // siapan
        $target = Booking::find($lightId);
        $this->assertThat('Pengingat H-2 terkirim', $reminders->sendH2($target));
        $this->assertThat('Pengingat H-1 terkirim', $reminders->sendH1(Booking::find($lightId)));
        $this->assertThat('Pengingat hari-H terkirim', $reminders->sendDayOf(Booking::find($lightId)));
        $h2 = CustomerNotification::where('customer_id', $customer->id)->where('title', 'Pengingat Jadwal 2 Hari Lagi')->first();
        $this->assertThat('Pengingat H-2 menyebut toko dan membuka jadwal ulang', $h2 && str_contains($h2->body, $store->name) && ($h2->data['params']['reschedule'] ?? null) === (string) $lightId);

        $summary = app(BookingServiceLevelService::class)->summarize(today()->subDay(), today()->addDay(), $store->id);
        $this->assertThat('Laporan SLA menghitung booking uji', $summary['created'] >= 3 && $summary['no_show'] >= 1);
    }

    // ---------------------------------------------------------------- pembantu

    private function findHeavyDate(Store $store): ?string
    {
        for ($d = 3; $d <= 20; $d++) {
            $day = today()->addDays($d);

            if ($store->isClosedOn($day)) {
                continue;
            }

            if (Booking::confirmedOverlapCount($store->id, $day) >= Booking::capacityForDate($store->id, $day)) {
                continue;
            }

            if (empty(Booking::fullDatesInRange($store->id, $day, 3))) {
                return $day->toDateString();
            }
        }

        return null;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function findLightSlot(Store $store): array
    {
        for ($d = 1; $d <= 20; $d++) {
            $day = today()->addDays($d);

            if ($store->isClosedOn($day)) {
                continue;
            }

            $open = collect(Booking::lightSlotAvailability($store->id, $day))->firstWhere('available', true);

            if ($open) {
                return [$day->toDateString(), $open['slot']];
            }
        }

        return [null, null];
    }

    private function png(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'smk') . '.png';
        file_put_contents($path, base64_decode(self::PNG));

        return new UploadedFile($path, 'foto.png', 'image/png', null, true);
    }

    private function track(?int $id): void
    {
        if ($id) {
            $this->bookingIds[] = $id;
        }
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->info("== {$title} ==");
    }

    /**
     * @return array{0: int, 1: ?array, 2: array} [status, isi JSON, header]
     */
    private function httpCall(string $method, string $uri, mixed $actor = null, ?string $guard = null, array $data = [], array $files = [], array $headers = []): array
    {
        try {
            if ($actor && $guard) {
                Auth::shouldUse($guard);
                Auth::guard($guard)->setUser($actor);
            }

            $server = ['HTTP_ACCEPT' => 'application/json'];
            foreach ($headers as $name => $value) {
                $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
            }

            if ($method === 'GET') {
                $request = Request::create($uri, 'GET', [], [], [], $server);
            } elseif ($files) {
                $request = Request::create($uri, $method, $data, [], $files, $server);
            } else {
                $request = Request::create($uri, $method, [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], json_encode($data));
            }

            $response = app(HttpKernel::class)->handle($request);

            return [$response->getStatusCode(), json_decode((string) $response->getContent(), true), $response->headers->all()];
        } catch (Throwable $e) {
            return [500, ['message' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()], []];
        }
    }

    /** @param array{0: int, 1: ?array, 2: array} $res */
    private function assertResponse(string $label, array $res, int $status, ?callable $check = null): void
    {
        $ok = $res[0] === $status;
        $detail = $ok ? '' : "status {$res[0]} (diharapkan {$status}): " . mb_substr((string) ($res[1]['message'] ?? json_encode($res[1])), 0, 160);

        if ($ok && $check) {
            try {
                $ok = (bool) $check($res[1] ?? []);
            } catch (Throwable $e) {
                $ok = false;
            }
            $detail = $ok ? '' : 'isi respons tidak sesuai harapan';
        }

        $ok ? $this->markPass($label) : $this->markFail($label, $detail);
    }

    private function assertThat(string $label, mixed $condition): void
    {
        $condition ? $this->markPass($label) : $this->markFail($label, 'kondisi tidak terpenuhi');
    }

    private function markPass(string $label): void
    {
        $this->passed++;
        $this->line("  <info>ok</info>    {$label}");
    }

    private function markFail(string $label, string $detail): void
    {
        $this->failures[] = "{$label} — {$detail}";
        $this->line("  <error>GAGAL</error> {$label} — {$detail}");
    }

    private function cleanup(): void
    {
        try {
            $ids = array_values(array_unique($this->bookingIds));

            foreach (BookingIntakePhoto::whereIn('booking_id', $ids)->get() as $photo) {
                Storage::disk('public')->delete($photo->path);
            }

            Booking::withoutGlobalScopes()->whereIn('id', $ids)->each(fn (Booking $b) => $b->delete());
            CustomerNotification::where('customer_id', $this->customer->id)->delete();
            DB::table('customer_vehicles')->where('customer_id', $this->customer->id)->delete();
            DB::table('idempotency_keys')->where('key', 'like', 'smoke-%')->delete();
            $this->customer->forceDelete();

            $this->comment('Data [SMOKE] dibersihkan.');
        } catch (Throwable $e) {
            $this->warn('Pembersihan data [SMOKE] gagal sebagian: ' . $e->getMessage() . ' (cari pelanggan "[SMOKE] Uji Alur" dan hapus manual).');
        }
    }
}
