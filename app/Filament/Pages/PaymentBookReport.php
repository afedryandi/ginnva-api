<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\BookingDownPayment;
use App\Models\BookingSettlementPayment;
use App\Models\ReceivablePayment;
use App\Models\Store;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * "Buku Pembayaran" (Penjualan Fase 2, 2026-11-11): satu daftar semua uang masuk dari booking dalam satu rentang tanggal, dari tiga tempat
 * yang sudah ada: uang muka (DP), pembayaran saat penjualan diproses, dan pelunasan piutang booking. Hanya membaca; tidak menyalin data.
 * Toko dibatasi seperti laporan Penjualan lain: staf toko hanya melihat tokonya sendiri.
 *
 * 2026-11-04 (audit): kolom Rekening hanya ada bila modul Keuangan menyala; DP kini mencatat Metode (DP lama tanpa metode tampil "—");
 * ada Periode Cepat, filter tersimpan di URL, nomor booking bisa diklik, dan baris Total.
 */
class PaymentBookReport extends Page implements HasForms
{
    use InteractsWithForms;
    use \App\Filament\Pages\Concerns\ExportsReportTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Penjualan';

    protected static ?string $navigationLabel = 'Buku Pembayaran';

    protected static ?string $title = 'Buku Pembayaran';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.payment-book-report';

    public ?array $data = [];

    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    #[Url(as: 'cabang')]
    public ?int $storeId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasExplicitMenuAccess(static::class);
    }

    public function mount(): void
    {
        // Nilai dari URL tidak dipercaya mentah-mentah: tanggal rusak jatuh ke bawaan, "sampai" sebelum "dari" disamakan, dan staf toko
        // tidak bisa memilih toko lain lewat alamat.
        $this->from = $this->queryDateOrDefault($this->from, now()->startOfMonth());
        $this->to = $this->queryDateOrDefault($this->to, now()->endOfMonth());

        if (Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
        }

        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $this->storeId = null;
        }

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
            'store_id' => $this->storeId,
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

    /** Cerminkan perubahan form ke properti #[Url] supaya alamat halaman ikut berubah (bisa dibagikan dan bertahan saat refresh). */
    public function updatedData(mixed $value, string $key): void
    {
        match ($key) {
            'from' => $this->from = $value,
            'to' => $this->to = $value,
            'store_id' => $this->storeId = $value ? (int) $value : null,
            default => null,
        };

        if (in_array($key, ['from', 'to'], true) && $this->from && $this->to && Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
            $this->data['to'] = $this->from;

            Notification::make()
                ->title('Tanggal "Sampai" tidak boleh sebelum "Dari"')
                ->body('Diset sama dengan tanggal "Dari".')
                ->warning()
                ->send();
        }
    }

    public function form(Form $form): Form
    {
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;

        return $form->schema([
            Select::make('preset')
                ->label('Periode Cepat')
                ->options([
                    'today' => 'Hari ini',
                    'this_month' => 'Bulan ini',
                    'last_month' => 'Bulan lalu',
                    'this_quarter' => 'Kuartal ini',
                    'ytd' => 'Tahun ini (s.d. hari ini)',
                ])
                ->placeholder('Pilih untuk mengisi tanggal otomatis')
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set) {
                    $range = match ($state) {
                        'today' => [now(), now()],
                        'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
                        'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
                        'this_quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
                        'ytd' => [now()->startOfYear(), now()],
                        default => null,
                    };

                    if ($range) {
                        $set('from', $range[0]->toDateString());
                        $set('to', $range[1]->toDateString());
                        $this->from = $range[0]->toDateString();
                        $this->to = $range[1]->toDateString();
                    }
                }),
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
            Select::make('store_id')
                ->label('Toko')
                ->placeholder('Semua toko')
                ->options(fn () => Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->visible($isFullAccess)
                ->live(),
        ])->columns($isFullAccess ? 4 : 3)->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return $this->exportActions();
    }

    protected function reportExportData(): array
    {
        $result = $this->getResult();
        $finance = (bool) config('features.finance');
        $storeName = ($this->data['store_id'] ?? null) && (auth()->user()?->isFullAccess() ?? false)
            ? Store::whereKey($this->data['store_id'])->value('name')
            : null;

        // Kolom Rekening hanya ada bila modul Keuangan menyala (tanpa Keuangan memang tidak ada rekening).
        $headings = ['Tanggal', 'Jenis', 'Referensi', 'Pelanggan', 'Metode'];
        $totals = ['', 'Total', '', '', ''];

        if ($finance) {
            $headings[] = 'Rekening';
            $totals[] = '';
        }

        $amountIndex = count($headings);
        array_push($headings, 'Nominal', 'Catatan');
        array_push($totals, $result['total'], '');

        return [
            'key' => 'buku-pembayaran',
            'title' => 'Buku Pembayaran',
            'subtitle' => $result['from']->translatedFormat('d F Y') . ' - ' . $result['to']->translatedFormat('d F Y') . ' · ' . ($storeName ? "Toko {$storeName}" : 'Semua toko'),
            'headings' => $headings,
            'rows' => $result['rows']->map(function (array $r) use ($finance) {
                $line = [
                    $r['date']?->format('d/m/Y') ?? '—',
                    $r['kind'],
                    $r['reference'],
                    $r['customer'],
                    $r['method'],
                ];

                if ($finance) {
                    $line[] = $r['account'];
                }

                array_push($line, $r['amount'], $r['note']);

                return $line;
            })->all(),
            'totals' => $totals,
            'right' => [$amountIndex],
            'note' => 'Uang muka yang sudah dikembalikan ditampilkan tapi tidak dihitung dalam total. Pengembalian dana (refund) tidak termasuk; lihat Laporan Refund.',
        ];
    }

    /** Staf toko dikunci ke tokonya; staf tanpa toko tidak melihat apa pun (-1). */
    private function effectiveStoreId(): ?int
    {
        $user = auth()->user();

        if ($user?->isFullAccess() ?? false) {
            $chosen = $this->data['store_id'] ?? null;

            return $chosen ? (int) $chosen : null;
        }

        return $user?->store_id ?? -1;
    }

    /** Tautan ke booking untuk baris DP dan pembayaran saat selesai. */
    public function bookingUrl(int $bookingId): string
    {
        return \App\Filament\Resources\BookingResource::getUrl('view', ['record' => $bookingId]);
    }

    /**
     * @return array{rows: Collection, byKind: array<string, float>, byMethod: array<string, float>, total: float, from: Carbon, to: Carbon}
     */
    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();

        if ($to->lt($from)) {
            $to = $from->copy()->endOfDay();
        }

        $storeId = $this->effectiveStoreId();
        $bookingScope = fn ($q) => $q->when($storeId, fn ($w) => $w->where('store_id', $storeId));
        $methods = Booking::PAYMENT_METHOD_LABELS;

        $rows = collect();

        BookingDownPayment::query()
            ->with(['booking:id,booking_number,customer_id,customer_name,store_id', 'booking.customer:id,name', 'paymentAccount:id,name'])
            ->whereBetween('received_at', [$from->toDateString(), $to->toDateString()])
            ->whereHas('booking', $bookingScope)
            ->get()
            ->each(fn (BookingDownPayment $dp) => $rows->push([
                'date' => $dp->received_at,
                'kind' => 'Uang Muka (DP)',
                'booking_id' => $dp->booking_id,
                'reference' => $dp->booking?->booking_number ?? '—',
                'customer' => $dp->booking?->display_customer_name ?? '—',
                'method' => $dp->method ? ($methods[$dp->method] ?? $dp->method) : '—',
                'account' => $dp->paymentAccount?->name ?? 'Kas di Tangan',
                'amount' => (float) $dp->amount,
                'note' => $dp->refunded_at ? 'Dikembalikan' : null,
            ]));

        BookingSettlementPayment::query()
            ->with(['booking:id,booking_number,customer_id,customer_name,store_id', 'booking.customer:id,name', 'paymentAccount:id,name'])
            ->whereBetween('paid_at', [$from->toDateString(), $to->toDateString()])
            ->whereHas('booking', $bookingScope)
            ->get()
            ->each(fn (BookingSettlementPayment $p) => $rows->push([
                'date' => $p->paid_at,
                'kind' => 'Pembayaran saat selesai',
                'booking_id' => $p->booking_id,
                'reference' => $p->booking?->booking_number ?? '—',
                'customer' => $p->booking?->display_customer_name ?? '—',
                'method' => $methods[$p->method] ?? $p->method,
                'account' => $p->paymentAccount?->name ?? 'Otomatis menurut metode',
                'amount' => (float) $p->amount,
                'note' => null,
            ]));

        ReceivablePayment::query()
            ->with('receivable:id,receivable_number,customer_name,store_id,source_type,source_id')
            ->whereNull('voided_at')
            ->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('receivable', fn ($q) => $q->where('source_type', 'booking')->when($storeId, fn ($w) => $w->where('store_id', $storeId)))
            ->get()
            ->each(fn (ReceivablePayment $p) => $rows->push([
                'date' => $p->payment_date,
                'kind' => 'Pelunasan piutang',
                'booking_id' => null,
                'reference' => $p->receipt_number ?: ($p->receivable?->receivable_number ?? '—'),
                'customer' => $p->receivable?->customer_name ?? '—',
                'method' => '—',
                'account' => '—',
                'amount' => (float) $p->amount,
                'note' => null,
            ]));

        $rows = $rows->sortByDesc(fn (array $r) => $r['date']?->timestamp ?? 0)->values();

        $counted = $rows->reject(fn (array $r) => $r['note'] === 'Dikembalikan');

        return [
            'rows' => $rows,
            'byKind' => $counted->groupBy('kind')->map(fn ($g) => (float) $g->sum('amount'))->all(),
            'byMethod' => $counted->where('method', '!=', '—')->groupBy('method')->map(fn ($g) => (float) $g->sum('amount'))->all(),
            'total' => (float) $counted->sum('amount'),
            'from' => $from,
            'to' => $to,
        ];
    }
}
