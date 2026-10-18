<?php
namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * For when nobody can log in: sets a new password for an account from the terminal (on this PC
 * "Forgot your password?" is off, since it has no email settings). Passwords are stored hashed,
 * so they can't be looked up, only replaced.
 *   php artisan user:password                    lists the accounts
 *   php artisan user:password kevin@gmail.com    asks for the new password twice (not shown)
 */
class SetUserPassword extends Command
{
    protected $signature = 'user:password {email? : The email of the account}';

    protected $description = 'List the accounts, or set a new password for one (typed twice, not shown)';

    public function handle(): int
    {
        if (! $this->argument('email')) {
            $this->table(['Email', 'Name', 'Role', 'Two-step verification'], User::orderBy('id')->get()->map(fn ($u) => [
                $u->email, $u->name, $u->role === 'admin' ? 'Admin (owner)' : 'Cashier', $u->two_factor_confirmed_at ? 'on' : 'off',
            ]));
            $this->line('To set a password: php artisan user:password <email>');

            return self::SUCCESS;
        }

        $user = User::where('email', trim($this->argument('email')))->first();
        if (! $user) {
            $this->error("There is no account with the email {$this->argument('email')}. Run php artisan user:password to see them.");

            return self::FAILURE;
        }

        $password = (string) $this->secret("New password for {$user->name} (at least 8 characters)");
        // The same rule as changing it in Profile.
        $check = Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]]);
        if ($check->fails()) {
            $this->error($check->errors()->first('password'));

            return self::FAILURE;
        }
        if ((string) $this->secret('Type it again') !== $password) {
            $this->error('The two passwords are different. Nothing was changed.');

            return self::FAILURE;
        }

        // A new remember token too, so a browser that stayed logged in with the old password is logged out.
        $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();

        $this->info("Password changed for {$user->name} ({$user->email}). Log in at " . rtrim(config('app.url'), '/') . '/login');
        if ($user->role === 'admin' && ! $user->two_factor_confirmed_at) {
            $this->line('As an owner, the first page after logging in sets up two-step verification: have an authenticator app ready (Google Authenticator, Microsoft Authenticator, ...).');
        }

        return self::SUCCESS;
    }
}
