<?php

namespace App\Http\Controllers;

use App\Support\RecordPdf;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Cetak PDF satu data dari menu mana pun di panel admin. Akses mengikuti hak lihat menu itu (Resource::canView). */
class RecordPdfController extends Controller
{
    public function __invoke(Request $request, string $slug, string $record)
    {
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);

        // Aplikasi ini tidak punya rute "login" biasa, jadi tamu diarahkan ke halaman masuk panel (bukan lewat middleware auth).
        if (! auth($panel->getAuthGuard())->check()) {
            return redirect()->guest($panel->getLoginUrl());
        }

        // Di luar panel guard bawaan bukan "web", sedangkan hak akses menu (Resource::canView) membaca auth()->user().
        auth()->shouldUse($panel->getAuthGuard());

        $resource = collect($panel->getResources())->first(fn (string $class) => $class::getSlug() === $slug);

        abort_if($resource === null, 404);

        $model = $resource::getEloquentQuery()->findOrFail($record);

        abort_unless($resource::canView($model), 403);

        $name = Str::slug($resource::getModelLabel() . '-' . $resource::getRecordTitle($model)) ?: 'detail';

        return RecordPdf::render($resource, $model)->stream($name . '.pdf');
    }
}
