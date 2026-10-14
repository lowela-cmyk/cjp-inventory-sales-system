<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * AUD-H01 (HIGH): the login limiter (5/min) is keyed by username|IP, and with trustProxies(at: '*')
 * the IP is whatever the client puts in X-Forwarded-For, so password guessing is effectively unlimited.
 */
#[Group('audit')]
class H01LoginThrottleSpoofTest extends AuditTestCase
{
    public function test_failed_logins_for_one_account_are_throttled_when_x_forwarded_for_rotates(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);

        $statuses = [];
        for ($i = 1; $i <= 8; $i++) {
            $statuses[] = $this->withHeader('X-Forwarded-For', '198.51.100.'.$i)
                ->post(route('login.store'), ['username' => $user->email, 'password' => 'wrong-password-'.$i])
                ->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'Brute-force attempts against one account must be throttled regardless of X-Forwarded-For.');
    }

    public function test_the_baseline_limiter_still_works_without_header_spoofing(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);

        $statuses = [];
        for ($i = 1; $i <= 7; $i++) {
            $statuses[] = $this->post(route('login.store'), ['username' => $user->email, 'password' => 'wrong'])->getStatusCode();
        }

        $this->assertContains(429, $statuses);
    }
}
