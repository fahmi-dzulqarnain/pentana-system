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

    @if ($stage === 'lookup')
        <div style="width:100%;max-width:28rem;margin:0 auto;padding:3rem 1.5rem;box-sizing:border-box;">
            <h1 style="font-size:1.5rem;font-weight:700;margin:0 0 0.25rem;color:#111827;">
                {{ __('Activate your account') }}
            </h1>

            <p style="margin:0 0 1.5rem;color:#6b7280;">
                {{ __('Enter the email your admin registered for you.') }}
            </p>

            <form wire:submit="lookup">
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

                @if ($lookupMessage)
                    <div style="margin-top:1rem;padding:0.75rem 1rem;border:1px solid #fcd34d;background-color:#fffbeb;border-radius:0.5rem;color:#92400e;font-size:0.875rem;">
                        {{ $lookupMessage }}
                        <a href="/account/login" wire:navigate style="color:inherit;font-weight:600;text-decoration:underline;">{{ __('Go to sign in') }}</a>
                    </div>
                @endif

                <button
                    type="submit"
                    style="margin-top:1.5rem;width:100%;padding:0.625rem 1rem;border:none;border-radius:0.5rem;background-color:#f59e0b;color:#ffffff;font-size:0.95rem;font-weight:600;cursor:pointer;"
                >
                    <span wire:loading.remove wire:target="lookup">{{ __('Find my account') }}</span>
                    <span wire:loading wire:target="lookup">{{ __('Searching…') }}</span>
                </button>
            </form>
        </div>
    @else
        <div class="claim-stage">
            <header>
                <h1 style="font-size:1.5rem;font-weight:700;margin:0 0 0.25rem;color:#111827;">
                    {{ __('Activate your account') }}
                </h1>

                <p style="margin:0;color:#6b7280;">
                    {!! __('Welcome, :name.', ['name' => '<strong style="color:#111827;">'.e($memberName).'</strong>'.($memberGrade !== '' ? ' ('.e($memberGrade).')' : '')]) !!}
                    {{ __('Just two quick steps to finish setting up.') }}
                </p>
            </header>

            {{ $this->claimForm }}
        </div>
    @endif
</div>
