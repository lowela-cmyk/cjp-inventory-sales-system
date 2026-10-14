<?php

namespace Tests\Feature\Audit;

use PHPUnit\Framework\Attributes\Group;

/**
 * AUD-C03 (CRITICAL): a MySQL dump with user emails, phone numbers, bcrypt password hashes and a
 * sessions table is committed to the repository (which has a GitHub remote).
 */
#[Group('audit')]
class C03CommittedDatabaseDumpTest extends AuditTestCase
{
    public function test_no_database_dumps_are_tracked_in_the_repository(): void
    {
        exec('git -C '.escapeshellarg(base_path()).' ls-files -- "*.sql" "*.sql.gz" "database/backups/*"', $tracked, $exitCode);

        $this->assertSame(0, $exitCode, 'git must be available to run this check.');
        $this->assertSame([], $tracked, 'Database dumps must not be committed: '.implode(', ', $tracked));
        $this->assertStringContainsString('database/backups', (string) file_get_contents(base_path('.gitignore')));
    }
}
