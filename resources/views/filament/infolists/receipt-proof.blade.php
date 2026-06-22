@php
    $path = $getRecord()?->proof_image_path;
    $url = $path ? \Illuminate\Support\Facades\Storage::disk('public')->url($path) : null;
@endphp

@if ($url)
    <div x-data="{ open: false, zoomed: false }">
        {{-- Thumbnail (click to open) --}}
        <img
            src="{{ $url }}"
            alt="Receipt proof"
            loading="lazy"
            x-on:click="open = true"
            class="h-44 w-auto cursor-zoom-in rounded-lg object-cover ring-1 ring-gray-950/10 transition hover:opacity-90 dark:ring-white/10"
        />

        {{-- Fullscreen lightbox --}}
        <template x-teleport="body">
            <div
                x-show="open"
                x-cloak
                x-transition.opacity
                x-on:keydown.escape.window="open = false; zoomed = false"
                x-on:click="open = false; zoomed = false"
                class="fixed inset-0 z-50 flex items-center justify-center overflow-auto bg-black/80 p-4"
                style="display: none;"
            >
                <img
                    src="{{ $url }}"
                    alt="Receipt proof"
                    x-on:click.stop="zoomed = ! zoomed"
                    x-bind:class="zoomed ? 'max-w-none cursor-zoom-out scale-150' : 'max-h-full max-w-full cursor-zoom-in'"
                    class="rounded-lg shadow-2xl transition-transform duration-200"
                />

                <button
                    type="button"
                    x-on:click="open = false; zoomed = false"
                    class="absolute right-4 top-4 text-4xl leading-none text-white/70 hover:text-white"
                    aria-label="Close"
                >&times;</button>
            </div>
        </template>
    </div>
@else
    <p class="text-sm text-gray-500 dark:text-gray-400">No receipt uploaded.</p>
@endif
