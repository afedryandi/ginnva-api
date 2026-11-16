<x-filament-widgets::widget>
    {{-- Gaya .gd-act ada di filament/partials/dashboard-styles (dimuat oleh halaman Dashboard). --}}
    @php $logs = $this->getLogs(); @endphp

    <section class="gd-act">
        <div class="gd-act-head">
            <div>
                <h2 class="gd-act-title">Aktivitas terbaru</h2>
                <p class="gd-act-sub">Perubahan dan kejadian terakhir di sistem</p>
            </div>
            <a href="{{ $this->allUrl() }}" class="gd-act-link">Lihat semua</a>
        </div>

        @if ($logs->isEmpty())
            <p class="gd-act-empty">Belum ada aktivitas tercatat.</p>
        @else
            <ul class="gd-act-list">
                @foreach ($logs as $log)
                    <li class="gd-act-row">
                        <div class="gd-act-main">
                            <div class="gd-act-line">
                                <span class="gd-act-actor">{{ $log['actor'] }}</span>
                                <span class="gd-act-action">{{ $log['action'] }}</span>
                                <span class="gd-act-target">{{ $log['target'] }}</span>
                            </div>
                            <div class="gd-act-meta">
                                <span class="gd-act-module">{{ $log['module'] }}</span>
                                @if ($log['risky'])
                                    <span class="gd-act-risky">Sensitif</span>
                                @endif
                                @if ($log['note'] !== '')
                                    <span class="gd-act-note">{{ $log['note'] }}</span>
                                @endif
                            </div>
                        </div>
                        <span class="gd-act-time">{{ $log['time'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-filament-widgets::widget>
