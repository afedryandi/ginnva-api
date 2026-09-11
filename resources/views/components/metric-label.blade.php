@props(['tooltip'])
{{-- Label metrik + ikon (?) — dipakai berulang di Dashboard Penjualan
     supaya markup tiap metrik tidak diduplikasi.

     Tooltip pakai directive x-tooltip milik Filament (tippy.js, sudah
     ter-bundle di panel) supaya jalan di hover DAN tap (touch/tablet),
     dengan atribut `title` native sebagai fallback kalau JS mati.
     Teks tooltip sengaja dijaga SATU BARIS — definisi lengkap tiap
     angka ada di panel "Cara baca angka" yang bisa dibuka di bawah
     bagian metrik (keyboard & touch accessible via <details>). --}}
<div class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
    <span>{{ $slot }}</span>
    <button
        type="button"
        x-data
        x-tooltip.raw="{{ $tooltip }}"
        title="{{ $tooltip }}"
        class="cursor-help text-gray-400 hover:text-gray-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-gray-500 dark:hover:text-gray-300"
        aria-label="Keterangan: {{ $tooltip }}"
    >
        <x-heroicon-o-question-mark-circle class="h-3.5 w-3.5" />
    </button>
</div>
