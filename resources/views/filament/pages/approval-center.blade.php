<x-filament-panels::page>
    @include('filament.partials.plain-ui')

    {{-- Gaya khusus halaman ini (berprefiks .ap-). Panel memakai CSS Filament terkompilasi, jadi dipakai CSS polos dengan variabel warna Filament
         (--gray-*, --primary-*) agar mengikuti warna primer dan mode gelap (.dark). Hanya tampilan: data, aksi setujui/tolak, dan hak akses tidak berubah. --}}
    @once
        <style>
            .ap { --ap-surface: #fff; --ap-line: rgba(var(--gray-200), .7); --ap-title: rgb(var(--gray-900)); --ap-text: rgb(var(--gray-700)); --ap-muted: rgb(var(--gray-500)); --ap-hover: rgba(var(--gray-100), .8); --ap-soft: rgba(var(--primary-500), .08); --ap-accent: rgb(var(--primary-600)); --ap-warn-bg: rgba(245, 158, 11, .14); --ap-warn: #92400e; display: flex; flex-direction: column; gap: 1rem; font-variant-numeric: tabular-nums; }
            .dark .ap { --ap-surface: rgb(var(--gray-900)); --ap-line: rgba(255, 255, 255, .08); --ap-title: rgb(var(--gray-50)); --ap-text: rgb(var(--gray-200)); --ap-muted: rgb(var(--gray-400)); --ap-hover: rgba(255, 255, 255, .05); --ap-soft: rgba(var(--primary-400), .14); --ap-accent: rgb(var(--primary-300)); --ap-warn-bg: rgba(245, 158, 11, .18); --ap-warn: #fcd34d; }

            /* Tata letak 2:1: daftar di kiri (2/3), kolom samping di kanan (1/3) berisi ringkasan dan penyaring jenis. Di layar sempit kolom samping naik ke atas. */
            .ap-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1.5rem; }
            .ap-main { min-width: 0; }
            .ap-side { display: flex; flex-direction: column; gap: 1.5rem; order: -1; min-width: 0; }
            @media (min-width: 1024px) {
                .ap-layout { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); align-items: start; }
                .ap-side { order: 0; }
            }
            .ap-card { background: var(--ap-surface); border: 1px solid var(--ap-line); border-radius: 1.25rem; padding: 1.25rem; }
            .ap-card-title { margin-bottom: .875rem; font-size: .75rem; font-weight: 650; letter-spacing: .06em; text-transform: uppercase; color: var(--ap-muted); }

            /* Ringkasan: dua angka. */
            .ap-summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
            .ap-stat-label { font-size: .75rem; color: var(--ap-muted); }
            .ap-stat-value { margin-top: .125rem; font-size: 1.75rem; line-height: 2.25rem; font-weight: 650; letter-spacing: -.01em; color: var(--ap-title); }
            .ap-stat-value.is-warn { color: var(--ap-warn); }

            /* Penyaring jenis: daftar vertikal, yang aktif berlatar primer lembut, jumlah rata kanan. */
            .ap-filter { display: flex; flex-direction: column; gap: .25rem; }
            .ap-pill { display: flex; align-items: center; justify-content: space-between; gap: .5rem; width: 100%; min-height: 2.5rem; padding: 0 .75rem; border: 1px solid transparent; border-radius: .875rem; background: transparent; font-size: .875rem; font-weight: 550; color: var(--ap-text); text-align: left; cursor: pointer; transition: background .15s ease, border-color .15s ease; }
            .ap-pill:hover { background: var(--ap-hover); }
            .ap-pill:focus-visible { outline: 2px solid rgb(var(--primary-500)); outline-offset: 2px; }
            .ap-pill-count { min-width: 1.5rem; padding: 0 .4rem; border-radius: 999px; background: var(--ap-hover); font-size: .75rem; line-height: 1.5rem; text-align: center; color: var(--ap-muted); }
            .ap-pill.is-active { background: var(--ap-soft); border-color: rgba(var(--primary-500), .3); color: var(--ap-accent); }
            .ap-pill.is-active .ap-pill-count { background: rgb(var(--primary-600)); color: #fff; }

            /* Daftar: satu kartu berisi baris-baris bersekat tipis. */
            .ap-list { background: var(--ap-surface); border: 1px solid var(--ap-line); border-radius: 1.25rem; overflow: hidden; }
            .ap-row { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem 1rem; padding: 1rem 1.25rem; transition: background .15s ease; }
            .ap-row + .ap-row { border-top: 1px solid var(--ap-line); }
            .ap-row:hover { background: var(--ap-hover); }
            .ap-icon { display: flex; align-items: center; justify-content: center; flex-shrink: 0; width: 2.5rem; height: 2.5rem; border-radius: .75rem; background: var(--ap-soft); color: var(--ap-accent); }
            .ap-icon svg { width: 1.25rem; height: 1.25rem; }
            .ap-body { flex: 1 1 18rem; min-width: 0; display: flex; flex-direction: column; gap: .25rem; }
            .ap-head { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem .625rem; }
            .ap-who { font-size: .9375rem; font-weight: 600; color: var(--ap-title); }
            .ap-badge { display: inline-flex; align-items: center; height: 1.375rem; padding: 0 .5rem; border-radius: .5rem; background: var(--ap-hover); font-size: .6875rem; font-weight: 650; letter-spacing: .02em; text-transform: uppercase; color: var(--ap-muted); }
            .ap-badge.is-warn { background: var(--ap-warn-bg); color: var(--ap-warn); }
            .ap-text { font-size: .875rem; line-height: 1.375rem; color: var(--ap-text); overflow-wrap: anywhere; }
            .ap-meta { font-size: .75rem; color: var(--ap-muted); }
            .ap-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: .5rem; margin-left: auto; }
            .ap-view-only { font-size: .75rem; color: var(--ap-muted); }
            .ap-detail { font-size: .8125rem; font-weight: 600; color: var(--ap-accent); text-decoration: none; }
            .ap-detail:hover { text-decoration: underline; }

            /* Kosong. */
            .ap-empty { display: flex; flex-direction: column; align-items: center; gap: .5rem; padding: 3rem 1.5rem; text-align: center; background: var(--ap-surface); border: 1px dashed var(--ap-line); border-radius: 1rem; }
            .ap-empty-icon { display: flex; align-items: center; justify-content: center; width: 3rem; height: 3rem; border-radius: 999px; background: var(--ap-soft); color: var(--ap-accent); }
            .ap-empty-icon svg { width: 1.5rem; height: 1.5rem; }
            .ap-empty-title { font-size: .9375rem; font-weight: 600; color: var(--ap-title); }
            .ap-empty-text { max-width: 26rem; font-size: .8125rem; color: var(--ap-muted); }

            @media (max-width: 640px) {
                .ap-row { padding: .875rem 1rem; }
                .ap-actions { width: 100%; justify-content: flex-start; margin-left: 0; }
            }
        </style>
    @endonce

    @php
        $items = $this->getItems();
        $counts = $this->getCounts();
        $labels = $this->getSourceLabels();
        $total = array_sum($counts);
        $stale = collect($items)->filter(fn ($i) => $i->isStale())->count();
    @endphp

    <div class="ap">
        <div class="ap-layout">
        <div class="ap-main">
        @if (count($items) > 0)
            <div class="ap-list">
                @foreach ($items as $item)
                    <div class="ap-row" wire:key="ap-{{ $item->source }}-{{ $item->id }}">
                        <div class="ap-icon"><x-filament::icon :icon="$item->icon" /></div>

                        <div class="ap-body">
                            <div class="ap-head">
                                <span class="ap-who">{{ $item->who }}</span>
                                <span class="ap-badge">{{ $item->typeLabel }}</span>
                                @if ($item->isStale())
                                    <span class="ap-badge is-warn">Lebih dari sehari</span>
                                @endif
                            </div>
                            <span class="ap-text">{{ $item->summary }}</span>
                            <span class="ap-meta">
                                @if ($item->store) {{ $item->store }} · @endif
                                diajukan {{ $item->submittedAt?->locale('id')->diffForHumans() ?? '—' }}
                            </span>
                        </div>

                        <div class="ap-actions">
                            @if ($item->inline)
                                @if ($item->url)
                                    <a href="{{ $item->url }}" class="ap-detail">Detail</a>
                                @endif
                                @if ($this->canDecide())
                                    {{ ($this->rejectAction)(['source' => $item->source, 'id' => $item->id]) }}
                                    {{ ($this->approveAction)(['source' => $item->source, 'id' => $item->id]) }}
                                @else
                                    <span class="ap-view-only">Hanya melihat</span>
                                @endif
                            @elseif ($item->url)
                                <x-filament::button tag="a" :href="$item->url" color="gray" size="sm" icon="heroicon-o-arrow-top-right-on-square">
                                    Buka
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="ap-empty">
                <div class="ap-empty-icon"><x-filament::icon icon="heroicon-o-check-circle" /></div>
                <div class="ap-empty-title">Tidak ada yang menunggu</div>
                <div class="ap-empty-text">Semua pengajuan sudah diputuskan. Pengajuan baru muncul di sini dan di lencana menu.</div>
            </div>
        @endif
        </div>

        <aside class="ap-side">
            <section class="ap-card">
                <div class="ap-card-title">Ringkasan</div>
                <div class="ap-summary">
                    <div>
                        <div class="ap-stat-label">Menunggu keputusan</div>
                        <div class="ap-stat-value">{{ $total }}</div>
                    </div>
                    <div>
                        <div class="ap-stat-label">Lebih dari sehari</div>
                        <div class="ap-stat-value {{ $stale > 0 ? 'is-warn' : '' }}">{{ $stale }}</div>
                    </div>
                </div>
            </section>

            <section class="ap-card">
                <div class="ap-card-title">Jenis pengajuan</div>
                <div class="ap-filter" role="tablist" aria-label="Jenis pengajuan">
                    <button type="button" role="tab" aria-selected="{{ $this->source ? 'false' : 'true' }}" wire:click="filterBy(null)" class="ap-pill {{ $this->source ? '' : 'is-active' }}">
                        Semua <span class="ap-pill-count">{{ $total }}</span>
                    </button>
                    @foreach ($labels as $key => $label)
                        @if (($counts[$key] ?? 0) > 0 || $this->source === $key)
                            <button type="button" role="tab" aria-selected="{{ $this->source === $key ? 'true' : 'false' }}" wire:click="filterBy('{{ $key }}')" class="ap-pill {{ $this->source === $key ? 'is-active' : '' }}">
                                {{ $label }} <span class="ap-pill-count">{{ $counts[$key] ?? 0 }}</span>
                            </button>
                        @endif
                    @endforeach
                </div>
            </section>
        </aside>
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
