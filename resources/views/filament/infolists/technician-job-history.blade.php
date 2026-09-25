{{--
    GAP DIPERBAIKI 2026-09-25 (audit Teknisi, "tidak ada riwayat
    performa/komisi gabungan di halaman Technician itu sendiri") --
    SEBELUMNYA admin harus loncat ke Laporan Komisi/Utilisasi Teknisi
    terpisah untuk lihat riwayat 1 teknisi tertentu. Bagian ini murni
    baca (ViewEntry di infolist ViewTechnician), pakai
    Technician::commissionForBooking() yang sudah ada -- satu-satunya
    tempat aturan komisi dihitung, tidak duplikasi logika.
--}}
@php
    $technician = $getRecord();
    $bookings = $technician->user_id
        ? \App\Models\Booking::query()
            ->whereHas('installers', fn ($q) => $q->where('users.id', $technician->user_id))
            ->whereIn('status', ['confirmed', 'completed'])
            ->orderByDesc('preferred_date')
            ->limit(10)
            ->get(['id', 'booking_number', 'customer_name', 'preferred_date', 'status', 'product_ppf', 'product_kaca_film', 'product_detailing', 'product_premium_wash'])
        : collect();

    $monthToDateCommission = $technician->user_id
        ? \App\Models\Booking::query()
            ->whereHas('installers', fn ($q) => $q->where('users.id', $technician->user_id))
            ->where('status', 'confirmed')
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]))
            ->get()
            ->sum(fn ($b) => $technician->commissionForBooking($b) ?? 0)
        : 0;

    $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
@endphp

<div class="space-y-3">
    <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5">
        <span class="text-gray-500 dark:text-gray-400">Estimasi Komisi Bulan Ini:</span>
        <span class="font-semibold text-gray-900 dark:text-white">{{ $rupiah($monthToDateCommission) }}</span>
        <span class="text-xs text-gray-400 dark:text-gray-500">(booking berstatus confirmed yang sudah tercatat ke Jurnal Umum)</span>
    </div>

    @if ($bookings->isEmpty())
        <p class="text-xs text-gray-400 dark:text-gray-500">Belum ada riwayat booking untuk teknisi ini.</p>
    @else
        <div class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($bookings as $booking)
                @php
                    $products = collect([
                        $booking->product_ppf ? 'PPF' : null,
                        $booking->product_kaca_film ? 'Kaca Film' : null,
                        $booking->product_detailing ? 'Detailing' : null,
                        $booking->product_premium_wash ? 'Premium Wash' : null,
                    ])->filter()->join(', ');
                    $commission = $technician->commissionForBooking($booking);
                @endphp
                <div class="flex items-center justify-between gap-3 py-2 text-sm">
                    <div class="min-w-0">
                        <div class="truncate font-medium text-gray-900 dark:text-white">{{ $booking->booking_number }} — {{ $booking->customer_name }}</div>
                        <div class="text-xs text-gray-400 dark:text-gray-500">{{ $booking->preferred_date?->format('d M Y') }} · {{ $products ?: '—' }}</div>
                    </div>
                    <div class="flex-shrink-0 text-xs font-semibold text-gray-700 dark:text-gray-200">
                        {{ $commission !== null ? $rupiah($commission) : 'Belum diatur' }}
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
