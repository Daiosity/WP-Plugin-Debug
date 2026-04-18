# Detector Fixtures

These small fixture plugins exist to regression-test the detector against a few
known-good and known-bad patterns.

They are not packaged into release ZIPs.

## Scenarios

### 1. Normal admin overlap

- `admin-overlap-alpha`
- `admin-overlap-beta`

Expected result:
- broad admin overlap only
- should stay in `overlap` or `shared_surface`
- should not become `high` or `probable_conflict`

### 2. Asset lifecycle mutation

- `asset-owner-alpha`
- `asset-mutator-beta`

Expected result:
- `asset-owner-alpha` registers and enqueues `pcd-fixture-shared-admin-style`
- `asset-mutator-beta` dequeues and deregisters the same handle later
- should produce asset lifecycle mutation evidence with concrete resource matching

### 3. Callback removal

- `callback-owner-alpha`
- `callback-remover-beta`

Expected result:
- `callback-owner-alpha` adds a callback on `template_redirect`
- `callback-remover-beta` removes that callback
- should produce callback mutation evidence and stronger pair attribution

### 4. REST route collision

- `rest-route-alpha`
- `rest-route-beta`

Expected result:
- both register the same REST route
- should produce an exact route-collision finding instead of vague API overlap

### 5. AJAX action collision

- `ajax-action-alpha`
- `ajax-action-beta`

Expected result:
- both attach to the same `wp_ajax_` and `wp_ajax_nopriv_` action
- should produce exact action-collision evidence

## How to Use

1. Copy one scenario pair into a local WordPress test site.
2. Activate the pair you want to test.
3. Reproduce the relevant request path if needed.
4. Run Conflict Debugger.
5. Confirm the finding category, severity, confidence, and wording match the expected outcome above.

## LocalWP Workflow

This repository includes a helper script to install the full fixture pack into the
LocalWP test site used in this workspace:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\sync-fixture-plugins.ps1
```

That syncs every fixture plugin from `tests/fixtures/wp-plugins/` into:

- `WordPress Site/app/public/wp-content/plugins/`

After syncing, use the site-scoped WP-CLI wrapper to inspect or activate fixtures:

```powershell
.\WordPress Site\wp.bat plugin list
.\WordPress Site\wp.bat plugin activate asset-owner-alpha asset-mutator-beta
.\WordPress Site\wp.bat plugin deactivate asset-owner-alpha asset-mutator-beta
```

If you are using that LocalWP site, reset the lab between runs with:

```powershell
$php = 'C:\Users\Christo\AppData\Local\Programs\Local\resources\extraResources\lightning-services\php-8.2.29+0\bin\win64\php.exe'
& $php -c '.\WordPress Site\tools\wp-cli\cli-php.ini' -r "require 'C:/Users/Christo/Documents/WordPress Plugin Development/Plugin Debug/WordPress Site/app/public/wp-load.php'; require 'C:/Users/Christo/Documents/WordPress Plugin Development/Plugin Debug/WordPress Site/tools/reset-pcd-lab.php';"
```

To replay a real authenticated admin request against that LocalWP site:

```powershell
powershell -ExecutionPolicy Bypass -File .\WordPress Site\tools\invoke-pcd-admin-request.ps1
```

Recommended habit:

- keep only `conflict-debugger` active for the clean baseline
- activate one fixture scenario pair at a time
- run a fresh scan after reproducing the relevant request path
