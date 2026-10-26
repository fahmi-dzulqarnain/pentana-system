<?php

namespace Tests\Feature\Support;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupportAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private SupportMessage $message;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $path = UploadedFile::fake()->image('s.png')->store('support', 'local');
        $this->owner = User::factory()->create(['activated_at' => now()]);
        $ticket = SupportTicket::factory()->for($this->owner)->create();
        $this->message = SupportMessage::factory()->for($ticket, 'ticket')
            ->create(['user_id' => $this->owner->id, 'attachment_path' => $path]);
    }

    private function url(?SupportMessage $message = null): string
    {
        return route('support.attachment', $message ?? $this->message);
    }

    public function test_the_owner_can_view_their_screenshot(): void
    {
        $this->actingAs($this->owner)->get($this->url())
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_an_admin_can_view_any_screenshot(): void
    {
        $this->actingAs(User::factory()->admin()->create(['activated_at' => now()]))->get($this->url())->assertOk();
    }

    public function test_another_member_gets_not_found(): void
    {
        $this->actingAs(User::factory()->create(['activated_at' => now()]))->get($this->url())->assertNotFound();
    }

    public function test_a_disabled_owner_gets_not_found(): void
    {
        $this->owner->forceFill(['disabled_at' => now()])->save();

        $this->actingAs($this->owner)->get($this->url())->assertNotFound();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get($this->url())->assertRedirect();
    }

    public function test_a_message_without_attachment_is_not_found(): void
    {
        $plain = SupportMessage::factory()->for($this->message->ticket, 'ticket')->create(['user_id' => $this->owner->id]);

        $this->actingAs($this->owner)->get($this->url($plain))->assertNotFound();
    }
}
