<?php
namespace Tests\Feature;

use App\Models\User;
use App\Notifications\TwoFactorRecoveryLink;
use App\Notifications\TwoFactorTurnedOff;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\UsesTwoFactor;
use Tests\TestCase;

/**
 * Getting back in by email: a forgotten password (a link, plus the authenticator code when two-step
 * verification is on), and a lost phone (after the password, a sign-in link that turns two-step
 * verification off). Links only go to an email confirmed in Profile.
 */
class EmailRecoveryTest extends TestCase
{
    use RefreshDatabase, UsesTwoFactor;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_nothing_is_offered_until_email_is_set_up(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.username' => null, 'mail.mailers.smtp.password' => null]);
        $user = $this->user('cashier', confirmed: false);

        $this->get(route('login'))->assertOk()->assertDontSee('Forgot your password?');
        $this->get(route('password.request'))->assertRedirect(route('login'));
        $this->post(route('password.email'), ['email' => $user->email])->assertRedirect(route('login'));
        $this->actingAs($user)->get(route('profile.edit'))->assertOk()->assertDontSee('Send confirmation email');
        $this->post(route('verification.send'))->assertSessionHas('error');
        Notification::assertNothingSent();

        // Gmail set up (an address and its app password): offered.
        config(['mail.mailers.smtp.username' => 'shop@gmail.com', 'mail.mailers.smtp.password' => 'app-password']);
        $this->get(route('profile.edit'))->assertSee('Send confirmation email');
        Auth::logout();
        $this->get(route('login'))->assertSee('Forgot your password?');
    }

    public function test_a_confirmed_email_gets_a_link_to_set_a_new_password(): void
    {
        $user = $this->user('cashier');

        $this->get(route('password.request'))->assertOk()->assertSee('Email me a link');
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status')->assertSessionHasNoErrors();
        $token = $this->resetToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk()
            ->assertSee('Set a new password')->assertSee($user->email)->assertDontSee('6-digit code');

        // Logged in with the new password, and logged out everywhere else.
        $this->post(route('password.store'), $this->newPassword($user, $token))->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));

        // The link works once.
        Auth::logout();
        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertRedirect(route('password.request'))->assertSessionHasErrors('email');
        $this->post(route('password.store'), $this->newPassword($user, $token, 'another-pass'))->assertRedirect(route('password.request'));
        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
    }

    public function test_an_unconfirmed_or_unknown_email_gets_nothing_and_the_same_answer(): void
    {
        $unconfirmed = $this->user('cashier', confirmed: false);

        foreach ([$unconfirmed->email, 'nobody@example.com'] as $email) {
            $this->post(route('password.email'), ['email' => $email])
                ->assertSessionHas('status', fn ($status) => str_starts_with($status, 'If that email belongs to an account'))
                ->assertSessionHasNoErrors();
        }
        Notification::assertNothingSent();
    }

    public function test_a_link_for_someone_else_or_out_of_date_doesnt_work(): void
    {
        $user = $this->user('cashier');
        $other = $this->user('cashier');
        $this->post(route('password.email'), ['email' => $user->email]);
        $token = $this->resetToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $other->email]))->assertRedirect(route('password.request'));
        $this->post(route('password.store'), $this->newPassword($other, $token))->assertRedirect(route('password.request'));
        $this->assertTrue(Hash::check('secret-password', $other->fresh()->password));

        $this->travel(61)->minutes();
        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertRedirect(route('password.request'));
        $this->post(route('password.store'), $this->newPassword($user, $token))->assertRedirect(route('password.request'));
        $this->assertTrue(Hash::check('secret-password', $user->fresh()->password));
        $this->assertGuest();
    }

    public function test_with_two_step_verification_the_new_password_also_takes_the_code(): void
    {
        $owner = $this->withTwoFactor($this->user('admin'));
        $this->post(route('password.email'), ['email' => $owner->email]);
        $token = $this->resetToken($owner);

        $this->get(route('password.reset', ['token' => $token, 'email' => $owner->email]))->assertOk()->assertSee('6-digit code');

        // The email inbox alone doesn't open the account.
        $this->post(route('password.store'), $this->newPassword($owner, $token))->assertSessionHasErrors('code');
        $this->post(route('password.store'), $this->newPassword($owner, $token) + ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertTrue(Hash::check('secret-password', $owner->fresh()->password));

        $this->post(route('password.store'), $this->newPassword($owner, $token) + ['code' => $this->codeFor($owner)])->assertRedirect('/');
        $this->assertAuthenticatedAs($owner);
        $this->assertTrue(Hash::check('brand-new-pass', $owner->fresh()->password));
        $this->assertTrue($owner->fresh()->hasTwoFactor());
    }

    public function test_a_recovery_code_works_instead_of_the_app(): void
    {
        $user = $this->withTwoFactor($this->user('cashier'));
        $this->post(route('password.email'), ['email' => $user->email]);
        $token = $this->resetToken($user);

        $this->post(route('password.store'), $this->newPassword($user, $token) + ['use_recovery' => 1, 'recovery_code' => 'wrong-codes'])
            ->assertSessionHasErrors('recovery_code');
        $this->post(route('password.store'), $this->newPassword($user, $token) + ['use_recovery' => 1, 'recovery_code' => $user->fresh()->two_factor_recovery_codes[0]])
            ->assertRedirect('/')->assertSessionHas('error', fn ($message) => str_contains($message, '7 left'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_lost_phone_gets_a_sign_in_link_after_the_password(): void
    {
        $user = $this->withTwoFactor($this->user('cashier'));

        // Not before the password.
        $this->post(route('two-factor.email'))->assertRedirect(route('login'));
        Notification::assertNothingSent();

        $this->logIn($user)->assertRedirect(route('two-factor.login'));
        $this->get(route('two-factor.login'))->assertOk()->assertSee('Email me a sign-in link');
        $this->post(route('two-factor.email'))
            ->assertSessionHas('email_link_sent', fn ($message) => str_contains($message, 'c***@example.com'));
        $url = $this->recoveryLink($user);

        // Asked again right away: in a minute.
        $this->post(route('two-factor.email'))->assertSessionHasErrors('email_link');
        Notification::assertSentToTimes($user, TwoFactorRecoveryLink::class, 1);

        // The link logs in and turns two-step verification off, and an email says so.
        $this->get($url)->assertRedirect(route('two-factor.show'))->assertSessionHas('success');
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->fresh()->hasTwoFactor());
        Notification::assertSentTo($user, TwoFactorTurnedOff::class);

        // It works once.
        Auth::logout();
        $this->get($url)->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_the_sign_in_link_needs_a_confirmed_email_and_runs_out(): void
    {
        $user = $this->withTwoFactor($this->user('cashier', confirmed: false));
        $this->logIn($user);
        $this->post(route('two-factor.email'))->assertSessionHasErrors('email_link');
        Notification::assertNothingSent();

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->post(route('two-factor.email'))->assertSessionHas('email_link_sent');
        $url = $this->recoveryLink($user);

        // Changed to open someone else's account: refused.
        $other = $this->withTwoFactor($this->user('admin'));
        $this->get(str_replace('two-factor-recover/' . $user->id, 'two-factor-recover/' . $other->id, $url))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->travel(31)->minutes();
        $this->get($url)->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertTrue($user->fresh()->hasTwoFactor());
    }

    public function test_the_sign_in_link_stops_working_when_two_step_verification_is_set_up_again(): void
    {
        $user = $this->withTwoFactor($this->user('cashier'));
        $this->logIn($user);
        $this->post(route('two-factor.email'));
        $url = $this->recoveryLink($user);

        // Meanwhile the phone turned up and the app was set up again.
        $this->withTwoFactor($user->fresh());
        $this->get($url)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertTrue($user->fresh()->hasTwoFactor());
    }

    public function test_the_sign_in_link_opened_while_logged_in_says_so(): void
    {
        $user = $this->withTwoFactor($this->user('cashier'));
        $this->logIn($user);
        $this->post(route('two-factor.email'));
        $url = $this->recoveryLink($user);

        $this->actingAs($this->user('cashier'))->get($url)->assertRedirect('/')->assertSessionHas('error');
        $this->assertTrue($user->fresh()->hasTwoFactor());
    }

    public function test_confirming_the_email_from_profile(): void
    {
        $user = $this->user('cashier', confirmed: false);

        $this->actingAs($user)->get(route('profile.edit'))->assertOk()->assertSee('Not confirmed yet')->assertSee('Send confirmation email');
        $this->post(route('verification.send'))->assertSessionHas('status', 'verification-link-sent');
        $url = null;
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$url) {
            $mail = $notification->toMail($user);
            $url = $mail->actionUrl;

            return $mail->subject === 'Confirm your email for Marcomedia POS';
        });

        $this->get($url)->assertRedirect(route('profile.edit'))->assertSessionHas('success');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get(route('profile.edit'))->assertSee('Confirmed.')->assertDontSee('Send confirmation email');

        // A new email is confirmed again, whether they changed it or the owner did.
        $this->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new-' . $user->email])->assertRedirect(route('profile.edit'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        $user->refresh()->forceFill(['email_verified_at' => now()])->save();
        $owner = $this->withTwoFactor($this->user('admin'));
        $this->actingAs($owner)->patch(route('users.update', $user), ['name' => $user->name, 'email' => 'other-' . $user->email, 'role' => 'cashier'])
            ->assertRedirect(route('users.index'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        // Only the email resets it.
        $user->refresh()->forceFill(['email_verified_at' => now()])->save();
        $this->patch(route('users.update', $user), ['name' => 'Renamed', 'email' => $user->fresh()->email, 'role' => 'cashier']);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_the_two_step_page_points_to_confirming_the_email(): void
    {
        $user = $this->withTwoFactor($this->user('cashier', confirmed: false));
        $this->actingAs($user)->get(route('two-factor.show'))->assertOk()->assertSee('Confirm your email in Profile');

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->get(route('two-factor.show'))->assertOk()->assertDontSee('Confirm your email in Profile');
    }

    public function test_an_old_or_someone_elses_confirmation_link_says_so_in_profile(): void
    {
        $user = $this->user('cashier', confirmed: false);
        $this->actingAs($user)->post(route('verification.send'));
        $url = null;
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$url) {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });

        $this->actingAs($this->user('cashier', confirmed: false))->get($url)->assertRedirect(route('profile.edit'))->assertSessionHas('error');
        $this->actingAs($user);
        $this->travel(61)->minutes();
        $this->get($url)->assertRedirect(route('profile.edit'))->assertSessionHas('error', fn ($message) => str_contains($message, 'expired'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    private function user(string $role, bool $confirmed = true): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid($role) . '@example.com', 'password' => Hash::make('secret-password'), 'role' => $role]);
        if ($confirmed) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }

    private function logIn(User $user)
    {
        return $this->post(route('login'), ['email' => $user->email, 'password' => 'secret-password']);
    }

    private function newPassword(User $user, string $token, string $password = 'brand-new-pass'): array
    {
        return ['token' => $token, 'email' => $user->email, 'password' => $password, 'password_confirmation' => $password];
    }

    // The token in the "Set a new password" email, and that email says what it should.
    private function resetToken(User $user): string
    {
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, &$token) {
            $token = $notification->token;
            $mail = $notification->toMail($user);

            return $mail->subject === 'Set a new password for Marcomedia POS' && str_contains($mail->actionUrl, '/reset-password/' . $token);
        });

        return $token;
    }

    private function recoveryLink(User $user): string
    {
        $url = null;
        Notification::assertSentTo($user, TwoFactorRecoveryLink::class, function (TwoFactorRecoveryLink $notification) use ($user, &$url) {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });

        return $url;
    }
}
