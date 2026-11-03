<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('How to pay') }}</x-slot>
        <x-slot name="description">{{ __('Transfer to this account or scan the DuitNow QR, using your reference.') }}</x-slot>

        @include('filament.support.payment-details', ['details' => $this->details()])
    </x-filament::section>
</x-filament-widgets::widget>
