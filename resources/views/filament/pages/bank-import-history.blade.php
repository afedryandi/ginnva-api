<div class="space-y-2 text-sm">
    @forelse ($batches as $batch)
        <div class="flex items-center justify-between gap-4 border-b border-gray-100 py-2 dark:border-white/10">
            <div>
                <div class="font-medium">{{ $batch->original_filename ?? $batch->batch }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $batch->account?->display_name }} · {{ $batch->imported_count }} diimpor
                    @if ($batch->duplicate_count) · {{ $batch->duplicate_count }} duplikat @endif
                    @if ($batch->invalid_count) · {{ $batch->invalid_count }} tidak valid @endif
                    · {{ $batch->creator?->name ?? '—' }} · {{ $batch->created_at->format('d M Y H:i') }}
                </div>
            </div>
        </div>
    @empty
        <p class="text-gray-500 dark:text-gray-400">Belum ada riwayat impor.</p>
    @endforelse
</div>
