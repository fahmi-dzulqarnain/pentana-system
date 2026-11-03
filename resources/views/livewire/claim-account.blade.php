<div wire:key="claim-card" style="flex:1;display:flex;flex-direction:column;">
    <style>
        /* Keep the multi-step Wizard, but render its step header flat: drop the card
           box (transparent background, no ring/shadow/border) and the horizontal
           scrollbar. The header is a column grid with auto-width columns + overflow-x:
           auto, so two steps with long descriptions overflow and scroll. Equal,
           shrinkable columns (minmax(0, 1fr)) make both steps always fit. */
        .fi-sc-wizard-header {
            grid-auto-columns: minmax(0, 1fr);
            overflow: visible;
            background-color: transparent;
            box-shadow: none;
            border: 0;
            border-radius: 0;
        }

        /* Full-page wizard: the header group (title + subtitle + step indicators) sits
           at the top, the active step's field is centred in the space below, and the
           footer button is pinned bottom-right. We keep the single Filament Wizard
           (its step logic stays intact) and lay its three regions out with a grid -
           header (auto) / active step (1fr) / footer (auto). Inactive steps are
           position:absolute (Filament), so they don't consume a grid row. */
        .claim-stage {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 2.5rem 2rem;
            box-sizing: border-box;
        }
        .claim-stage .fi-sc-wizard {
            flex: 1;
            display: grid;
            grid-template-rows: auto 1fr auto;
            position: relative;
        }
        .claim-stage .fi-sc-wizard .fi-sc-wizard-step.fi-active {
            display: flex;
            flex-direction: column;
            justify-content: center;
            margin-top: 0;
        }
        /* Centre the field column horizontally and keep it readable. */
        .claim-stage .fi-sc-wizard .fi-sc-wizard-step.fi-active > * {
            width: 100%;
            max-width: 28rem;
            margin-inline: auto;
        }
        /* The footer is already display:flex; justify-content:space-between, so the
           Next / Activate button lands on the right. Drop its own padding so it lines
           up with the header padding above. */
        .claim-stage .fi-sc-wizard .fi-sc-wizard-footer {
            padding-inline: 0;
            padding-bottom: 0;
        }
    </style>

    @if (! $linkValid)
        <div style="width:100%;max-width:28rem;margin:0 auto;padding:3rem 1.5rem;box-sizing:border-box;">
            <h1 style="font-size:1.5rem;font-weight:700;margin:0 0 0.25rem;color:#111827;">
                {{ __('This link no longer works') }}
            </h1>

            <p style="margin:0 0 1.5rem;color:#6b7280;">
                {{ __('It may have expired, been replaced by a newer invitation, or the account is already active.') }}
            </p>

            <a href="{{ route('claim') }}" wire:navigate
                style="display:block;text-align:center;padding:0.625rem 1rem;border-radius:0.5rem;background-color:#f59e0b;color:#ffffff;font-size:0.95rem;font-weight:600;text-decoration:none;">
                {{ __('Email me a new link') }}
            </a>

            <p style="margin:2rem 0 0;padding-top:1.25rem;border-top:1px solid #e5e7eb;text-align:center;font-size:0.875rem;color:#4b5563;">
                {{ __('Already activated your account?') }}
                <a href="/account/login" style="color:#b45309;font-weight:600;">{{ __('Sign in') }}</a>
            </p>
        </div>
    @else
        <div class="claim-stage">
            <header>
                <h1 style="font-size:1.5rem;font-weight:700;margin:0 0 0.25rem;color:#111827;">
                    {{ __('Activate your account') }}
                </h1>

                <p style="margin:0;color:#6b7280;">
                    {!! __('Welcome, :name.', ['name' => '<strong style="color:#111827;">'.e($memberName).'</strong>']) !!}
                    {{ __('Just two quick steps to finish setting up.') }}
                </p>
            </header>

            {{ $this->claimForm }}
        </div>
    @endif
</div>
