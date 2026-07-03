<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Kirim Push Notification</x-slot>
        <x-slot name="description">
            Kirim notifikasi ke semua pengguna (broadcast) atau ke pelanggan tertentu.
        </x-slot>

        <form wire:submit="send">
            {{ $this->form }}

            <div class="mt-6">
                <x-filament::button type="submit" icon="heroicon-o-paper-airplane">
                    Kirim Notifikasi
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
