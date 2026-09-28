{{-- Catatan kaki laporan keuangan PDF (audit Laporan Keuangan 2026-09-29): basis data, waktu cetak, pencetak. --}}
<div style="margin-top: 24px; padding-top: 8px; border-top: 1px solid #999; font-size: 9px; color: #555;">
    Sumber data: Jurnal Umum berstatus posted (jurnal draft tidak dihitung). Filter toko mengikuti pilihan saat mencetak;
    memilih toko tertentu tidak mencakup jurnal pusat (tanpa toko).<br>
    Dicetak {{ now()->translatedFormat('d M Y H:i') }} oleh {{ auth()->user()?->name ?? '—' }}.
</div>
