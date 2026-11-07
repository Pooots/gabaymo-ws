<?php

namespace Tests\Feature\Improvements;

use App\Models\AuditLog;
use App\Support\Totp;

/** Staff two-factor sign-in (TOTP, RFC 6238) with hashed recovery codes, and lockout. */
class AdminTwoFactorTest extends ImprovementTestCase
{
    public function test_totp_matches_the_rfc_6238_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('287082', Totp::at($secret, intdiv(59, 30)));
        $this->assertSame('081804', Totp::at($secret, intdiv(1111111109, 30)));
        $this->assertSame('279037', Totp::at($secret, intdiv(2000000000, 30)));
    }

    public function test_setup_confirm_and_sign_in_with_a_code_or_a_recovery_code(): void
    {
        config(['services.rate_limits.admin_login' => 50]);
        $admin = $this->staff(['overview'], false, ['email' => 'otp@test.ph']);
        $this->send('POST', '/api/v1/admin/login', ['email' => 'otp@test.ph', 'password' => 'secret123'])->assertOk();

        $setup = $this->send('POST', '/api/v1/admin/me/two-factor')->assertOk()->assertJsonStructure(['secret', 'otpauth_url', 'recovery_codes']);
        $secret = $setup->json('secret');
        $codes = $setup->json('recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertStringStartsWith('otpauth://totp/', $setup->json('otpauth_url'));
        $this->assertNotContains($codes[0], (array) $admin->fresh()->two_factor_recovery_codes, 'Recovery codes are stored hashed.');

        $this->send('POST', '/api/v1/admin/me/two-factor/confirm', ['code' => '000000'])->assertStatus(422);
        $this->send('POST', '/api/v1/admin/me/two-factor/confirm', ['code' => Totp::now($secret)])->assertOk()->assertJsonPath('user.two_factor_enabled', true);
        $this->send('POST', '/api/v1/admin/logout')->assertOk();

        // Password alone is no longer enough.
        $this->send('POST', '/api/v1/admin/login', ['email' => 'otp@test.ph', 'password' => 'secret123'])->assertOk()->assertExactJson(['two_factor_required' => true]);
        $this->send('GET', '/api/v1/admin/me')->assertUnauthorized();
        $this->send('POST', '/api/v1/admin/login/two-factor', ['code' => '123456'])->assertStatus(422);
        // Same time step as the confirm: a replayed code is refused, the next step works.
        $this->send('POST', '/api/v1/admin/login/two-factor', ['code' => Totp::now($secret)])->assertStatus(422);
        $this->send('POST', '/api/v1/admin/login/two-factor', ['code' => Totp::now($secret, time() + 30)])->assertOk()->assertJsonPath('user.email', 'otp@test.ph');
        $this->send('GET', '/api/v1/admin/me')->assertOk();
        $this->send('POST', '/api/v1/admin/logout')->assertOk();

        // A recovery code works once.
        $this->send('POST', '/api/v1/admin/login', ['email' => 'otp@test.ph', 'password' => 'secret123'])->assertJsonPath('two_factor_required', true);
        $this->send('POST', '/api/v1/admin/login/two-factor', ['code' => $codes[0]])->assertOk();
        $this->send('POST', '/api/v1/admin/logout')->assertOk();
        $this->send('POST', '/api/v1/admin/login', ['email' => 'otp@test.ph', 'password' => 'secret123'])->assertJsonPath('two_factor_required', true);
        $this->send('POST', '/api/v1/admin/login/two-factor', ['code' => $codes[0]])->assertStatus(422);
        $this->assertCount(7, (array) $admin->fresh()->two_factor_recovery_codes);

        // Turning it off needs the password.
        $this->send('POST', '/api/v1/admin/login/two-factor', ['code' => $codes[1]])->assertOk();
        $this->send('DELETE', '/api/v1/admin/me/two-factor', ['password' => 'nope'])->assertStatus(422);
        $this->send('DELETE', '/api/v1/admin/me/two-factor', ['password' => 'secret123'])->assertOk()->assertJsonPath('user.two_factor_enabled', false);
        $this->assertTrue(AuditLog::query()->where('action', 'admin.two_factor_enabled')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'admin.two_factor_disabled')->exists());
    }

    public function test_repeated_failures_lock_the_staff_account(): void
    {
        config(['services.admin_security.max_attempts' => 3, 'services.rate_limits.admin_login' => 50]);
        $admin = $this->staff(['overview'], false, ['email' => 'lock@test.ph']);

        foreach (range(1, 3) as $_) {
            $this->send('POST', '/api/v1/admin/login', ['email' => 'lock@test.ph', 'password' => 'wrong'])->assertStatus(422);
        }
        $this->send('POST', '/api/v1/admin/login', ['email' => 'lock@test.ph', 'password' => 'secret123'])->assertStatus(429)->assertJsonPath('error', 'account_locked');
        $this->assertTrue($admin->fresh()->locked_until->isFuture());
        $this->assertTrue(AuditLog::query()->where('action', 'admin.locked')->exists());

        $this->travel(16)->minutes();
        $this->send('POST', '/api/v1/admin/login', ['email' => 'lock@test.ph', 'password' => 'secret123'])->assertOk();
    }
}
