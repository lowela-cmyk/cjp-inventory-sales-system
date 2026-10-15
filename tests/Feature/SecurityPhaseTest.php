<?php

namespace Tests\Feature;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * October 12 audit follow-up: AUD-C02 (reset-code brute force) and AUD-H01 (login throttle bypass).
 */
class SecurityPhaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reset_code_is_cancelled_after_five_wrong_tries(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'owner@example.com', 'role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $this->post(route('password.email'), ['email' => $user->email]);

        $code = null;
        Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        $wrong = $code === '111111' ? '222222' : '111111';
        $reset = fn (string $guess) => $this->from(route('password.reset'))->post(route('password.update'), [
            'email' => $user->email, 'code' => $guess, 'password' => 'NewPassword!234', 'password_confirmation' => 'NewPassword!234',
        ]);

        // A minute apart, so the per-minute limiter stays out of the way and the per-code lockout is what stops it.
        for ($i = 1; $i <= 4; $i++) {
            $reset($wrong)->assertSessionHasErrors(['code' => 'The reset code is invalid or has expired.']);
            $this->travel(61)->seconds();
        }
        $reset($wrong)->assertSessionHasErrors(['code' => 'Too many wrong codes. This reset code has been cancelled; please request a new one.']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

        // The real code no longer works either; the password is unchanged.
        $this->travel(61)->seconds();
        $reset((string) $code)->assertSessionHasErrors(['code' => 'The reset code is invalid or has expired.']);
        $this->assertFalse(Hash::check('NewPassword!234', $user->fresh()->password));
    }

    public function test_throttled_login_explains_how_long_to_wait(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);

        for ($i = 1; $i <= 5; $i++) {
            $this->post(route('login.store'), ['username' => $user->email, 'password' => 'wrong']);
        }

        $this->post(route('login.store'), ['username' => $user->email, 'password' => 'wrong'])
            ->assertStatus(429)
            ->assertSee('Too many attempts. Please try again in 1 minute.')
            ->assertSee('id="username"', false);
    }

    public function test_a_spoofed_forwarded_for_header_does_not_change_the_client_ip(): void
    {
        Route::get('/_client-ip', fn (Request $request) => $request->ip());

        $this->withHeader('X-Forwarded-For', '203.0.113.9')->get('/_client-ip')->assertSeeText('127.0.0.1');
    }

    public function test_the_per_account_limit_holds_across_ips_even_behind_a_trusted_proxy(): void
    {
        // Behind a trusted proxy each request may carry a different real client IP;
        // the account still locks after 10 failures in 15 minutes.
        TrustProxies::at(['127.0.0.1']);
        $user = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);

        $statuses = [];
        for ($i = 1; $i <= 11; $i++) {
            $statuses[] = $this->withHeader('X-Forwarded-For', '198.51.100.'.$i)
                ->post(route('login.store'), ['username' => $user->email, 'password' => 'wrong'])->getStatusCode();
        }

        $this->assertNotContains(429, array_slice($statuses, 0, 10), 'Each IP alone stays under its own limit.');
        $this->assertSame(429, $statuses[10], 'The 11th failure for the same account is throttled.');
    }

    public function test_readiness_audit_still_fails_on_a_development_env_file(): void
    {
        $envFile = tempnam(sys_get_temp_dir(), 'cjp-env-');
        file_put_contents($envFile, "APP_ENV=local\nAPP_DEBUG=true\n");

        try {
            $exitCode = Artisan::call('cjp:readiness-audit', ['--turnover' => true, '--env-file' => $envFile]);
            $output = Artisan::output();
        } finally {
            unlink($envFile);
        }

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('APP_ENV is production', $output);
        $this->assertStringContainsString('current: local', $output);
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }
}
