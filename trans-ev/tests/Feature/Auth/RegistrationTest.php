<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register()
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '9876543210',
            'terms' => true,
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ]);

        $this->assertAuthenticated();
        $this->assertTrue(auth()->user()->hasRole('customer'));
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_registration_requires_phone_terms_and_strong_password()
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'weak@example.com',
            'phone' => '12345',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors(['phone', 'terms', 'password']);

        $this->assertGuest();
    }
}
