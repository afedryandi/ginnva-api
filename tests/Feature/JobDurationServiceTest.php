<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Spk;
use App\Models\Store;
use App\Models\Technician;
use App\Models\User;
use App\Services\JobDurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit Majoo f12 ("laporan durasi pengerjaan per job/teknisi/jenis
 * layanan"), dibangun 2026-09-22. Fokus test: durasi dihitung dari
 * checked_in_at/checked_out_at, jenis layanan diturunkan dari flag
 * produk Booking, dan job tanpa booking tetap muncul (bukan dibuang).
 */
class JobDurationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeStore(): Store
    {
        return Store::create(['name' => 'Toko Test', 'is_active' => true]);
    }

    public function test_job_duration_and_service_labels_from_booking(): void
    {
        $store = $this->makeStore();
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '081200000001']);
        $installer = User::create(['name' => 'Teknisi A', 'email' => 'tech-a@test.local', 'password' => 'x', 'store_id' => $store->id]);

        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => now()->toDateString(),
            'status' => 'completed',
        ]);
        $booking->installers()->attach($installer->id);

        Spk::create([
            'spk_number' => 'SPK-TEST-' . uniqid(),
            'store_id' => $store->id,
            'booking_id' => $booking->id,
            'customer_name' => 'Budi',
            'checked_in_at' => now()->subHours(3),
            'checked_out_at' => now(),
        ]);

        $jobs = app(JobDurationService::class)->jobs(now()->subDay(), now()->addDay());

        $this->assertCount(1, $jobs);
        $this->assertEquals(180, $jobs->first()['minutes']);
        $this->assertEquals(['PPF'], $jobs->first()['services']);
        $this->assertEquals(['Teknisi A'], $jobs->first()['technicians']);
    }

    public function test_job_outside_date_range_excluded(): void
    {
        $store = $this->makeStore();
        $customer = Customer::create(['name' => 'Ani', 'phone_number' => '081200000002']);

        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'Kaca Film',
            'product_kaca_film' => true,
            'preferred_date' => now()->toDateString(),
            'status' => 'completed',
        ]);

        Spk::create([
            'spk_number' => 'SPK-TEST-' . uniqid(),
            'store_id' => $store->id,
            'booking_id' => $booking->id,
            'customer_name' => 'Ani',
            'checked_in_at' => now()->subMonths(2),
            'checked_out_at' => now()->subMonths(2)->addHour(),
        ]);

        $jobs = app(JobDurationService::class)->jobs(now()->subDay(), now()->addDay());

        $this->assertCount(0, $jobs);
    }

    public function test_aggregate_by_service_computes_avg_min_max_and_full_credit_for_combo_jobs(): void
    {
        $store = $this->makeStore();
        $customer = Customer::create(['name' => 'Combo', 'phone_number' => '081200000003']);

        // Job kombo PPF + Detailing, 2 jam -- durasi PENUH masuk ke KEDUA layanan.
        $comboBooking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'PPF+Detailing',
            'product_ppf' => true,
            'product_detailing' => true,
            'preferred_date' => now()->toDateString(),
            'status' => 'completed',
        ]);
        Spk::create([
            'spk_number' => 'SPK-TEST-' . uniqid(),
            'store_id' => $store->id,
            'booking_id' => $comboBooking->id,
            'customer_name' => 'Combo',
            'checked_in_at' => now()->subHours(2),
            'checked_out_at' => now(),
        ]);

        // Job PPF murni, 1 jam.
        $ppfOnlyBooking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => now()->toDateString(),
            'status' => 'completed',
        ]);
        Spk::create([
            'spk_number' => 'SPK-TEST-' . uniqid(),
            'store_id' => $store->id,
            'booking_id' => $ppfOnlyBooking->id,
            'customer_name' => 'Combo',
            'checked_in_at' => now()->subHour(),
            'checked_out_at' => now(),
        ]);

        $rows = app(JobDurationService::class)->aggregateByService(now()->subDay(), now()->addDay())
            ->keyBy('service');

        $this->assertEquals(2, $rows['PPF']['jobCount']);
        $this->assertEquals(90.0, $rows['PPF']['avgMinutes']); // (120 + 60) / 2
        $this->assertEquals(60, $rows['PPF']['minMinutes']);
        $this->assertEquals(120, $rows['PPF']['maxMinutes']);

        $this->assertEquals(1, $rows['Detailing']['jobCount']);
        $this->assertEquals(120.0, $rows['Detailing']['avgMinutes']);
    }
}
