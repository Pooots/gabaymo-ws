<?php

namespace Tests\Feature\Improvements;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Billing\TokenMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** In-memory SQLite (never the development database) plus a browser-like cookie jar. */
abstract class ImprovementTestCase extends TestCase
{
    use RefreshDatabase;

    protected const ORIGIN = 'http://localhost:3000';

    /** @var array<string, string> */
    protected array $jar = [];

    protected function beforeRefreshingDatabase()
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'session.driver' => 'array',
            'broadcasting.default' => 'null',
            'cache.default' => 'array',
        ]);
        DB::purge();
    }

    /** A request from the SPA origin carrying cookies, like a browser. */
    protected function send(string $method, string $uri, array $data = [], array $headers = [], string $origin = self::ORIGIN): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $response = $this->withHeaders(['Origin' => $origin, 'Referer' => $origin.'/'] + $headers)
            ->withCookies($this->jar)
            ->json($method, $uri, $data);
        // Do not leak this browser's headers/cookies into later actingAs() requests.
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

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

    protected function freePlan(): SubscriptionPlan
    {
        return SubscriptionPlan::query()->firstOrCreate(['name' => 'Free Trial'], [
            'price' => 0, 'cadence' => 'forever', 'token_allowance' => 200_000, 'allowance_period' => 'once',
            'summary' => 'Trial', 'specs' => ['x'], 'active' => true, 'sort_order' => 1,
        ]);
    }

    protected function paidPlan(string $cadence = 'month', int $price = 499, string $name = 'Monthly'): SubscriptionPlan
    {
        return SubscriptionPlan::query()->create([
            'name' => $name.' '.uniqid(), 'price' => $price, 'cadence' => $cadence, 'token_allowance' => 3_000_000,
            'allowance_period' => 'month', 'summary' => 'Paid', 'specs' => ['x'], 'active' => true, 'sort_order' => 2,
        ]);
    }

    protected function member(array $attributes = [], ?SubscriptionPlan $plan = null): User
    {
        $user = User::query()->forceCreate([
            'name' => 'Maria Santos',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'm'.uniqid().'@test.ph',
            'password' => 'secret123',
            'account_type' => User::CUSTOMER,
            'region' => 'National Capital Region (NCR)',
            ...$attributes,
        ]);

        return app(TokenMeter::class)->startPlan($user, $plan ?? $this->freePlan())->fresh('subscriptionPlan');
    }

    protected function partner(array $attributes = []): User
    {
        return User::query()->forceCreate([
            'name' => 'Atty. Reyes',
            'email' => 'p'.uniqid().'@test.ph',
            'password' => 'secret123',
            'account_type' => User::PARTNER,
            'partner_status' => 'active',
            ...$attributes,
        ]);
    }

    protected function staff(array $permissions = [], bool $system = false, array $attributes = []): AdminUser
    {
        $role = Role::query()->create(['name' => 'R'.uniqid(), 'slug' => 'r'.uniqid(), 'is_system' => $system, 'permissions' => $permissions]);

        return AdminUser::query()->forceCreate([
            'name' => 'Staff One',
            'email' => 's'.uniqid().'@test.ph',
            'password' => 'secret123',
            'role_id' => $role->id,
            ...$attributes,
        ]);
    }
}
