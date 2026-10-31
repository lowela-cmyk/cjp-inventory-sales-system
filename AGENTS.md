# AGENTS.md

## Database safety (permanent rule)

`cjp_inventory_sales_db` (XAMPP MariaDB, port 3306) holds the client's real data.

- Never run tests, `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, `db:seed`, or any other destructive
  command against `cjp_inventory_sales_db`.
- Never set `DB_DATABASE` (or `DB_URL`) to the real database in a test command. A `DB_DATABASE` set in the shell reaches
  `$_SERVER` and wins over phpunit's `force="true"`, so it would point the tests at the real database.
- Run tests only with the default config (SQLite in memory: `php artisan test`) or the MariaDB config against its own
  throwaway database (`composer test:mariadb`, database `cjp_test_mariadb`), from a shell with no `DB_*` variables set.
  `tests/TestCase.php` refuses to refresh any database that is not SQLite or named `*_test` / `*_test_mariadb`; keep
  that guard.
- Browser or data checks run on a QA copy (a separately named database loaded from a mysqldump), never by pointing
  tests at the real database.
- Before a migration that changes data on the real database: take a `mysqldump` backup into
  `/Users/johnlukereynon/cjp-backups/` (outside the repo, never committed), run it on a QA copy first, and wait for the
  user's OK.
