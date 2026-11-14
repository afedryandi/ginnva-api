@php
    $user = auth()->user();
    $center = app(\App\Services\ApprovalCenterService::class);
@endphp

@if ($user && $center->canUse($user))
    @php $count = $center->badgeCount($user); @endphp

    <x-filament::icon-button
        icon="heroicon-o-inbox-arrow-down"
        tag="a"
        :href="\App\Filament\Pages\ApprovalCenter::getUrl()"
        label="Pusat Persetujuan"
        :badge="$count > 0 ? ($count > 99 ? '99+' : $count) : null"
        badge-color="danger"
        size="lg"
        :tooltip="$count > 0 ? $count . ' pengajuan menunggu keputusan' : 'Pusat Persetujuan'"
    />
@endif
