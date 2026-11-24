<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Promotes an existing account to operator. This is the only way to grant report
 * access; public registration deliberately never does.
 */
class MakeUserAdmin extends Command
{
    protected $signature = 'pixelforge:make-admin {email : The account email address}';

    protected $description = 'Give an account access to the operator usage report';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        if ($user->is_admin) {
            $this->info("{$email} is already an operator.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_admin' => true])->save();
        $this->info("{$email} can now open the operator report.");

        return self::SUCCESS;
    }
}
