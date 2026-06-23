<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\AccountClaimService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

class ClaimAccount extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public string $email = '';

    /** @var 'lookup'|'claim' */
    public string $stage = 'lookup';

    public ?string $lookupMessage = null;

    public ?int $userId = null;

    public string $memberName = '';

    public string $memberGrade = '';

    /** @var array<string, mixed> */
    public array $data = [];

    public function lookup(): void
    {
        $this->validate(['email' => 'required|email']);

        $user = app(AccountClaimService::class)->claimable($this->email);

        if ($user) {
            $this->userId = $user->id;
            $this->memberName = $user->name;
            $this->memberGrade = $user->memberCategory?->name ?? '—';
            $this->stage = 'claim';
            $this->lookupMessage = null;

            return;
        }

        $existing = User::where('email', trim($this->email))->first();
        $this->lookupMessage = $existing
            ? 'This account is already active — please log in.'
            : "We couldn't find that email — please check with your admin.";
    }

    public function claimForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make([
                    Step::make('Confirm your details')
                        ->description('Make sure this is you, then pick your date of birth.')
                        ->schema([
                            DatePicker::make('birthday')
                                ->label('Date of birth')
                                ->required()
                                ->native(false)
                                ->maxDate(now()),
                        ]),
                    Step::make('Set a password')
                        ->description('Choose a password you will use to sign in.')
                        ->schema([
                            TextInput::make('password')
                                ->password()
                                ->required()
                                ->rule(Password::defaults())
                                ->confirmed(),
                            TextInput::make('password_confirmation')
                                ->password()
                                ->required()
                                ->label('Confirm password')
                                ->dehydrated(false),
                        ]),
                ])->submitAction(view('livewire.claim-submit-button')),
            ]);
    }

    public function submit(): void
    {
        // Never trust client-controllable state (e.g. $userId) for WHO gets claimed: re-resolve
        // the target from the validated email at submit time. This keeps a claim strictly at
        // "the owner of this email" and blocks activating an arbitrary account by tampering the id.
        $user = app(AccountClaimService::class)->claimable($this->email);
        abort_if($user === null, 403);

        $state = $this->claimForm->getState();

        app(AccountClaimService::class)->claim($user, $state['birthday'], $state['password']);

        Auth::login($user);

        $this->redirect('/account', navigate: true);
    }

    #[Layout('components.layouts.guest')]
    public function render()
    {
        return view('livewire.claim-account');
    }
}
