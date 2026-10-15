{{--
    Penerimaan, Quality Check, dan serah terima sebuah booking (koreksi celah Fase 3, 2026-10-22): foto penerimaan, hasil QC
    terbaru, dan catatan serah terima sebelumnya hanya terlihat di aplikasi staf; di sini admin dan Store Manager bisa melihat
    buktinya dari detail booking. Murni baca (ViewEntry di infolist BookingResource). Gaya inline: panel memakai CSS bawaan
    Filament, bukan hasil compile Tailwind project ini.
--}}
@php
    /** @var \App\Models\Booking $booking */
    $booking = $getRecord();
    $photos = $booking->intakePhotos()->orderBy('id')->get();
    $angles = \App\Models\BookingIntakePhoto::ANGLES;
    $damages = $photos->where('angle', \App\Models\BookingIntakePhoto::DAMAGE);
    $qc = $booking->qcResults()->with(['inspector:id,name', 'items'])->first();
    $handover = $booking->handover()->with('recorder:id,name')->first();
    $when = fn ($d) => $d?->timezone(config('app.timezone'))->format('d M Y H:i');
@endphp

<div style="display:grid; gap:1.25rem;">
    {{-- Foto penerimaan --}}
    <div>
        <div class="text-sm font-semibold">Foto Penerimaan</div>
        @if ($photos->isEmpty())
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">Belum ada foto penerimaan.</div>
        @else
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:0.75rem; margin-top:0.5rem;">
                @foreach ($angles as $key => $label)
                    @php $photo = $photos->firstWhere('angle', $key); @endphp
                    <div>
                        @if ($photo)
                            <a href="{{ $photo->url() }}" target="_blank" rel="noopener">
                                <img src="{{ $photo->url() }}" alt="Foto {{ $label }}" loading="lazy" style="width:100%; height:110px; object-fit:cover; border-radius:0.5rem;">
                            </a>
                        @else
                            <div style="width:100%; height:110px; border-radius:0.5rem; border:1px dashed currentColor; opacity:0.35; display:flex; align-items:center; justify-content:center; font-size:0.75rem;">Belum difoto</div>
                        @endif
                        <div class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $label }}</div>
                    </div>
                @endforeach
            </div>

            @if ($damages->isNotEmpty())
                <div class="mt-3 text-xs font-semibold text-gray-600 dark:text-gray-300">Kerusakan yang sudah ada saat masuk</div>
                <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:0.75rem; margin-top:0.5rem;">
                    @foreach ($damages as $photo)
                        <div>
                            <a href="{{ $photo->url() }}" target="_blank" rel="noopener">
                                <img src="{{ $photo->url() }}" alt="Kerusakan" loading="lazy" style="width:100%; height:110px; object-fit:cover; border-radius:0.5rem;">
                            </a>
                            <div class="mt-1 text-xs">{{ $photo->note }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </div>

    {{-- Quality Check terbaru --}}
    <div>
        <div class="text-sm font-semibold">Quality Check</div>
        @if (! $qc)
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">Belum diperiksa.</div>
        @else
            <div class="mt-1 text-sm">
                <span class="font-semibold {{ $qc->passed ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">{{ $qc->passed ? 'Lulus' : 'Belum lulus' }}</span>
                — {{ $qc->inspector?->name ?? 'pemeriksa tidak diketahui' }}, {{ $when($qc->created_at) }}
                @if ($booking->ready_at)
                    <span class="text-gray-500 dark:text-gray-400">· siap diambil sejak {{ $when($booking->ready_at) }}</span>
                @endif
            </div>
            @if ($qc->notes)
                <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">Catatan: {{ $qc->notes }}</div>
            @endif
            @php $failed = $qc->items->where('passed', false); @endphp
            @if ($failed->isNotEmpty())
                <ul class="mt-1 text-sm" style="list-style:disc; padding-left:1.25rem;">
                    @foreach ($failed as $item)
                        <li>{{ $item->label }}@if ($item->note) — {{ $item->note }}@endif</li>
                    @endforeach
                </ul>
            @endif
            @if ($booking->qcResults()->count() > 1)
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Total {{ $booking->qcResults()->count() }} kali diperiksa; yang tampil adalah yang terbaru.</div>
            @endif
        @endif
    </div>

    {{-- Serah terima --}}
    <div>
        <div class="text-sm font-semibold">Serah Terima</div>
        @if (! $handover)
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">Belum dicatat.</div>
        @else
            <div class="mt-1 text-sm">
                Dicatat oleh {{ $handover->recorder?->name ?? 'tidak diketahui' }}, {{ $when($handover->handed_over_at) }}
            </div>
            <div class="mt-1 text-sm">Petunjuk perawatan: {{ $handover->care_instructions_given ? 'sudah disampaikan' : 'belum' }}</div>
            <div class="text-sm">
                Tanda tangan basah:
                {{ $handover->wet_signature_received ? 'diterima' : ($handover->wet_signature_required ? 'BELUM diterima' : 'tidak diterima (opsional)') }}
            </div>
            @if ($handover->notes)
                <div class="text-sm">Catatan: {{ $handover->notes }}</div>
            @endif
            @if ($handover->final_photo_path)
                @php $finalUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($handover->final_photo_path); @endphp
                <a href="{{ $finalUrl }}" target="_blank" rel="noopener">
                    <img src="{{ $finalUrl }}" alt="Foto kondisi akhir" loading="lazy" style="margin-top:0.5rem; width:220px; max-width:100%; height:150px; object-fit:cover; border-radius:0.5rem;">
                </a>
            @endif
        @endif
    </div>
</div>
