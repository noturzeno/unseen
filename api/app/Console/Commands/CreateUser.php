<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('unseen:create-user {email} {--name=}')]
#[Description('Create an Unseen user (private MVP has no public sign-up)')]
class CreateUser extends Command
{
    public function handle(): int
    {
        $email = $this->argument('email');
        $name = $this->option('name') ?: $this->ask('Name');
        $password = $this->secret('Password (min 12 characters)');

        $validator = Validator::make(compact('email', 'name', 'password'), [
            'email' => ['required', 'email', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::create(compact('email', 'name', 'password'));

        $this->info("User {$email} created.");

        return self::SUCCESS;
    }
}
