<?php

namespace Database\Factories;

use App\Enums\SupportTicketStatus;
use App\Enums\SupportTicketType;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicket>
 */
class SupportTicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => SupportTicketType::Question,
            'subject' => fake()->sentence(4),
            'status' => SupportTicketStatus::NeedsReply,
            'last_message_at' => now(),
        ];
    }
}
