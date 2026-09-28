@props(['notices' => []])

@if (! empty($notices))
    <div class="space-y-2">
        @foreach ($notices as $notice)
            <div @class([
                'rounded-lg border p-3 text-sm',
                'border-warning-300 bg-warning-50 text-warning-700 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-300' => $notice['type'] === 'warning',
                'border-info-300 bg-info-50 text-info-700 dark:border-info-700 dark:bg-info-950 dark:text-info-300' => $notice['type'] !== 'warning',
            ])>
                {{ $notice['text'] }}
            </div>
        @endforeach
    </div>
@endif
