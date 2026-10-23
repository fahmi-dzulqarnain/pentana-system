<div>
    @unless ($dismissed)
        <x-filament::callout icon="heroicon-o-finger-print" color="primary"
            :heading="__('Add a passkey for faster sign-in')"
            :description="__('Use your fingerprint, face or device PIN instead of typing your password.')">
            <x-slot name="footer">
                <x-filament::button tag="a" :href="$profileUrl" size="sm">{{ __('Add one') }}</x-filament::button>
                <x-filament::button wire:click="dismiss" size="sm" color="gray" outlined>{{ __('Dismiss') }}</x-filament::button>
            </x-slot>
        </x-filament::callout>
    @endunless
</div>
