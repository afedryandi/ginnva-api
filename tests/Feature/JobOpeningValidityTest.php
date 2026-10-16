<?php

namespace Tests\Feature;

use App\Models\JobOpening;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Marketing/Konten Fase 4: lowongan punya tanggal terbit dan batas berlaku; yang kedaluwarsa hilang sendiri dari API publik
 * (syarat Google Jobs), sedangkan lowongan lama tanpa batas tetap tayang.
 */
class JobOpeningValidityTest extends TestCase
{
    use RefreshDatabase;

    private function job(array $over = []): JobOpening
    {
        return JobOpening::create($over + [
            'title' => 'Posisi ' . uniqid(), 'department' => 'Operasional', 'location' => 'PIK 2, Tangerang',
            'type' => 'Full-time', 'description' => 'Deskripsi', 'requirements' => [], 'is_published' => true, 'sort_order' => 0,
        ]);
    }

    public function test_expired_jobs_disappear_from_the_public_api_but_the_last_day_still_counts(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');

        $this->job(['title' => 'Tanpa batas']);
        $this->job(['title' => 'Hari terakhir', 'valid_through' => '2026-11-10']);
        $this->job(['title' => 'Masih lama', 'valid_through' => '2026-12-31']);
        $this->job(['title' => 'Sudah lewat', 'valid_through' => '2026-11-09']);
        $this->job(['title' => 'Draft', 'is_published' => false]);

        $titles = collect($this->getJson('/api/job-openings')->assertOk()->json('data'))->pluck('title')->all();

        $this->assertEqualsCanonicalizing(['Tanpa batas', 'Hari terakhir', 'Masih lama'], $titles);

        Carbon::setTestNow();
    }

    public function test_the_api_exposes_posted_and_valid_through_dates_for_the_job_posting_schema(): void
    {
        $job = $this->job(['title' => 'Installer PPF', 'posted_at' => '2026-10-20 08:00:00', 'valid_through' => '2026-12-31']);
        $legacy = $this->job(['title' => 'Lama']);

        $rows = collect($this->getJson('/api/job-openings')->assertOk()->json('data'))->keyBy('title');

        $this->assertSame('2026-12-31', $rows['Installer PPF']['valid_through']);
        $this->assertStringStartsWith('2026-10-20', $rows['Installer PPF']['posted_at']);
        $this->assertNull($rows['Lama']['valid_through']);
        $this->assertNotNull($rows['Lama']['posted_at'], 'Tanpa tanggal terbit memakai tanggal dibuat.');
    }

    public function test_listing_status_for_the_panel(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');

        $this->assertSame('tayang', $this->job()->listingStatus());
        $this->assertSame('tayang', $this->job(['valid_through' => '2026-11-10'])->listingStatus());
        $this->assertSame('berakhir', $this->job(['valid_through' => '2026-11-09'])->listingStatus());
        $this->assertSame('draft', $this->job(['is_published' => false])->listingStatus());

        Carbon::setTestNow();
    }
}
