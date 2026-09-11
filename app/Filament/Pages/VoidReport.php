<?php

namespace App\Filament\Pages;

use App\Exports\VoidReportExport;
use App\Models\Booking;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;

/**
 * "Laporan Void" — diminta 2026-09-09, analog "Laporan Void" Majoo:
 * rekap booking yang DIBATALKAN (status berubah jadi 'cancelled') dalam
 * 1 rentang tanggal.
 *
 * Booking TIDAK punya kolom cancelled_at/cancel_reason terpisah, TAPI
 * setiap perubahan status sudah otomatis tercatat di activity_log lewat
 * LogsActivity (lihat Booking::getActivitylogOptions(), logOnlyDirty)
 * — sama sumber data yang dipakai ActivityResource ("Aktivitas"). Jadi
 * "kapan dibatalkan" & "siapa yang membatalkan" DIAMBIL DARI LOG,
 * bukan dari kolom Booking langsung, supaya tidak perlu migrasi kolom
 * baru untuk data yang sebenarnya sudah tercatat.
 *
 * "Potensi kehilangan pendapatan" dihitung dari transaction_amount
 * booking SAAT INI (bukan snapshot nilai transaksi persis di detik
 * dibatalkan) -- untuk booking yang batal SEBELUM transaction_amount
 * pernah diisi, nilainya 0 (memang belum ada nilai transaksi yang
 * hilang). Ini pendekatan yang sama dipakai laporan lain yang membaca
 * kondisi Booking saat ini, bukan histori nilai per field.
 *
 * CATATAN: halaman ini BUKAN dead-code seperti badge "Void" di
 * SalesResource (yang scope dasarnya whereHas('journalEntry') —
 * booking cancelled tidak pernah punya jurnal, jadi badge itu tidak
 * pernah muncul). Laporan ini baca dari activity_log, independen dari
 * jurnal, jadi BERFUNGSI NORMAL.
 *
 * Scoping toko (audit 2026-09-11, temuan I) — SEBELUMNYA di-scope di
 * PHP (tarik SEMUA histori pembatalan company-wide ke memori dulu, baru
 * filter Collection per toko). Sekarang di-scope di SQL lewat
 * whereHasMorph (subject Booking punya store_id), DB yang filter.
 */
class VoidReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-x-circle';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Penjualan';

    protected static ?string $navigationLabel = 'Laporan Void';

    protected static ?string $title = 'Laporan Void';

    protected static ?int $navigationSort = 8;

    protected static string $view = 'filament.pages.void-report';

    public ?array $data = [];

    // #[Url] (audit 2026-09-11, temuan D) — pola sama laporan Penjualan
    // lain.
    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->from = $this->queryDateOrDefault($this->from, now()->startOfMonth());
        $this->to = $this->queryDateOrDefault($this->to, now()->endOfMonth());

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
        ]);
    }

    private function queryDateOrDefault(mixed $value, Carbon $default): string
    {
        if (! is_string($value) || $value === '') {
            return $default->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return $default->toDateString();
        }
    }

    public function updatedData(mixed $value, string $key): void
    {
        match ($key) {
            'from' => $this->from = $value,
            'to' => $this->to = $value,
            default => null,
        };
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
        ])->columns(2)->statePath('data');
    }

    /**
     * "Ekspor Laporan" (audit 2026-09-11, temuan B) — pola sama laporan
     * Penjualan lain.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new VoidReportExport($this->getResult()),
                    'laporan-void-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.void_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'laporan-void-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;
        $storeId = $isFullAccess ? null : $user?->store_id;

        $events = Activity::query()
            ->where('subject_type', Booking::class)
            // logOnlyDirty berarti kalau 'status' muncul di
            // properties->attributes, itu PASTI perubahan status (bukan
            // field lain yang kebetulan ikut ter-log) -- dan nilai
            // barunya 'cancelled'.
            ->where('properties->attributes->status', 'cancelled')
            ->whereBetween('created_at', [$from, $to])
            // Scope toko di SQL (audit 2026-09-11, temuan I) — subject
            // Booking punya store_id, whereHasMorph push filter ke DB
            // ketimbang tarik semua histori company-wide ke PHP dulu.
            ->when($storeId, fn ($q) => $q->whereHasMorph(
                'subject',
                [Booking::class],
                fn ($q2) => $q2->where('store_id', $storeId)
            ))
            ->with(['causer', 'subject' => fn ($q) => $q->with('store:id,name')])
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (Activity $activity) => $activity->subject !== null)
            ->values();

        $totalLostRevenue = $events->sum(fn (Activity $activity) => (float) ($activity->subject->transaction_amount ?? 0));

        return [
            'from' => $from,
            'to' => $to,
            'events' => $events,
            'totalCount' => $events->count(),
            'totalLostRevenue' => $totalLostRevenue,
        ];
    }
}
