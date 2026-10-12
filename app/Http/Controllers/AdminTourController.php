<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Menandai tur pengenalan panel admin sudah selesai/dilewati untuk staf yang sedang login. Permintaan GET sederhana
 * (idempoten, hanya memengaruhi akun sendiri) supaya tidak bergantung pada token CSRF/Livewire.
 */
class AdminTourController extends Controller
{
    public function done(Request $request): Response
    {
        $user = $request->user('web');

        // Lewat query langsung: tidak memicu jejak aktivitas / event model untuk sekadar menandai tur.
        User::whereKey($user->getKey())->update(['admin_tour_seen_at' => now()]);

        return response()->noContent();
    }
}
