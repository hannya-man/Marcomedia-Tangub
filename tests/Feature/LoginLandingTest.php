<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Where each role lands after logging in: its own start page, or the page it asked for if it can
 * open it. Never a page that bounces it back with "You don't have access to that page."
 */
class LoginLandingTest extends TestCase
{
    use RefreshDatabase;

    private const ACCESS_ERROR = "You don't have access to that page.";

    public function test_a_cashier_lands_on_billing_without_an_access_error(): void
    {
        $this->logIn($this->user('cashier'))->assertRedirect('/');
        $this->get('/')->assertRedirect(route('pos.index'));

        $this->get(route('pos.index'))->assertOk()->assertSessionMissing('error')->assertDontSee(self::ACCESS_ERROR, false);
    }

    public function test_the_owner_lands_on_the_dashboard(): void
    {
        $this->logIn($this->user('admin'))->assertRedirect('/');
        $this->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_a_page_asked_for_before_logging_in_is_kept_only_if_the_role_can_open_it(): void
    {
        // The dashboard is the owner's: a cashier who asked for it starts on Billing/POS instead.
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->logIn($this->user('cashier'))->assertRedirect('/');
    }

    public function test_the_owner_goes_back_to_the_page_asked_for(): void
    {
        $this->get(route('stock.discrete'))->assertRedirect(route('login'));
        $this->logIn($this->user('admin'))->assertRedirect(route('stock.discrete'));
    }

    public function test_a_chat_poll_after_logging_out_isnt_where_the_next_login_lands(): void
    {
        // The chat asks for JSON, so a logged-out poll gets a 401 and isn't remembered.
        $this->getJson(route('chat.poll'))->assertUnauthorized();
        $this->logIn($this->user('cashier'))->assertRedirect('/');
    }

    public function test_a_logged_in_cashier_opening_the_login_page_goes_to_billing(): void
    {
        $this->actingAs($this->user('cashier'))->get(route('login'))->assertRedirect('/');
        $this->get('/')->assertRedirect(route('pos.index'));
    }

    public function test_the_login_page_shows_the_logo_and_tagline(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('images/marcomedia-logo-transparent.png', false)
            ->assertSee('The best place to get customized!')
            ->assertDontSee('Printing &amp; Photography', false);
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => uniqid($role) . '@example.com',
            'password' => Hash::make('secret-password'), 'role' => $role,
        ]);
    }

    private function logIn(User $user)
    {
        return $this->post(route('login'), ['email' => $user->email, 'password' => 'secret-password']);
    }
}
