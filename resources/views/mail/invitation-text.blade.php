{!! __('Join :app, :name!', ['app' => config('app.name'), 'name' => $name]) !!}

{!! __('Your organisation has set up a :app membership for you. Here is what it includes and what it costs.', ['app' => config('app.name')]) !!}

{!! __('Your membership') !!}
@if ($fee)
MYR {!! $fee !!} {!! __('/ month') !!}
@endif
@if ($category)
{!! __('Member category: :category', ['category' => $category]) !!}
@endif
{!! __('Your account email') !!}: {!! $email !!}
{!! __('Offer valid until :date', ['date' => $expires]) !!}

{!! __('What your membership includes') !!}
- {!! __('Catered lunches') !!}: {!! __('Vote on the menu and order your meal every lunch day.') !!}
- {!! __('Activities and outings') !!}: {!! __('Register for events and see who else is going.') !!}
- {!! __('Welfare fund') !!}: {!! __('Be part of your organisation\'s welfare fund, there for members when it matters.') !!}
- {!! __('Everything in one app') !!}: {!! __('Dues, receipts and reminders on the web, iPhone and iPad.') !!}

{!! __('How to join') !!}
1. {!! __('Open the claim page and enter the email above.') !!}
2. {!! __('Confirm your date of birth and choose a password.') !!}
3. {!! __('Pay your monthly fee in the app by uploading your payment proof.') !!}

{!! __('Join now') !!}: {!! $url !!}

{!! __('If you do not claim your account by :date, it will be disabled. Your admin can always send you a new invitation.', ['date' => $expires]) !!}

{!! __('Questions about your membership or your fee? Just ask your :app admin.', ['app' => config('app.name')]) !!}

{!! __('Hope to see you inside,') !!}
{!! __('The :app team', ['app' => config('app.name')]) !!}
