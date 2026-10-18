<?php
namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\UsesTwoFactor;
use Tests\TestCase;

/**
 * Two-step verification (a code from an authenticator app after the password; owners must use it)
 * and deleting a cashier's account, which the owner confirms with their code.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase, UsesTwoFactor;

    public function test_setting_it_up_takes_a_code_from_the_app_and_shows_recovery_codes_once(): void
    {
        $cashier = $this->user('cashier');
        $this->actingAs($cashier)->get(route('profile.edit'))->assertOk()->assertSee('Two-step verification')->assertSee('Turn on');
        $this->get(route('two-factor.show'))->assertOk()->assertSee('Set it up');

        $this->post(route('two-factor.begin'))->assertRedirect(route('two-factor.show'));
        $this->get(route('two-factor.show'))->assertOk()
            ->assertSee('data:image/svg+xml;base64,', false)                       // the QR code
            ->assertSee(app(TwoFactor::class)->setupKey($cashier->fresh()));      // or type the key
        $this->assertFalse($cashier->fresh()->hasTwoFactor());

        $this->post(route('two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($cashier->fresh()->hasTwoFactor());

        $this->post(route('two-factor.confirm'), ['code' => $this->codeFor($cashier->fresh())])
            ->assertSessionHas('recovery_codes', fn ($codes) => count($codes) === 8);
        $this->assertTrue($cashier->fresh()->hasTwoFactor());
        $this->get(route('two-factor.show'))->assertSee('Save these recovery codes now');   // shown once
        $this->get(route('two-factor.show'))->assertDontSee('Save these recovery codes now')->assertSee('8 of 8 recovery codes left');
        $this->get(route('profile.edit'))->assertSee('Manage');
    }

    public function test_logging_in_asks_for_the_code_and_each_code_works_once(): void
    {
        $user = $this->withTwoFactor($this->user('cashier'));

        $this->logIn($user)->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->get(route('pos.index'))->assertRedirect(route('login'));   // not logged in yet
        $this->get(route('two-factor.login'))->assertOk()->assertSee('auth-splash is-arriving', false);

        $this->post(route('two-factor.login'), ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();

        // Logged in, back to the page asked for.
        $code = $this->codeFor($user);
        $this->post(route('two-factor.login'), ['code' => $code])->assertRedirect(route('pos.index'));
        $this->assertAuthenticatedAs($user);

        // Seen over someone's shoulder: the same code doesn't log in again.
        $this->post(route('logout'));
        $this->logIn($user);
        $this->post(route('two-factor.login'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_recovery_code_logs_in_once(): void
    {
        $user = $this->withTwoFactor($this->user('cashier'));
        [$first] = $user->fresh()->two_factor_recovery_codes;

        $this->logIn($user);
        $this->post(route('two-factor.login'), ['use_recovery' => 1, 'recovery_code' => strtoupper($first)])
            ->assertRedirect('/')->assertSessionHas('error', fn ($m) => str_contains($m, '7 left'));
        $this->assertAuthenticatedAs($user);
        // Still said on the page '/' leads to (it was used up on the way).
        $this->get('/')->assertRedirect(route('pos.index'));
        $this->get(route('pos.index'))->assertOk()->assertSee('7 left');

        $this->post(route('logout'));
        $this->logIn($user);
        $this->post(route('two-factor.login'), ['use_recovery' => 1, 'recovery_code' => $first])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_the_code_step_runs_out_and_guessing_is_slowed_down(): void
    {
        $user = $this->withTwoFactor($this->user('cashier'));

        $this->logIn($user);
        foreach (range(1, 5) as $try) {
            $this->post(route('two-factor.login'), ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $this->post(route('two-factor.login'), ['code' => $this->codeFor($user)])
            ->assertSessionHas('errors', fn ($errors) => str_starts_with($errors->first('code'), 'Too many wrong codes.'));
        $this->assertGuest();

        // Ten minutes after the password, it starts over.
        $this->travel(11)->minutes();
        $this->post(route('two-factor.login'), ['code' => $this->codeFor($user)])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_owners_must_keep_it_on_and_a_cashier_turns_it_off_with_a_code(): void
    {
        $owner = $this->withTwoFactor($this->user('admin'));
        $this->actingAs($owner)->delete(route('two-factor.disable'), ['code' => $this->codeFor($owner)])->assertSessionHas('error');
        $this->assertTrue($owner->fresh()->hasTwoFactor());

        $cashier = $this->withTwoFactor($this->user('cashier'));
        $this->actingAs($cashier)->delete(route('two-factor.disable'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertTrue($cashier->fresh()->hasTwoFactor());
        $this->delete(route('two-factor.disable'), ['code' => $this->codeFor($cashier)])->assertSessionHas('success');
        $this->assertFalse($cashier->fresh()->hasTwoFactor());
    }

    public function test_deleting_a_cashier_takes_the_owners_code_and_keeps_their_sales_name(): void
    {
        $owner = $this->withTwoFactor($this->user('admin'));
        $cashier = $this->user('cashier');
        $sale = Sale::create([
            'invoice_number' => 'INV-TEST-0001', 'user_id' => $cashier->id, 'subtotal' => 100, 'discount_amount' => 0, 'tax_amount' => 0,
            'total_amount' => 100, 'amount_paid' => 100, 'change_amount' => 0, 'payment_method' => 'cash', 'status' => 'completed',
        ]);

        $this->actingAs($owner)->delete(route('users.destroy', $cashier), ['code' => '000000', 'delete_user_id' => $cashier->id])
            ->assertSessionHasErrorsIn('deleteUser', 'code');
        $this->assertNotSoftDeleted($cashier);

        $this->delete(route('users.destroy', $cashier), ['code' => $this->codeFor($owner)])->assertSessionHas('success');
        $this->assertSoftDeleted($cashier);
        $this->get(route('users.index'))->assertDontSee($cashier->email);
        // Their sale keeps their name; their email is free for a new account.
        $this->get(route('sales.index'))->assertSee($cashier->name);
        $this->assertSame($cashier->name, $sale->fresh()->user->name);
        $this->post(route('users.store'), ['name' => 'Rehired', 'email' => $cashier->email, 'role' => 'cashier', 'password' => 'secret-password'])
            ->assertSessionHas('success');

        // And they can't log in.
        $this->post(route('logout'));
        $this->post(route('login'), ['email' => $cashier->email, 'password' => 'secret-password']);
        $this->assertAuthenticatedAs(User::where('email', $cashier->email)->sole());   // the new account, not the deleted one
    }

    public function test_a_deleted_cashier_is_logged_out_and_cant_log_in(): void
    {
        $cashier = $this->user('cashier');
        $this->logIn($cashier)->assertRedirect('/');
        $this->assertAuthenticatedAs($cashier);

        $cashier->delete();
        $this->app['auth']->forgetGuards();   // the next request, as the server sees it
        $this->get(route('pos.index'))->assertRedirect(route('login'));

        $this->logIn($cashier)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_only_cashiers_are_deleted_and_never_yourself(): void
    {
        $owner = $this->withTwoFactor($this->user('admin'));
        $other = $this->withTwoFactor($this->user('admin'));

        $this->actingAs($owner)->delete(route('users.destroy', $other), ['code' => $this->codeFor($owner)])->assertSessionHas('error');
        $this->delete(route('users.destroy', $owner), ['code' => $this->codeFor($owner)])->assertSessionHas('error');
        $this->assertNotSoftDeleted($other);
        $this->assertNotSoftDeleted($owner);

        $this->actingAs($this->user('cashier'))->delete(route('users.destroy', $this->user('cashier')))->assertRedirect(route('pos.index'));
    }

    public function test_an_owner_turns_off_someones_two_step_verification_with_their_own_code(): void
    {
        $owner = $this->withTwoFactor($this->user('admin'));
        $cashier = $this->withTwoFactor($this->user('cashier'));

        $this->actingAs($owner)->delete(route('users.two-factor.reset', $cashier), ['code' => '000000'])
            ->assertSessionHasErrorsIn('resetTwoFactor', 'code');
        $this->assertTrue($cashier->fresh()->hasTwoFactor());

        $this->delete(route('users.two-factor.reset', $cashier), ['code' => $this->codeFor($owner)])->assertSessionHas('success');
        $this->assertFalse($cashier->fresh()->hasTwoFactor());
    }

    // ------------------------------------------------------------------

    private function user(string $role): User
    {
        return User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid($role) . '@example.com', 'password' => Hash::make('secret-password'), 'role' => $role]);
    }

    private function logIn(User $user)
    {
        return $this->post(route('login'), ['email' => $user->email, 'password' => 'secret-password']);
    }
}
