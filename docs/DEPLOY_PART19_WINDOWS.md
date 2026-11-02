# Part 19 deployment: client's Windows PC (XAMPP, Git Bash)

Run every command in **Git Bash** on the client's PC, one step at a time. Stop at any step whose result is not
what the step says to expect.

This brings the PC to the latest `main` and runs four migrations. They add or change the two Part 19 tables
(`sale_refunds`, `sale_change_logs`); the fourth migration also backfills sale codes on existing change-log rows.

- `2026_11_02_000001_create_sale_refunds_table`
- `2026_11_02_000002_create_sale_change_logs_table`
- `2026_11_03_000001_keep_sale_change_logs_of_deleted_sales`
- `2026_11_03_000002_add_sale_code_to_sale_change_logs`

## 0. Settings for this session

Adjust `APP_DIR` to where the project is on the PC, and `DB_NAME` to `DB_DATABASE` in its `.env`.

```bash
APP_DIR=/c/xampp/htdocs/cjp-inventory-sales-system
BACKUP_DIR=/c/cjp-backups
DB_NAME=cjp_inventory_sales_db
MYSQLDUMP="C:/xampp/mysql/bin/mysqldump.exe"
MYSQL="C:/xampp/mysql/bin/mysql.exe"
PHP="C:/xampp/php/php.exe"
cd "$APP_DIR"
```

XAMPP's MariaDB `root` user has no password by default. If the PC has one, add `-p` after `-u root` in every
`$MYSQLDUMP` and `$MYSQL` command below; it will ask for the password.

Checks before starting:

```bash
"$PHP" -v                        # PHP 8.2 or newer
node -v && npm -v                # Node is needed to rebuild the CSS (public/build is not in Git)
grep '^DB_DATABASE=' .env        # must match DB_NAME above
```

## 1. Record the current code

```bash
git status                       # expect: nothing to commit, working tree clean
mkdir -p "$BACKUP_DIR"
git rev-parse HEAD | tee "$BACKUP_DIR/part19-previous-commit.txt"
```

If `git status` shows local changes, stop and ask before going on.

## 2. Stop the app

Stop Apache in the XAMPP Control Panel (or press Ctrl+C in the window running `php artisan serve`), so nothing
writes to the database during the update. Leave **MySQL running**.

## 3. Back up the database

```bash
BACKUP="$BACKUP_DIR/${DB_NAME}-before-part19-$(date +%Y%m%d-%H%M%S).sql"
"$MYSQLDUMP" -h 127.0.0.1 -u root --single-transaction --skip-routines "$DB_NAME" > "$BACKUP"
tail -1 "$BACKUP"                # expect: -- Dump completed on ...
ls -l "$BACKUP"                  # expect: a size well above 0
```

Keep this file outside the project folder and never add it to Git.

## 4. Update the code and rebuild the CSS

```bash
git fetch origin
git pull --ff-only origin main   # must fast-forward; stop if it reports a conflict or "not possible"
git log --oneline -1             # expect: the latest Part 19 commit
npm ci
npm run build                    # expect: "built in ..."
```

No PHP packages changed, so `composer install` is not needed.

## 5. Check which migrations will run

```bash
"$PHP" artisan config:clear
"$PHP" artisan tinker --execute="echo DB::connection()->getDatabaseName();"   # expect: the DB_NAME above
"$PHP" artisan migrate:status | grep Pending
```

Expect **exactly** the four migrations listed at the top. If anything else is pending, stop and ask.

## 6. Migrate

```bash
"$PHP" artisan migrate --force
"$PHP" artisan optimize:clear
"$PHP" artisan migrate:status | grep -c Pending   # expect: 0
```

## 7. Check lifts completed with fuel still on the truck (read-only)

```bash
"$PHP" artisan lifts:completed-with-fuel
```

This changes nothing. If it lists any lifts, save the output and send it to the developer; do not fix them by hand.

## 8. Start the app and check

Start Apache again (or `"$PHP" artisan serve`), log in, and open Inventory, Sales, Ledger and Fuel Lifting. A
cancelled sale with payments shows a **Customer Refunds** section in its Payment History.

## Rollback

Pick the smallest rollback that fixes the problem.

**Code only** (the new tables can stay; the previous code ignores them):

```bash
git checkout "$(cat "$BACKUP_DIR/part19-previous-commit.txt")"
npm run build
"$PHP" artisan optimize:clear
```

Later, `git checkout main` returns to the latest code.

**Database only** (the Part 19 code keeps working without the tables; refunds are hidden):

```bash
# Save any refunds and change history recorded since the update first.
"$MYSQLDUMP" -h 127.0.0.1 -u root "$DB_NAME" sale_refunds sale_change_logs > "$BACKUP_DIR/part19-tables-$(date +%Y%m%d-%H%M%S).sql"
"$PHP" artisan migrate:rollback --step=4 --force
"$PHP" artisan migrate:status | grep 2026_11_0     # expect: all four Pending
```

**Full restore** from the step 3 backup (loses everything recorded after the backup):

```bash
"$MYSQL" -h 127.0.0.1 -u root "$DB_NAME" < "$BACKUP"
```

`$BACKUP` is set only in the session that took the backup; otherwise use the full path of the file in
`$BACKUP_DIR`.
