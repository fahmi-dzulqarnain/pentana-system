{{-- One group's names. On a published lunch each name opens a menu of every enabled option plus Not attending. --}}
@if (empty($members))
    <span class="lv-nobody">{{ __('Nobody') }}</span>
@else
    <div class="lv-names">
        @foreach ($members as $member)
            <span class="lv-name" wire:key="member-{{ $member['id'] }}">
                @if ($editable)
                    <x-filament::dropdown placement="bottom-start" teleport>
                        <x-slot name="trigger">
                            <button type="button" class="lv-name-btn">{{ $member['name'] }}</button>
                        </x-slot>

                        <x-filament::dropdown.list>
                            @foreach ($choices as $choice)
                                <x-filament::dropdown.list.item wire:click="setPick({{ $member['id'] }}, {{ $choice['id'] }})" x-on:click="close">
                                    {{ $choice['label'] }}
                                </x-filament::dropdown.list.item>
                            @endforeach
                            <x-filament::dropdown.list.item wire:click="setPick({{ $member['id'] }}, null)" x-on:click="close">
                                {{ __('Not attending') }}
                            </x-filament::dropdown.list.item>
                        </x-filament::dropdown.list>
                    </x-filament::dropdown>
                @else
                    {{ $member['name'] }}
                @endif

                @if ($member['disabled'])
                    <x-filament::badge color="gray" size="sm">{{ __('disabled') }}</x-filament::badge>
                @endif
            </span>
        @endforeach
    </div>
@endif
