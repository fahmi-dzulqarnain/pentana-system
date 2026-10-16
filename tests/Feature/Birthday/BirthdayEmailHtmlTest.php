<?php

namespace Tests\Feature\Birthday;

use App\Models\User;
use App\Notifications\BirthdayWish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Traits\Localizable;
use Tests\TestCase;

class BirthdayEmailHtmlTest extends TestCase
{
    use Localizable, RefreshDatabase;

    public function test_to_mail_renders_the_body_via_the_html_view(): void
    {
        $mail = (new BirthdayWish('Happy Birthday!', '<p>Hi <strong>Aisyah</strong>!</p>'))
            ->toMail(User::factory()->create());

        $this->assertSame('mail.auto-email', $mail->view);
        $this->assertSame('<p>Hi <strong>Aisyah</strong>!</p>', $mail->viewData['body']);
    }

    public function test_the_mail_view_outputs_the_body_unescaped(): void
    {
        $html = view('mail.auto-email', ['body' => '<p>Hi <strong>Aisyah</strong>!</p>'])->render();

        // Rendered unescaped - escaping would produce &lt;p&gt; instead.
        $this->assertStringContainsString('<p>Hi <strong>Aisyah</strong>!</p>', $html);
    }

    public function test_without_a_custom_body_the_built_in_message_is_sent(): void
    {
        $mail = (new BirthdayWish)->toMail(User::factory()->create(['name' => 'Aisyah']));
        $html = view($mail->view['html'], $mail->viewData)->render();

        $this->assertSame('Happy birthday, Aisyah! 🎂', $mail->subject);
        $this->assertSame('mail.birthday', $mail->view['html']);
        $this->assertStringContainsString('Happy birthday, Aisyah!', $html);
        $this->assertStringContainsString('href="'.url('/account').'"', $html);
    }

    public function test_the_built_in_message_follows_the_member_language(): void
    {
        config(['app.locales' => 'en,ms,id']);
        $member = User::factory()->create(['name' => 'Budi', 'locale' => 'id']);

        $mail = $this->withLocale('id', fn () => (new BirthdayWish)->toMail($member));
        $html = $this->withLocale('id', fn () => view($mail->view['html'], $mail->viewData)->render());

        $this->assertSame('Selamat ulang tahun, Budi! 🎂', $mail->subject);
        $this->assertStringContainsString('Tim PENTANA', $html);
    }
}
