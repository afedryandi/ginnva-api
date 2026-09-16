{{--
    Diagram visual "Kondisi Kendaraan" -- baca-saja (diminta user
    2026-09-16). Foto sama persis dengan yang dipakai mobile app
    (public/images/spk-car-diagram.png, disalin dari assets/images/
    car-diagram-top.png di ginnva-mobile), titik overlay diposisikan
    pakai persentase (x_percent/y_percent) sama seperti komponen
    DamageDiagram.tsx -- BUKAN pixel absolut, supaya konsisten di
    ukuran layar apa pun. Filament belum bisa MENAMBAH/menghapus titik
    dari sini, cuma menampilkan yang sudah diisi dari mobile.
--}}
@php
    $codeColors = [
        'C' => '#ef4444',
        'B' => '#f97316',
        'P' => '#eab308',
        'G' => '#3b82f6',
        'M' => '#8b5cf6',
        'OS' => '#22c55e',
    ];
@endphp

<div style="max-width: 320px; margin: 0 auto;">
    <div style="position: relative; width: 100%; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; background: #fff;">
        <img
            src="{{ asset('images/spk-car-diagram.png') }}"
            alt="Diagram kondisi kendaraan"
            style="width: 100%; display: block;"
        />

        @foreach ($record?->damageMarks ?? [] as $mark)
            <span
                title="{{ $mark->code }} — {{ \App\Models\Spk::DAMAGE_CODE_LABELS[$mark->code] ?? $mark->code }}"
                style="
                    position: absolute;
                    left: {{ $mark->x_percent }}%;
                    top: {{ $mark->y_percent }}%;
                    transform: translate(-50%, -50%);
                    width: 18px;
                    height: 18px;
                    border-radius: 9999px;
                    background: {{ $codeColors[$mark->code] ?? '#666' }};
                    border: 2px solid #ffffff;
                    box-shadow: 0 0 0 1px rgba(0,0,0,.15);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 8px;
                    font-weight: 700;
                    color: #ffffff;
                    line-height: 1;
                "
            >{{ $mark->code }}</span>
        @endforeach
    </div>

    @if (($record?->damageMarks ?? collect())->isEmpty())
        <p style="text-align: center; font-size: 11px; color: #9ca3af; margin-top: 6px;">
            Belum ada titik kerusakan ditandai dari aplikasi.
        </p>
    @endif
</div>
