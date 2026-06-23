<?php

namespace Tests\Feature\Birthday;

use App\Models\AutoEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BirthdaySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_email_has_type_and_active_flag(): void
    {
        $email = AutoEmail::create([
            'subject' => 'Happy Birthday!',
            'body' => 'Hi {name}, happy birthday!',
            'type' => 'birthday',
            'is_active' => true,
        ]);

        $email->refresh();
        $this->assertSame('birthday', $email->type);
        $this->assertTrue($email->is_active);
    }

    public function test_auto_email_defaults_to_one_off(): void
    {
        $email = AutoEmail::create(['subject' => 'Notice', 'body' => 'Hello']);
        $this->assertSame('one_off', $email->fresh()->type);
    }

    public function test_user_tracks_birthday_email_sent_at(): void
    {
        $user = User::factory()->create(['birthday_email_sent_at' => now()]);
        $this->assertInstanceOf(Carbon::class, $user->fresh()->birthday_email_sent_at);
    }
}
