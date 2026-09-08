@props(['tooltip'])
{{-- Label metrik + ikon (?) dengan tooltip native (title attribute) —
     dipakai berulang di Dashboard Penjualan supaya tidak duplikat markup
     tiap metrik. Native title dipilih (bukan x-tooltip Alpine Filament)
     supaya tidak bergantung API internal yang tidak bisa diverifikasi
     visual dari environment ini. --}}
<div class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
    <span>{{ $slot }}</span>
    <span
        title="{{ $tooltip }}"
        class="cursor-help text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
    >
        <x-heroicon-o-question-mark-circle class="h-3.5 w-3.5" />
    </span>
</div>
