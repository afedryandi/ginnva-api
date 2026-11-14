<x-filament-panels::page>
    @include('filament.partials.plain-ui')

    @once
        <style>
            .pu-ap-chips { display: flex; flex-wrap: wrap; gap: 8px; }
            .pu-ap-chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border: 1px solid var(--pu-line); border-radius: 999px; font-size: 13px; font-weight: 600; color: var(--pu-text); background: transparent; cursor: pointer; }
            .pu-ap-chip:hover { background: var(--pu-hover); }
            .pu-ap-chip-active { background: var(--pu-link); border-color: var(--pu-link); color: #fff; }
            .pu-ap-chip-active:hover { background: var(--pu-link); }
            .pu-ap-count { font-size: 12px; opacity: .85; }
            .pu-ap-item { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 16px; padding: 14px 16px; border: 1px solid var(--pu-line); border-radius: 12px; }
            .pu-ap-icon { display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; flex-shrink: 0; border-radius: 12px; background: var(--pu-soft); color: var(--pu-link); }
            .pu-ap-icon svg { width: 22px; height: 22px; }
            .pu-ap-body { flex: 1 1 260px; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
            .pu-ap-type { font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--pu-muted); }
            .pu-ap-summary { color: var(--pu-text); overflow-wrap: anywhere; }
            .pu-ap-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
            .pu-ap-stale { border-color: var(--pu-warn); }
        </style>
    @endonce

    @php
        $items = $this->getItems();
        $counts = $this->getCounts();
        $labels = $this->getSourceLabels();
        $total = array_sum($counts);
    @endphp

    <div class="pu pu-stack">
        <div class="pu-ap-chips">
            <button type="button" wire:click="filterBy(null)" class="pu-ap-chip {{ $this->source ? '' : 'pu-ap-chip-active' }}">
                Semua <span class="pu-ap-count">{{ $total }}</span>
            </button>
            @foreach ($labels as $key => $label)
                @if (($counts[$key] ?? 0) > 0 || $this->source === $key)
                    <button type="button" wire:click="filterBy('{{ $key }}')" class="pu-ap-chip {{ $this->source === $key ? 'pu-ap-chip-active' : '' }}">
                        {{ $label }} <span class="pu-ap-count">{{ $counts[$key] ?? 0 }}</span>
                    </button>
                @endif
            @endforeach
        </div>

        @forelse ($items as $item)
            <div class="pu-ap-item {{ $item->isStale() ? 'pu-ap-stale' : '' }}" wire:key="ap-{{ $item->source }}-{{ $item->id }}">
                <div class="pu-ap-icon"><x-filament::icon :icon="$item->icon" /></div>

                <div class="pu-ap-body">
                    <span class="pu-ap-type">{{ $item->typeLabel }}</span>
                    <span class="pu-strong">{{ $item->who }}</span>
                    <span class="pu-ap-summary">{{ $item->summary }}</span>
                    <span class="pu-small {{ $item->isStale() ? 'pu-warn' : 'pu-muted' }}">
                        @if ($item->store) {{ $item->store }} · @endif
                        diajukan {{ $item->submittedAt?->locale('id')->diffForHumans() ?? '—' }}
                        @if ($item->isStale()) · sudah lebih dari sehari @endif
                    </span>
                </div>

                <div class="pu-ap-actions">
                    @if ($item->inline)
                        {{ ($this->approveAction)(['source' => $item->source, 'id' => $item->id]) }}
                        {{ ($this->rejectAction)(['source' => $item->source, 'id' => $item->id]) }}
                        @if ($item->url)
                            <a href="{{ $item->url }}" class="pu-link pu-small">Detail</a>
                        @endif
                    @elseif ($item->url)
                        <x-filament::button tag="a" :href="$item->url" color="gray" size="sm" icon="heroicon-o-arrow-top-right-on-square">
                            Buka
                        </x-filament::button>
                    @endif
                </div>
            </div>
        @empty
            <div class="pu-bar pu-muted">
                Tidak ada pengajuan yang menunggu keputusan. Pengajuan baru muncul di sini dan di lencana menu.
            </div>
        @endforelse
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
