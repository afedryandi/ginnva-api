<?php

namespace Tests\Feature;

use App\Support\Tooltips;
use PHPUnit\Framework\TestCase;

/**
 * Teks tooltip status: setiap status yang dipakai sistem punya penjelasan, dan nilai yang tidak dikenal tidak memunculkan
 * tooltip yang menyesatkan.
 */
class TooltipsTest extends TestCase
{
    public function test_every_known_status_has_a_tooltip(): void
    {
        $cases = [
            'bookingStatus'          => ['pending', 'confirmed', 'completed', 'cancelled'],
            'bookingSource'          => ['app', 'whatsapp', 'walk_in'],
            'warrantyReview'         => ['pending_review', 'approved', 'rejected'],
            'warrantyStatus'         => ['active', 'expired', 'revoked', 'pending_review'],
            'journalStatus'          => ['draft', 'posted'],
            'payableStatus'          => ['unpaid', 'partial', 'paid', 'cancelled'],
            'receivableStatus'       => ['unpaid', 'partial', 'paid', 'cancelled'],
            'rewardRedemptionStatus' => ['pending', 'fulfilled', 'cancelled'],
            'quotationStatus'        => ['new', 'contacted', 'closed', 'cancelled'],
        ];

        foreach ($cases as $method => $states) {
            foreach ($states as $state) {
                $text = Tooltips::{$method}($state);
                $this->assertIsString($text, "$method($state) tanpa tooltip.");
                $this->assertGreaterThan(15, mb_strlen($text));
            }
        }
    }

    public function test_unknown_or_empty_values_return_no_tooltip(): void
    {
        $this->assertNull(Tooltips::bookingStatus('status-baru'));
        $this->assertNull(Tooltips::bookingStatus(null));
        $this->assertNull(Tooltips::payableStatus(''));
    }

    public function test_payable_and_receivable_wording_differs(): void
    {
        $this->assertStringContainsString('Utang', Tooltips::payableStatus('unpaid'));
        $this->assertStringContainsString('Tagihan', Tooltips::receivableStatus('unpaid'));
    }
}
