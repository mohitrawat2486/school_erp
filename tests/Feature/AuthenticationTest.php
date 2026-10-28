<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_login_page_is_available(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_valid_credentials_authenticate_and_regenerate_session(): void
    {
        $user = User::factory()->create();
        $this->withSession(['probe' => 'value']);
        $session = session()->getId();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($session, session()->getId());
        $this->get('/dashboard')->assertOk()->assertSee($user->name);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_requires_valid_input(): void
    {
        $this->post('/login', ['email' => 'invalid'])->assertSessionHasErrors(['email', 'password']);
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        $this->freezeTime();
        $email = 'limited@example.test';
        RateLimiter::clear($email.'|127.0.0.1');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $email, 'password' => 'wrong']);
        }
        $this->post('/login', ['email' => $email, 'password' => 'wrong'])->assertSessionHasErrors(['email' => 'Too many sign-in attempts. Try again in 60 seconds.']);
        $this->assertGuest();
    }

    public function test_authenticated_users_are_redirected_from_login(): void
    {
        $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/dashboard');
    }

    public function test_logout_ends_session(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_logout_does_not_accept_get(): void
    {
        $this->get('/logout')->assertStatus(405);
    }
}
