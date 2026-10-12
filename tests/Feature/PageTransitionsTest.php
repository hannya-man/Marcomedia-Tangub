<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The brand screen between the login page and the app (partials/auth-splash), and the Products
 * list's shadcn page links that page in place.
 */
class PageTransitionsTest extends TestCase
{
    use RefreshDatabase;

    private const ARRIVING = 'auth-splash is-arriving';

    public function test_the_first_page_after_logging_in_starts_under_the_brand_screen_once(): void
    {
        $cashier = $this->user('cashier');
        $this->post(route('login'), ['email' => $cashier->email, 'password' => 'secret-password'])->assertRedirect('/');
        $this->get('/')->assertRedirect(route('pos.index'));   // a redirect draws nothing: the screen waits

        $this->get(route('pos.index'))->assertOk()->assertSee(self::ARRIVING, false);
        $this->get(route('pos.index'))->assertOk()->assertDontSee(self::ARRIVING, false);
    }

    public function test_the_login_page_starts_under_it_after_logging_out_or_a_refused_login(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee(self::ARRIVING, false);

        $this->actingAs($this->user('admin'))->post(route('logout'))->assertRedirect('/');
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee(self::ARRIVING, false);
        $this->get(route('login'))->assertOk()->assertDontSee(self::ARRIVING, false);

        $this->from(route('login'))->post(route('login'), ['email' => 'nobody@example.com', 'password' => 'wrong-password'])->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee(self::ARRIVING, false)->assertSee('These credentials do not match');
    }

    public function test_the_products_list_pages_in_place_with_shadcn_page_links(): void
    {
        $owner = $this->user('admin');
        $page = $this->actingAs($owner)->get(route('inventory.index', ['page' => 2]))->assertOk();

        $page->assertSee('id="product-list" data-smooth-list', false)
            ->assertSee('aria-label="Pagination"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('Go to the previous page')
            ->assertSee('Showing <span class="font-medium text-ink">16</span>', false);
    }

    private function user(string $role): User
    {
        return User::create(['name' => ucfirst($role), 'email' => uniqid($role) . '@example.com', 'password' => Hash::make('secret-password'), 'role' => $role]);
    }
}
