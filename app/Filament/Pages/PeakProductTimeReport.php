<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * "Waktu Teramai Produk" — diminta 2026-09-09, analog Majoo. DIBANGUN
 * ULANG 2026-09-09 setelah user tunjukkan screenshot Majoo yang
 * SEBENARNYA -- dipecah per PRODUK (SKU) × HARI DALAM SEMINGGU (bukan
 * per jam), pakai film_product_id (SAMA infrastruktur & keterbatasan
 * dengan ProductSalesReport: booking yang belum diisi SKU masuk bucket
 * "Belum Diisi SKU").
 *
 * "Hari" diambil dari JournalEntry.entry_date (tanggal booking BENAR-
 * BENAR tercatat sebagai pendapatan) -- SELALU ada untuk booking yang
 * dihitung di sini, beda dari analisa jam (posted_at) yang butuh
 * timestamp jam:menit lebih spesifik.
 */
class PeakProductTimeReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $cluster = \App\Filament\Clusters\AnalisaLaporanCluster::class;

    protected static ?string $navigationLabel = 'Waktu Teramai Produk';

    protected static ?string $title = 'Waktu Teramai Produk';

    // 601 -- band grup 'Analisa Laporan' (lihat catatan sistem band di
    // ProductSalesReport.php).
    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.peak-product-time-report';

    private const DAY_NAMES = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', "Jum'at", 'Sabtu'];

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
        ])->columns(2)->statePath('data');
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();

        $bookings = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
            ->where('transaction_amount', '>', 0)
            ->with(['journalEntry:id,entry_date', 'filmProduct:id,sku,name'])
            ->get(['id', 'transaction_amount', 'journal_entry_id', 'film_product_id']);

        $totalCount = $bookings->count();
        $totalRevenue = (float) $bookings->sum('transaction_amount');
        $unassignedCount = $bookings->whereNull('film_product_id')->count();

        // Kelompok: [film_product_id, hari] => agregat
        $groups = [];
        foreach ($bookings as $booking) {
            $day = $booking->journalEntry?->entry_date?->dayOfWeek;
            if ($day === null) continue;

            $key = ($booking->film_product_id ?? 'none') . '|' . $day;
            $groups[$key] ??= [
                'product' => $booking->filmProduct,
                'day' => $day,
                'count' => 0,
                'revenue' => 0.0,
            ];
            $groups[$key]['count']++;
            $groups[$key]['revenue'] += (float) $booking->transaction_amount;
        }

        $rows = collect($groups)
            ->map(function ($row) use ($totalCount, $totalRevenue) {
                $row['countPct'] = $totalCount > 0 ? $row['count'] / $totalCount * 100 : 0;
                $row['revenuePct'] = $totalRevenue > 0 ? $row['revenue'] / $totalRevenue * 100 : 0;
                $row['dayName'] = self::DAY_NAMES[$row['day']];

                return $row;
            })
            ->sortByDesc(fn ($row) => $row['product'] === null ? -1 : $row['count'])
            ->values();

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totalCount' => $totalCount,
            'unassignedCount' => $unassignedCount,
        ];
    }
}
