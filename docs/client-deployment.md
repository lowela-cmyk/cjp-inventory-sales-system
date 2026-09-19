# Client Deployment & Update Guide (Windows / XAMPP)

## 1. Diagnosis: Why the Developer Sees the Latest UI but the Client Does Not

When a developer makes UI updates and verifies them locally, the changes appear immediately. However, when the client executes `git pull origin main`, the client often reports seeing the old interface. This happens due to four distinct layers of caching and asset compilation:

1. **Git Tracks Source Code, NOT Compiled Assets (`.gitignore`)**:
   - The Blade templates (`resources/views/`), CSS (`resources/css/app.css`), and JavaScript (`resources/js/app.js`) are tracked by Git.
   - The compiled production assets (`public/build/`) and development markers (`public/hot`) are explicitly listed in `.gitignore`.
   - When the client runs `git pull origin main`, Git downloads the updated Blade templates and source CSS/JS, but **does not update `public/build/`**.
   - Without running `npm.cmd run build` on the client machine, the client's web server continues serving the old compiled CSS/JS from their previous build, or crashes with a `ViteManifestNotFoundException` if `manifest.json` is missing.

2. **Laravel Compiled View Cache (`storage/framework/views`)**:
   - Laravel compiles Blade templates into PHP files in `storage/framework/views`.
   - The `@vite(...)` directive is evaluated when Blade compiles the template. If the view cache is not cleared, Laravel serves the old compiled HTML pointing to outdated asset hash filenames.

3. **Laravel Config & Route Caching**:
   - If `php artisan config:cache` or `php artisan route:cache` was previously run, changes to `.env`, routes, or configuration are ignored until cleared.

4. **Browser Cache**:
   - Browsers aggressively cache CSS, JavaScript, and HTML documents. Even after rebuilding assets, a standard browser refresh (F5) may load cached resources from memory or disk cache.

---

## 2. Asset Build Setup Determination

- **Current Repository Setup**: Standard Laravel + Vite.
- **Git Tracking Policy**: Source files (`resources/css`, `resources/js`, `package.json`, `package-lock.json`, `composer.json`, `composer.lock`) are tracked by Git. Generated build directories (`public/build`, `public/hot`, `node_modules`, `vendor`) are ignored.
- **Determination**:
  - The client **must run `npm install` (or `npm.cmd ci`) and `npm run build` (or `npm.cmd run build`)** after pulling from GitHub.
  - Committing `public/build` directly into Git is discouraged in this workflow because it causes constant git merge conflicts on binary/hashed files and bloats the Git history.
  - If the client machine cannot run Node.js/npm, built assets must be compiled in a CI/CD pipeline or packaged as a release zip containing the `public/build` folder matching the commit.

---

## 3. Exact Client Update Steps (Windows / XAMPP PowerShell)

### Prerequisites:
1. Open **XAMPP Control Panel** and ensure **Apache** and **MySQL** are running (green status).
2. Open **PowerShell** (or Command Prompt) as Administrator.

### Step-by-Step Commands:

```powershell
# 1. Navigate to the project root
cd C:\xampp\htdocs\cjp_inventory_sales

# 2. Pull the latest code from GitHub
git pull origin main

# 3. Install/update PHP dependencies (omits dev tools in production)
composer install --no-dev --prefer-dist --optimize-autoloader

# 4. Install/update frontend packages
# Use npm.cmd to prevent Windows PowerShell script execution policy errors
npm.cmd ci

# 5. Compile production CSS and JavaScript assets
npm.cmd run build

# 6. Apply any new database migrations
php artisan migrate --force

# 7. Clear all Laravel caches in one master command
php artisan optimize:clear

# 8. Explicitly clear individual caches to guarantee freshness
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
```

---

## 4. Explanation of Each Command

| Command | Purpose |
|---|---|
| `git pull origin main` | Downloads the latest committed Blade views, CSS, JS, controllers, and migrations from GitHub. |
| `composer install --no-dev ...` | Ensures PHP packages and the Composer class autoload map are synchronized with `composer.lock`. |
| `npm.cmd ci` (or `npm.cmd install`) | Installs exact versions of frontend packages from `package-lock.json`. `npm.cmd` bypasses Windows PowerShell `.ps1` execution policy restrictions. |
| `npm.cmd run build` | Compiles `resources/css/app.css` and `resources/js/app.js` into hashed production files in `public/build/assets/` and writes `public/build/manifest.json`. |
| `php artisan migrate --force` | Runs any pending database migrations without prompting for confirmation. |
| `php artisan optimize:clear` | Removes compiled bootstrap files (configuration, routes, views, events, and application cache). |
| `php artisan config:clear` | Ensures `.env` and `config/*.php` changes are loaded directly from disk. |
| `php artisan route:clear` | Rebuilds dynamic route registrations so new endpoints are immediately accessible. |
| `php artisan view:clear` | Deletes all compiled Blade templates in `storage/framework/views`, forcing Laravel to re-evaluate `@vite(...)` with the new asset hashes. |
| `php artisan cache:clear` | Flushes application data cache (e.g., database cache table or dashboard memoization). |

---

## 5. Browser Cache & Verification Checklist

1. **Verify Asset Manifest**:
   - Check that `public\build\manifest.json` exists and contains mappings for `resources/css/app.css` and `resources/js/app.js`.
   - Verify that the referenced hashed files (e.g., `public\build\assets\app-*.css` and `public\build\assets\app-*.js`) exist.

2. **Check for Stale `public/hot`**:
   - If a developer or client previously ran `npm run dev`, a temporary file named `public/hot` was created.
   - While `public/hot` exists, Laravel attempts to load assets from a local Vite dev server (`http://localhost:5173`).
   - If the dev server is not active, all styling and scripts fail to load.
   - Check and delete if present:
     ```powershell
     if (Test-Path .\public\hot) { Remove-Item -Force .\public\hot }
     ```

3. **Hard Refresh the Browser**:
   - Open the web browser to the application URL (e.g., `http://localhost/cjp_inventory_sales/public` or your configured virtual host).
   - Press **Ctrl + F5** or **Ctrl + Shift + R** (Google Chrome, Microsoft Edge, Mozilla Firefox).
   - This bypasses the local browser cache and forces a full re-download of HTML, CSS, and JS.

4. **Verify in Developer Tools**:
   - Press **F12** to open Browser Developer Tools -> **Network** tab.
   - Check that the loaded `.css` and `.js` files match the hashed filenames in `public/build/manifest.json` and return **HTTP 200**.

5. **PHP OPcache (XAMPP Apache)**:
   - If controller or service changes do not appear even after clearing Laravel caches, PHP OPcache in Apache may be caching bytecode.
   - In **XAMPP Control Panel**, click **Stop** on Apache, then click **Start**.
