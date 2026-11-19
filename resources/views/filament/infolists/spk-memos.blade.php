{{--
    Memo Barang yang dibuat dari SPK ini (halaman Lihat SPK): satu baris per memo dengan nomor sebagai tautan, status pengajuan, dan jumlah barang.
    Gaya polos karena panel memakai CSS Filament terkompilasi.
--}}
@php
    $record = $getRecord();
    $memos = $record->materialMemos()->withCount('items')->latest()->get();
@endphp

@if ($memos->isEmpty())
    <div style="color:rgb(107 114 128);font-size:.875rem;">Belum ada memo barang dari SPK ini.</div>
@else
    <div style="display:flex;flex-direction:column;">
        @foreach ($memos as $memo)
            <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem 1rem;padding:.7rem 0;{{ $loop->first ? '' : 'border-top:1px solid rgba(128,128,128,.2);' }}">
                <div>
                    <a href="{{ \App\Filament\Resources\MaterialMemoResource::getUrl('view', ['record' => $memo]) }}"
                       style="font-weight:600;font-size:.875rem;color:rgb(var(--primary-600));text-decoration:none;">
                        {{ $memo->memo_number }}
                    </a>
                    <div style="font-size:.75rem;color:rgb(107 114 128);">
                        {{ $memo->items_count }} barang · {{ $memo->created_at?->translatedFormat('d M Y') }}
                    </div>
                </div>
                @if ($memo->request_status)
                    <span style="font-size:.75rem;font-weight:600;color:rgb(107 114 128);">
                        {{ \App\Models\MaterialMemo::REQUEST_STATUS_LABELS[$memo->request_status] ?? $memo->request_status }}
                    </span>
                @endif
            </div>
        @endforeach
    </div>
@endif
