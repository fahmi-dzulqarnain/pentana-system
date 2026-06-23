<div style="display:flex;flex-direction:column;gap:0.75rem;">
    @forelse($fields as $field)
        @php($value = $answers[$field['key']] ?? null)
        <div>
            <div style="font-size:0.75rem;font-weight:600;color:#6b7280;">{{ $field['label'] }}</div>
            <div>
                @if(is_bool($value))
                    {{ $value ? 'Yes' : 'No' }}
                @elseif($value === null || $value === '')
                    <span style="color:#9ca3af;">—</span>
                @else
                    {{ $value }}
                @endif
            </div>
        </div>
    @empty
        <div style="color:#9ca3af;">No questions for this activity.</div>
    @endforelse
</div>
