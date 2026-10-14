<?php

namespace Tests\Feature\Audit;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;

/**
 * AUD-C02 (CRITICAL): the 6-digit password reset code has no per-code attempt limit, and the only
 * throttle is keyed by email|IP where the IP comes from a client-controlled X-Forwarded-For header
 * (trustProxies(at: '*')). An attacker can rotate the header and keep guessing for 15 minutes.
 */
#[Group('audit')]
class C02PasswordResetBruteForceTest extends AuditTestCase
{
    public function test_reset_code_guessing_is_capped_even_when_the_client_ip_header_rotates(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'victim@example.com', 'role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);

        $this->post(route('password.email'), ['email' => $user->email])->assertRedirect();
        Mail::assertSent(PasswordResetCodeMail::class);

        $statuses = [];
        for ($i = 1; $i <= 12; $i++) {
            $statuses[] = $this->withHeader('X-Forwarded-For', '203.0.113.'.$i)
                ->post(route('password.update'), [
                    'email' => $user->email,
                    'code' => str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                    'password' => 'NewPassword!234',
                    'password_confirmation' => 'NewPassword!234',
                ])->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'Repeated wrong reset codes for one account must be throttled regardless of the client IP header.');
    }
}
