<div wire:key="claim-request" style="width:100%;max-width:28rem;margin:0 auto;padding:3rem 1.5rem;box-sizing:border-box;">
    <h1 style="font-size:1.5rem;font-weight:700;margin:0 0 0.25rem;color:#111827;">
        {{ __('Activate your account') }}
    </h1>

    @if ($sent)
        <div style="margin-top:1rem;padding:0.875rem 1rem;border:1px solid #86efac;background-color:#f0fdf4;border-radius:0.5rem;color:#166534;font-size:0.875rem;">
            <p style="margin:0 0 0.5rem;font-weight:600;">{{ __('Check your inbox') }}</p>
            <p style="margin:0;">{{ __('If :email is waiting to be activated, we have emailed it a link to finish setting up. It can take a few minutes, so check your spam folder too.', ['email' => $email]) }}</p>
        </div>

        <ul style="margin:1.25rem 0 0;padding-left:1.25rem;color:#4b5563;font-size:0.875rem;line-height:1.6;">
            <li>{{ __('Already activated? Sign in, or reset your password from the sign-in page.') }}</li>
            <li>{{ __('No email after a while? Your invitation may have expired. Ask your admin to send you a new one.') }}</li>
        </ul>

        <button type="button" wire:click="$set('sent', false)"
            style="margin-top:1.5rem;width:100%;padding:0.625rem 1rem;border:1px solid #d1d5db;border-radius:0.5rem;background-color:#ffffff;color:#374151;font-size:0.95rem;font-weight:600;cursor:pointer;">
            {{ __('Use a different email') }}
        </button>
    @else
        <p style="margin:0 0 1.5rem;color:#6b7280;">
            {{ __('Enter the email your admin registered for you. We will email you a link to set your password.') }}
        </p>

        <form wire:submit="send">
            <label for="claim-email" style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:0.375rem;color:#374151;">
                {{ __('Email address') }}
            </label>
            <input
                id="claim-email"
                type="email"
                wire:model="email"
                autocomplete="email"
                style="width:100%;box-sizing:border-box;padding:0.625rem 0.75rem;border:1px solid #d1d5db;border-radius:0.5rem;font-size:0.95rem;"
            >
            @error('email')
                <p style="margin:0.375rem 0 0;color:#dc2626;font-size:0.8125rem;">{{ $message }}</p>
            @enderror

            @if ($throttleMessage)
                <p style="margin:0.375rem 0 0;color:#dc2626;font-size:0.8125rem;">{{ $throttleMessage }}</p>
            @endif

            <button
                type="submit"
                style="margin-top:1.5rem;width:100%;padding:0.625rem 1rem;border:none;border-radius:0.5rem;background-color:#f59e0b;color:#ffffff;font-size:0.95rem;font-weight:600;cursor:pointer;"
            >
                <span wire:loading.remove wire:target="send">{{ __('Email me my link') }}</span>
                <span wire:loading wire:target="send">{{ __('Sending…') }}</span>
            </button>
        </form>
    @endif

    <p style="margin:2rem 0 0;padding-top:1.25rem;border-top:1px solid #e5e7eb;text-align:center;font-size:0.875rem;color:#4b5563;">
        {{ __('Already activated your account?') }}
        <a href="/account/login" style="color:#b45309;font-weight:600;">{{ __('Sign in') }}</a>
    </p>
</div>
