@if (count($list) === 0)
    <p class="none">{{ __('Nobody') }}</p>
@else
    <ul>
        @foreach ($list as $member)
            <li><span class="box">{{ $tick ? '☐' : '' }}</span>{{ $member['name'] }}@if ($member['disabled']) <span class="tag">{{ __('(disabled)') }}</span>@endif</li>
        @endforeach
    </ul>
@endif
