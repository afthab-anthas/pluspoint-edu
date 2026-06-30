<?php

/**
 * Example Laravel PHPUnit Test File
 *
 * Demonstrates Feature tests (HTTP, auth, DB) and Unit tests.
 * To run for real, move to tests/Feature/ or tests/Unit/ and run:
 *   php artisan test
 */

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ---------------------------------------------------------------------------
// Feature Test: HTTP & Pages
// ---------------------------------------------------------------------------

class HomePageTest extends TestCase
{
    public function test_home_page_returns_200(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_about_page_returns_200(): void
    {
        $response = $this->get('/about');

        $response->assertStatus(200);
    }

    public function test_students_page_requires_auth(): void
    {
        $response = $this->get('/students');

        // Unauthenticated users should be redirected to login
        $response->assertRedirect('/login');
    }
}

// ---------------------------------------------------------------------------
// Feature Test: Authentication
// ---------------------------------------------------------------------------

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_login_page(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('Login');
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email'    => 'student@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->post('/login', [
            'email'    => 'student@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email'    => 'student@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        $response = $this->post('/login', [
            'email'    => 'student@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
    }
}

// ---------------------------------------------------------------------------
// Feature Test: Registration
// ---------------------------------------------------------------------------

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_page_is_accessible(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_user_can_register(): void
    {
        $response = $this->post('/register', [
            'name'                  => 'John Doe',
            'email'                 => 'john@example.com',
            'password'              => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);

        $response->assertRedirect('/home');
        $this->assertDatabaseHas('users', ['email' => 'john@example.com']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->post('/register', [
            'name'                  => 'Another User',
            'email'                 => 'taken@example.com',
            'password'              => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);

        $response->assertSessionHasErrors('email');
    }
}

// ---------------------------------------------------------------------------
// Feature Test: Authenticated Pages
// ---------------------------------------------------------------------------

class AuthenticatedPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_access_students_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/students');

        $response->assertStatus(200);
    }

    public function test_authenticated_user_can_access_account_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/account');

        $response->assertStatus(200);
    }
}

// ---------------------------------------------------------------------------
// Unit Test: User Model
// ---------------------------------------------------------------------------

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_is_hashed_on_create(): void
    {
        $user = User::factory()->create(['password' => 'plain-text-password']);

        $this->assertNotEquals('plain-text-password', $user->password);
    }

    public function test_password_is_hidden_from_serialization(): void
    {
        $user = User::factory()->create();

        $array = $user->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('remember_token', $array);
    }

    public function test_user_fillable_fields(): void
    {
        $user = User::factory()->make([
            'name'   => 'Test User',
            'email'  => 'test@example.com',
            'mobile' => '0501234567',
        ]);

        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('test@example.com', $user->email);
        $this->assertEquals('0501234567', $user->mobile);
    }

    public function test_user_can_be_found_by_email(): void
    {
        User::factory()->create(['email' => 'find-me@example.com']);

        $found = User::where('email', 'find-me@example.com')->first();

        $this->assertNotNull($found);
        $this->assertEquals('find-me@example.com', $found->email);
    }
}
