<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeSuperAdmin extends Command
{
    protected $signature = 'app:make-super-admin {email}';

    protected $description = 'Promote an existing user to super-admin';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user with email {$email}.");

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => true, 'is_super_admin' => true])->save();

        $this->info("{$user->name} is now a super-admin.");

        return self::SUCCESS;
    }
}
