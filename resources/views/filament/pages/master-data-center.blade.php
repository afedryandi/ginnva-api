<x-filament-panels::page>
    @php
        $sections = $this->getSections();
        $attention = collect($sections)->flatten(1)->filter(fn ($c) => $c['severity'] === 'warn' && $c['count'] > 0)->count();
    @endphp

    <div @class([
        'rounded-lg px-4 py-3 text-sm',
        'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $attention === 0,
        'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' => $attention > 0,
    ])>
        @if ($attention === 0)
            Tidak ada pemeriksaan penting yang bermasalah. Butir bertanda "Dilengkapi" bersifat saran.
        @else
            {{ $attention }} pemeriksaan penting masih perlu ditindak. Halaman ini hanya membaca; perbaiki di menu yang ditautkan.
        @endif
    </div>

    @foreach ($sections as $section => $checks)
        <x-filament::section :heading="$section">
            @php($link = $this->linkFor($section))
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($checks as $check)
                    <li class="flex items-start justify-between gap-4 py-2 text-sm">
                        <div>
                            <div class="font-medium">{{ $check['label'] }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $check['note'] }}</div>
                        </div>
                        <div class="shrink-0 text-right tabular-nums">
                            @if ($check['count'] === 0)
                                <span class="text-xs text-success-600 dark:text-success-400">Beres</span>
                            @else
                                <span @class([
                                    'rounded-md px-2 py-0.5 text-xs font-semibold',
                                    'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' => $check['severity'] === 'warn',
                                    'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300' => $check['severity'] !== 'warn',
                                ])>{{ $check['count'] . ($check['total'] > 0 ? ' dari ' . $check['total'] : '') }}</span>
                                <div class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">{{ $check['severity'] === 'warn' ? 'Perlu ditindak' : 'Dilengkapi' }}</div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($link)
                <div class="mt-2"><a href="{{ $link }}" class="text-sm underline">Buka {{ $section }}</a></div>
            @endif
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
