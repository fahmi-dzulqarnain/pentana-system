<?php

namespace App\Livewire;

use App\Filament\Pages\Welcome;
use App\Models\User;
use App\Services\AccountClaimService;
use App\Support\PasswordStrengthMeter;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

class ClaimAccount extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    /** Session keys holding the member a valid link pointed at. Server-side, so the client can't swap them. */
    private const SESSION_USER = 'claim.user_id';

    private const SESSION_INVITED = 'claim.invited_at';

    public bool $linkValid = false;

    public string $memberName = '';

    /** @var array<string, mixed> */
    public array $data = [];

    /** Reached only through the signed link in the invitation email (the route checks the signature). */
    public function mount(int $user): void
    {
        $invited = (int) request()->query('invited');
        $member = app(AccountClaimService::class)->userForLink($user, $invited);

        session()->forget([self::SESSION_USER, self::SESSION_INVITED]);

        if ($member !== null) {
            session()->put([self::SESSION_USER => $member->id, self::SESSION_INVITED => $invited]);
            $this->linkValid = true;
            $this->memberName = $member->name;
        }

        // Initialize the form state up front so the Wizard's fields have resolvable
        // Livewire properties. The native(false) date picker uses
        // $wire.entangle('data.birthday') in the browser, which throws
        // "property data.birthday cannot be found" if the key was never initialized.
        $this->claimForm->fill();
    }

    /** The member this session's link proved, re-checked so a claimed or superseded link stops working. */
    private function member(): ?User
    {
        $id = session(self::SESSION_USER);

        return $id === null ? null : app(AccountClaimService::class)->userForLink((int) $id, (int) session(self::SESSION_INVITED));
    }

    public function claimForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make([
                    Step::make(__('Confirm your details'))
                        ->description(__('Make sure this is you, then pick your date of birth.'))
                        ->schema([
                            DatePicker::make('birthday')
                                ->label(__('Date of birth'))
                                ->required()
                                ->native(false)
                                ->maxDate(now()),
                        ]),
                    Step::make(__('Set a password'))
                        ->description(__('Choose a password you will use to sign in.'))
                        ->schema([
                            TextInput::make('password')
                                ->label(__('Password'))
                                ->password()
                                ->required()
                                ->rule(Password::defaults())
                                ->belowContent(fn (): Htmlable => PasswordStrengthMeter::make(array_filter([$this->member()?->email, $this->memberName])))
                                ->confirmed(),
                            TextInput::make('password_confirmation')
                                ->password()
                                ->required()
                                ->label(__('Confirm password'))
                                ->dehydrated(false),
                        ]),
                ])
                    ->contained(false)
                    ->submitAction(view('livewire.claim-submit-button')),
            ]);
    }

    public function submit(): void
    {
        // WHO gets claimed comes from the session the signed link set up, never from component
        // state the browser can change.
        $user = $this->member();
        abort_if($user === null, 403);

        $state = $this->claimForm->getState();

        app(AccountClaimService::class)->claim($user, $state['birthday'], $state['password']);

        session()->forget([self::SESSION_USER, self::SESSION_INVITED]);
        Auth::login($user);

        // Freshly-activated members are offered a passkey before anything else.
        $this->redirect(Welcome::getUrl(panel: 'account'), navigate: true);
    }

    #[Layout('components.layouts.guest')]
    public function render()
    {
        return view('livewire.claim-account')->title(__('Activate your account').' · PENTANA');
    }
}
