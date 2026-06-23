<div style="margin-top:1.5rem;">
    <x-filament::button wire:click="submit" wire:loading.attr="disabled" wire:target="submit" style="width:100%;">
        <span wire:loading.remove wire:target="submit">Activate my account</span>
        <span wire:loading wire:target="submit">Activating…</span>
    </x-filament::button>
</div>
