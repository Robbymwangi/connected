<?php

namespace App\Console\Commands;

use App\Models\Institution;
use App\Models\User;
use App\Support\CurrentInstitution;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/* The one-time setup of a real school (docs/spec/access-model.md, "Provisioning"): the institution
   and its first administrator, before the ordinary account system has anyone to provision from.
   Every other account is then made in the panel by that administrator. Not a role, not a screen,
   and never the demo seeder, which must not run in production.

   The password is always asked for, never taken as an option, so it stays out of the shell's
   history and the server's process list. The admin's email is unique among admin accounts across
   every school (unique(kind, email), ADR 0014), which the check below states before the database
   would refuse it. */
class ProvisionSchool extends Command
{
    protected $signature = 'connected:provision-school {--school= : The school\'s name} {--admin-name= : The first administrator\'s full name} {--admin-email= : The first administrator\'s email}';

    protected $description = 'Create a school and its first administrator (one time per school)';

    public function handle(CurrentInstitution $current): int
    {
        $input = [
            'school' => $this->option('school') ?? $this->ask('School name'),
            'name' => $this->option('admin-name') ?? $this->ask('Administrator\'s full name'),
            'email' => $this->option('admin-email') ?? $this->ask('Administrator\'s email'),
            'password' => $this->secret('Administrator\'s password (at least 12 characters)'),
            'password_confirmation' => $this->secret('The password again'),
        ];

        $validator = Validator::make($input, [
            'school' => ['required', 'string', 'max:255', 'unique:institutions,name'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        $validator->after(function ($validator) use ($input): void {
            if (User::withoutGlobalScopes()->where('kind', User::ADMIN)->where('email', $input['email'])->exists()) {
                $validator->errors()->add('email', 'An administrator with this email already exists.');
            }
        });

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $school = Institution::create(['name' => $input['school']]);
        $current->actingFor($school->id, fn () => User::create([
            'institution_id' => $school->id,
            'name' => $input['name'],
            'email' => $input['email'],
            'kind' => User::ADMIN,
            'password' => $input['password'],
        ]));

        $this->info("Created {$school->name} and its administrator {$input['email']}. Sign in at /admin; production asks for an authenticator app at the first sign-in.");

        return self::SUCCESS;
    }
}