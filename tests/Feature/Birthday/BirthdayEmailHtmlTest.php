<?php

namespace Tests\Feature\Birthday;

use App\Models\User;
use App\Notifications\BirthdayWish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BirthdayEmailHtmlTest extends TestCase
{
    use RefreshDatabase;

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

        // Rendered unescaped — escaping would produce &lt;p&gt; instead.
        $this->assertStringContainsString('<p>Hi <strong>Aisyah</strong>!</p>', $html);
    }
}
