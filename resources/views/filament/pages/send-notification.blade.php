<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Kirim Push Notification</x-slot>
        <x-slot name="description">
            Kirim notifikasi ke semua pengguna (broadcast) atau ke pelanggan tertentu.
        </x-slot>

        @php
            // Konfirmasi & disable saat kirim (audit 2026-09-30) -- SEBELUMNYA tombol
            // langsung eksekusi tanpa konfirmasi walau mode default-nya broadcast ke
            // SEMUA pengguna, dan tidak ada guard anti double-klik.
            $isBroadcast = ($this->data['audience'] ?? 'customer') === 'partner'
                ? ($this->data['broadcast_partner'] ?? true)
                : ($this->data['broadcast'] ?? true);
            $confirmMessage = $isBroadcast
                ? 'Yakin ingin mengirim notifikasi ini ke SEMUA pengguna? Aksi ini tidak bisa dibatalkan.'
                : 'Kirim notifikasi ini ke penerima yang dipilih?';
        @endphp

        <div>
            {{ $this->form }}

            <div class="mt-6">
                <x-filament::button
                    type="button"
                    wire:click="send"
                    wire:confirm="{{ $confirmMessage }}"
                    wire:loading.attr="disabled"
                    wire:target="send"
                    icon="heroicon-o-paper-airplane"
                >
                    <span wire:loading.remove wire:target="send">Kirim Notifikasi</span>
                    <span wire:loading wire:target="send">Mengirim...</span>
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
