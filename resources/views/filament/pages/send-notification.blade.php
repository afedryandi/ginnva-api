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

            @if ($preview)
                <div class="mt-6 rounded-lg border border-gray-200 p-4 text-sm dark:border-white/10" data-testid="recipient-preview">
                    <div class="font-semibold">Pratinjau penerima</div>
                    <p class="mt-1">
                        <span class="tabular-nums font-semibold">{{ number_format($preview['recipients'], 0, ',', '.') }}</span> penerima,
                        <span class="tabular-nums font-semibold">{{ number_format($preview['devices'], 0, ',', '.') }}</span> perangkat akan dikirimi.
                        @if ($preview['excluded'] !== null)
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ number_format($preview['excluded'], 0, ',', '.') }} pelanggan dilewati karena {{ $preview['excluded_reason'] }}.
                            </span>
                        @endif
                    </p>
                    @foreach ($preview['warnings'] as $warning)
                        <p class="mt-2 font-medium text-warning-600 dark:text-warning-400">{{ $warning }}</p>
                    @endforeach
                </div>
            @endif

            <div class="mt-6 flex flex-wrap gap-3">
                <x-filament::button
                    type="button"
                    color="gray"
                    wire:click="previewRecipients"
                    wire:loading.attr="disabled"
                    wire:target="previewRecipients"
                    icon="heroicon-o-eye"
                >
                    Pratinjau Penerima
                </x-filament::button>

                <x-filament::button
                    type="button"
                    wire:click="send"
                    wire:confirm="{{ $confirmMessage }}"
                    wire:loading.attr="disabled"
                    wire:target="send"
                    icon="heroicon-o-paper-airplane"
                >
                    <span wire:loading.remove wire:target="send">{{ ! empty($this->data['schedule']) ? 'Jadwalkan Notifikasi' : 'Kirim Notifikasi' }}</span>
                    <span wire:loading wire:target="send">Mengirim...</span>
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
