<?php

namespace Tests\Feature\Onboarding;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\MemberCategory;
use App\Models\User;
use App\Notifications\MemberInvitation;
use App\Services\MemberInviter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Traits\Localizable;
use Livewire\Livewire;
use Tests\TestCase;

class MemberInvitationTest extends TestCase
{
    use Localizable, RefreshDatabase;

    public function test_inviting_an_unclaimed_member_emails_them_and_records_when(): void
    {
        Notification::fake();
        $member = User::factory()->create(['activated_at' => null]);

        $this->assertTrue(app(MemberInviter::class)->invite($member));

        Notification::assertSentTo($member, MemberInvitation::class);
        $this->assertNotNull($member->fresh()->invited_at);
    }

    public function test_claimed_members_and_admins_are_not_invited(): void
    {
        Notification::fake();
        $claimed = User::factory()->create(['activated_at' => now()]);
        $admin = User::factory()->admin()->create(['activated_at' => null]);

        $this->assertFalse(app(MemberInviter::class)->invite($claimed));
        $this->assertFalse(app(MemberInviter::class)->invite($admin));

        Notification::assertNothingSent();
        $this->assertNull($claimed->fresh()->invited_at);
    }

    public function test_the_email_links_to_the_claim_page_without_putting_the_email_in_the_url(): void
    {
        $member = User::factory()->create(['name' => 'Aisyah', 'email' => 'aisyah@example.com', 'activated_at' => null]);

        $mail = (new MemberInvitation)->toMail($member);
        $html = view($mail->view['html'], $mail->viewData)->render();
        $text = view($mail->view['text'], $mail->viewData)->render();

        $this->assertSame(route('claim'), $mail->viewData['url']);
        $this->assertStringContainsString('href="'.route('claim').'"', $html);
        $this->assertStringContainsString('aisyah@example.com', $html);
        $this->assertStringContainsString('Join PENTANA, Aisyah!', $html);
        $this->assertStringContainsString('Join now: '.route('claim'), $text);
    }

    public function test_the_email_follows_the_member_language(): void
    {
        config(['app.locales' => 'en,ms,id']);
        $member = User::factory()->create(['name' => 'Aisyah', 'locale' => 'ms', 'activated_at' => null]);

        $html = $this->withLocale('ms', function () use ($member) {
            $mail = (new MemberInvitation)->toMail($member);
            $this->assertSame('Anda dijemput menyertai PENTANA', $mail->subject);

            return view($mail->view['html'], $mail->viewData)->render();
        });

        $this->assertStringContainsString('Sertai PENTANA, Aisyah!', $html);
        $this->assertStringContainsString('Sertai sekarang', $html);
    }

    public function test_the_offer_shows_the_member_own_monthly_fee(): void
    {
        $category = MemberCategory::create(['name' => 'Staff', 'default_fee' => 30]);
        $member = User::factory()->create(['member_category_id' => $category->id, 'custom_fee' => 12.5, 'activated_at' => null]);

        $mail = (new MemberInvitation)->toMail($member);
        $html = view($mail->view['html'], $mail->viewData)->render();

        // The custom fee wins over the category default, as on their bills.
        $this->assertStringContainsString('MYR 12.50', $html);
        $this->assertStringNotContainsString('MYR 30.00', $html);
        $this->assertStringContainsString('Member category: Staff', $html);
    }

    public function test_no_price_is_shown_when_the_member_has_no_fee(): void
    {
        $member = User::factory()->create(['member_category_id' => null, 'custom_fee' => null, 'activated_at' => null]);

        $mail = (new MemberInvitation)->toMail($member);

        $this->assertNull($mail->viewData['fee']);
        $this->assertStringNotContainsString('MYR', view($mail->view['html'], $mail->viewData)->render());
    }

    public function test_member_names_are_escaped_in_the_html(): void
    {
        $member = User::factory()->create(['name' => '<script>x</script>', 'activated_at' => null]);

        $mail = (new MemberInvitation)->toMail($member);
        $html = view($mail->view['html'], $mail->viewData)->render();

        $this->assertStringNotContainsString('<script>x</script>', $html);
    }

    public function test_admins_can_invite_from_the_users_table(): void
    {
        Notification::fake();
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));
        $unclaimed = User::factory()->create(['activated_at' => null]);
        $claimed = User::factory()->create(['activated_at' => now()]);

        Livewire::test(ListUsers::class)
            ->assertTableActionVisible('invite', $unclaimed)
            ->assertTableActionHidden('invite', $claimed)
            ->callTableAction('invite', $unclaimed)
            ->assertHasNoTableActionErrors();

        Notification::assertSentTo($unclaimed, MemberInvitation::class);
        Notification::assertNotSentTo($claimed, MemberInvitation::class);
    }

    public function test_the_bulk_action_skips_members_who_cannot_be_invited(): void
    {
        Notification::fake();
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));
        $a = User::factory()->create(['activated_at' => null]);
        $b = User::factory()->create(['activated_at' => null]);
        $claimed = User::factory()->create(['activated_at' => now()]);

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('invite', [$a, $b, $claimed]);

        Notification::assertSentTo([$a, $b], MemberInvitation::class);
        Notification::assertNotSentTo($claimed, MemberInvitation::class);
        $this->assertNotNull($a->fresh()->invited_at);
        $this->assertNull($claimed->fresh()->invited_at);
    }

    public function test_the_email_brings_its_own_dark_mode_colours(): void
    {
        $member = User::factory()->create(['activated_at' => null]);

        $mail = (new MemberInvitation)->toMail($member);
        $html = view($mail->view['html'], $mail->viewData)->render();

        // Declared, so mail apps use our palette instead of recolouring on their own.
        $this->assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
        // Apple Mail and Outlook each get the membership card's dark colours.
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        $this->assertStringContainsString('[data-ogsb] .em-ticket { background-color: #2a2112 !important; }', $html);
        $this->assertStringContainsString('[data-ogsc] .em-ticket-strong { color: #fef3c7 !important; }', $html);
        $this->assertStringContainsString('class="em-ticket"', $html);
    }
}
