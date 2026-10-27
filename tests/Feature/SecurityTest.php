<?php
namespace Tests\Feature;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a browser is told to protect (no framing, no guessing file types, scripts only with their
 * hash) and a shift that only its own cashier closes.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private function cashier(string $name): User
    {
        return User::create(['name' => $name, 'email' => uniqid('cashier') . '@example.com', 'password' => bcrypt('secret-password'), 'role' => 'cashier']);
    }

    public function test_pages_cant_be_framed_by_another_site_or_have_their_file_type_guessed(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'")
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_every_script_from_another_site_has_a_fixed_version_and_its_hash(): void
    {
        $pages = [
            $this->get(route('login'))->getContent(),
            $this->actingAs($this->cashier('Cashier'))->get(route('pos.index'))->getContent(),
        ];

        foreach ($pages as $html) {
            preg_match_all('/<script\b[^>]*\bsrc="(https?:[^"]*)"[^>]*>/', $html, $tags);
            // This site's own files (Vite's build) need no hash.
            $others = array_filter($tags[0], fn ($tag, $i) => ! str_starts_with($tags[1][$i], url('/') . '/'), ARRAY_FILTER_USE_BOTH);
            $this->assertNotEmpty($others);
            foreach ($others as $tag) {
                $this->assertMatchesRegularExpression('/integrity="sha(256|384|512)-[A-Za-z0-9+\/=]+" crossorigin="anonymous"/', $tag, $tag);
                $this->assertStringNotContainsString('.x', $tag, $tag);
                $this->assertStringNotContainsString('@latest', $tag, $tag);
            }
        }
    }

    public function test_a_cashier_cant_close_another_cashiers_shift(): void
    {
        $ana = $this->cashier('Ana');
        $ben = $this->cashier('Ben');
        $shift = Shift::create(['user_id' => $ana->id, 'opened_at' => now()->subHour(), 'status' => 'open']);

        $this->actingAs($ben)->post(route('shifts.close', $shift), ['actual_cash' => 0])->assertForbidden();
        $this->assertSame('open', $shift->fresh()->status);

        $this->actingAs($ana)->post(route('shifts.close', $shift), ['actual_cash' => 0]);
        $this->assertSame('closed', $shift->fresh()->status);
    }
}
