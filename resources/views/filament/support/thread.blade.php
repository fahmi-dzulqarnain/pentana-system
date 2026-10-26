{{-- The ticket conversation, oldest first. Bodies are the writers' own words: always escaped. --}}
@php
    $authorLabel = function ($message) use ($viewerIsAdmin, $viewerId) {
        if ($message->user_id === $viewerId) {
            return __('You');
        }
        if ($message->from_committee && ! $viewerIsAdmin) {
            return __('Committee');
        }

        return $message->author?->display_name ?? __('Committee');
    };
@endphp

<div style="display:flex;flex-direction:column;gap:0.75rem;">
    @foreach ($messages as $message)
        @php($mine = $message->user_id === $viewerId)
        {{-- Inline styles: Filament's precompiled CSS doesn't ship arbitrary Tailwind utilities. --}}
        <div style="max-width:80%;padding:0.75rem 1rem;border-radius:0.75rem;{{ $mine ? 'margin-left:auto;' : '' }}background:{{ $message->from_committee ? 'rgba(37,99,235,.12)' : 'rgba(127,127,127,.10)' }};">
            <div style="font-size:0.8rem;opacity:.7;margin-bottom:0.25rem;">
                <strong>{{ $authorLabel($message) }}</strong>
                · {{ $message->created_at->diffForHumans() }}
            </div>
            <div style="white-space:normal;word-break:break-word;">{!! nl2br(e($message->body)) !!}</div>
            @if ($message->attachment_path)
                <a href="{{ route('support.attachment', $message) }}" target="_blank" rel="noopener" style="display:block;margin-top:0.5rem;">
                    <img src="{{ route('support.attachment', $message) }}" alt="{{ __('Screenshot') }}"
                         style="max-width:100%;max-height:20rem;border-radius:0.5rem;">
                </a>
            @endif
        </div>
    @endforeach
</div>
