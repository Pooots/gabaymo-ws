<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Sanctum cookie-session auth for the customer, partner and admin portals.
 *
 * Runs on in-memory SQLite so it never touches the development database.
 */
class SpaAuthTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'http://localhost:3000';

    /** @var array<string, string> */
    private array $jar = [];

    protected function beforeRefreshingDatabase()
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'session.driver' => 'array',
        ]);
        DB::purge();
    }

    public function test_customer_login_starts_a_cookie_session(): void
    {
        $this->member();

        $login = $this->send('POST', '/api/v1/login', ['email' => 'member@test.ph', 'password' => 'secret123']);

        $login->assertOk()
            ->assertJsonPath('portal', 'customer')
            ->assertJsonMissingPath('token');
        $this->assertArrayHasKey(config('session.cookie'), $this->jar);

        $this->send('GET', '/api/v1/session')->assertOk()->assertJsonPath('portal', 'customer');
        $this->send('GET', '/api/v1/me')->assertOk()->assertJsonPath('user.email', 'member@test.ph');
        $this->send('GET', '/api/v1/partner/me')->assertUnauthorized();
        $this->send('GET', '/api/v1/admin/me')->assertUnauthorized();
    }

    public function test_partner_uses_the_same_login_and_is_sent_to_the_partner_portal(): void
    {
        $this->partner();

        $this->send('POST', '/api/v1/login', ['email' => 'partner@test.ph', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('portal', 'partner');

        $this->send('GET', '/api/v1/session')->assertOk()->assertJsonPath('portal', 'partner');
        $this->send('GET', '/api/v1/partner/dashboard')->assertOk();
        $this->send('GET', '/api/v1/me')->assertUnauthorized();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->member();
        $this->send('POST', '/api/v1/login', ['email' => 'member@test.ph', 'password' => 'secret123'])->assertOk();

        $this->send('POST', '/api/v1/logout')->assertOk();

        $this->send('GET', '/api/v1/me')->assertUnauthorized();
        $this->send('GET', '/api/v1/session')->assertUnauthorized();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->member();

        $this->send('POST', '/api/v1/login', ['email' => 'member@test.ph', 'password' => 'nope'])
            ->assertStatus(422);
        $this->send('GET', '/api/v1/session')->assertUnauthorized();
    }

    public function test_admin_session_is_separate_from_the_customer_session(): void
    {
        $this->member();
        $this->staff();

        $this->send('POST', '/api/v1/admin/login', ['email' => 'staff@test.ph', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonMissingPath('token');
        $this->send('GET', '/api/v1/admin/me')->assertOk();
        $this->send('GET', '/api/v1/me')->assertUnauthorized();

        $this->send('POST', '/api/v1/login', ['email' => 'member@test.ph', 'password' => 'secret123'])->assertOk();
        $this->send('GET', '/api/v1/me')->assertOk();
        $this->send('GET', '/api/v1/admin/me')->assertOk();

        // Signing out of the customer portal leaves the admin signed in.
        $this->send('POST', '/api/v1/logout')->assertOk();
        $this->send('GET', '/api/v1/me')->assertUnauthorized();
        $this->send('GET', '/api/v1/admin/me')->assertOk();

        $this->send('POST', '/api/v1/admin/logout')->assertOk();
        $this->send('GET', '/api/v1/admin/me')->assertUnauthorized();
    }

    public function test_staff_cannot_sign_in_on_the_customer_login(): void
    {
        $this->staff();

        $this->send('POST', '/api/v1/login', ['email' => 'staff@test.ph', 'password' => 'secret123'])
            ->assertStatus(422);
    }

    public function test_a_role_named_partner_does_not_turn_partners_into_staff(): void
    {
        $this->partner();
        Role::query()->create(['name' => 'Partner', 'slug' => 'partner', 'permissions' => ['overview']]);

        $this->send('POST', '/api/v1/admin/login', ['email' => 'partner@test.ph', 'password' => 'secret123'])
            ->assertStatus(422);
        $this->send('GET', '/api/v1/admin/overview')->assertUnauthorized();
    }

    public function test_admin_actions_are_logged_against_the_staff_account(): void
    {
        $staff = $this->staff();

        $this->send('POST', '/api/v1/admin/login', ['email' => 'staff@test.ph', 'password' => 'secret123'])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.login',
            'admin_user_id' => $staff->id,
            'user_id' => null,
        ]);
    }

    public function test_requests_from_unknown_origins_get_no_session(): void
    {
        $this->member();

        $this->send('POST', '/api/v1/login', ['email' => 'member@test.ph', 'password' => 'secret123'], 'https://evil.example')
            ->assertStatus(400);
        $this->assertArrayNotHasKey(config('session.cookie'), $this->jar);
    }

    public function test_register_from_unknown_origin_creates_nothing(): void
    {
        $this->send('POST', '/api/v1/register', [
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'mobile' => '09171234567',
            'region' => \App\Support\PhilippineRegions::NAMES[0],
            'email' => 'ana@test.ph',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ], 'https://evil.example')->assertStatus(400);

        $this->assertDatabaseMissing('users', ['email' => 'ana@test.ph']);
    }

    /**
     * Make a request from the SPA origin, carrying cookies like a browser does.
     */
    private function send(string $method, string $uri, array $data = [], string $origin = self::ORIGIN): TestResponse
    {
        // A fresh request must not see guard state cached from the previous one.
        $this->app['auth']->forgetGuards();

        $response = $this->withHeaders(['Origin' => $origin, 'Referer' => $origin.'/'])
            ->withCookies($this->jar)
            ->json($method, $uri, $data);

        foreach ($response->headers->getCookies() as $cookie) {
            $value = $response->getCookie($cookie->getName())?->getValue();

            if ($cookie->isCleared() || $value === null) {
                unset($this->jar[$cookie->getName()]);
            } else {
                $this->jar[$cookie->getName()] = $value;
            }
        }

        return $response;
    }

    private function member(): User
    {
        return User::query()->forceCreate([
            'name' => 'Maria Santos',
            'email' => 'member@test.ph',
            'password' => 'secret123',
            'account_type' => User::CUSTOMER,
        ]);
    }

    private function partner(): User
    {
        return User::query()->forceCreate([
            'name' => 'Atty. Reyes',
            'email' => 'partner@test.ph',
            'password' => 'secret123',
            'account_type' => User::PARTNER,
            'partner_status' => 'active',
        ]);
    }

    private function staff(): AdminUser
    {
        $role = Role::query()->create(['name' => 'Support', 'slug' => 'support', 'permissions' => ['overview']]);

        return AdminUser::query()->forceCreate([
            'name' => 'Staff One',
            'email' => 'staff@test.ph',
            'password' => 'secret123',
            'role_id' => $role->id,
        ]);
    }
}
