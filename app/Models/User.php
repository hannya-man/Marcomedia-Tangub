<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    // A deleted account is only marked (deleted_at): it can't log in and leaves User Management, but
    // its name stays on the sales and stock history it made (those relations use withTrashed()).
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable. The two-step verification ones are set only by
     * App\Services\Auth\TwoFactor, never from a form.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role', // admin | cashier
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'sheet_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'encrypted:array',
        'two_factor_confirmed_at' => 'datetime',
    ];

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    // Two-step verification is on: logging in asks for the authenticator app's code.
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    // A new password (set by email link, by the owner, or in Profile). Whoever knew the old one may
    // still be logged in, so every other login of this account ends: other browsers' sessions (kept in
    // the database, on Vercel) and every "Remember me".
    public function changePassword(string $password): void
    {
        $this->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $this->id)
                ->where('id', '!=', session()->getId())->delete();
        }
    }
}
