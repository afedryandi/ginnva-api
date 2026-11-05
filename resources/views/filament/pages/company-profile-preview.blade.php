<div class="rounded-lg border border-gray-200 p-4 text-xs text-gray-600 dark:border-white/10 dark:text-gray-300">
    <div class="font-semibold text-gray-950 dark:text-white">{{ $profile['name'] }}@if ($profile['npwp']) &middot; NPWP {{ $profile['npwp'] }}@endif @if ($profile['klu']) &middot; KLU {{ $profile['klu'] }}@endif</div>
    @if ($profile['address'])
        <div class="mt-1">{{ $profile['address'] }}</div>
    @endif
    <div class="mt-2 text-[11px] text-gray-500 dark:text-gray-400">
        Dokumen ini digenerate otomatis oleh sistem Ginnva. Ini adalah invoice/bukti transaksi internal, bukan Faktur Pajak.
    </div>
</div>
