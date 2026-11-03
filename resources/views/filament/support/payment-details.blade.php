{{-- Bank transfer details + DuitNow QR. Expects $details from App\Support\PaymentDetails::for(). --}}
<div style="display:flex; flex-wrap:wrap; gap:1rem; align-items:flex-start;">
    <dl style="flex:1 1 14rem; display:grid; grid-template-columns:auto 1fr; gap:0.5rem 1rem; margin:0; font-size:0.875rem;">
        <dt style="color:#6b7280;">{{ __('Bank') }}</dt>
        <dd style="margin:0; font-weight:600;">{{ $details['bank_name'] }}</dd>

        <dt style="color:#6b7280;">{{ __('Account number') }}</dt>
        <dd style="margin:0; display:flex; align-items:center; gap:0.5rem;" x-data="{ copied: false }">
            <span style="font-weight:700; font-size:1rem; letter-spacing:0.03em; font-variant-numeric:tabular-nums;">{{ $details['account_number'] }}</span>
            <button type="button"
                x-on:click="navigator.clipboard.writeText(@js($details['account_number'])); copied = true; setTimeout(() => copied = false, 1500)"
                style="font-size:0.75rem; font-weight:600; color:var(--primary-600,#d97706); background:none; border:0; padding:0; cursor:pointer;">
                <span x-show="! copied">{{ __('Copy') }}</span>
                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
            </button>
        </dd>

        <dt style="color:#6b7280;">{{ __('Account name') }}</dt>
        <dd style="margin:0; font-weight:600;">{{ $details['account_name'] }}</dd>

        <dt style="color:#6b7280;">{{ __('Reference') }}</dt>
        <dd style="margin:0; display:flex; align-items:center; gap:0.5rem;" x-data="{ copied: false }">
            <span style="font-weight:700; font-variant-numeric:tabular-nums;">{{ $details['reference'] }}</span>
            <button type="button"
                x-on:click="navigator.clipboard.writeText(@js($details['reference'])); copied = true; setTimeout(() => copied = false, 1500)"
                style="font-size:0.75rem; font-weight:600; color:var(--primary-600,#d97706); background:none; border:0; padding:0; cursor:pointer;">
                <span x-show="! copied">{{ __('Copy') }}</span>
                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
            </button>
        </dd>
    </dl>

    <div style="display:flex; flex-direction:column; align-items:center; gap:0.375rem;">
        <a href="{{ $details['qr_url'] }}" target="_blank" rel="noopener">
            <img src="{{ $details['qr_url'] }}" alt="{{ __('DuitNow QR for :name', ['name' => $details['account_name']]) }}"
                style="width:8.5rem; height:auto; border-radius:0.5rem; display:block;">
        </a>
        <a href="{{ $details['qr_url'] }}" download="kelab-pentana-duitnow-qr.jpg"
            style="font-size:0.75rem; font-weight:600; color:var(--primary-600,#d97706); text-decoration:none;">
            {{ __('Download') }}
        </a>
    </div>
</div>
