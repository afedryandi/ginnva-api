<?php

namespace App\Services;

use RuntimeException;

/** Item opname yang selisihnya melewati ambang catatan (adjustment_note_threshold) tetapi belum diberi catatan alasan. */
class OpnameNeedsNoteException extends RuntimeException
{
    /** @param list<array{item_type: string, item_id: int, message: string}> $problems */
    public function __construct(public array $problems)
    {
        parent::__construct(count($problems) === 1 ? $problems[0]['message'] : count($problems) . ' item perlu catatan alasan selisih sebelum disimpan.');
    }
}