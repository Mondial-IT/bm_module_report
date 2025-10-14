Here’s a drop-in **`README.md`** you can place in `web/modules/custom/bm_module_report/README.md`.

---

# BM Module Report

**Drupal 11** admin report that inventories your site’s modules and maps them to their **Composer packages**.
It helps you quickly see:

* **composer require** command (incl. **version constraint** from `composer.lock`)
* **Module name** (human-readable)
* **Name on disk** (machine name)
* **Composer package** (e.g., `drupal/webform`)
* **Enabled** (Yes/No)

Extras:

* **Filters out core modules** (focus on contrib & custom).
* **Highlights** (yellow) all rows belonging to a Composer package where **no component is enabled** (package present but unused).
* **Download** a ready-to-paste set of **`composer.json` require lines** for **packages with ≥1 enabled module**.

---

## Why this is useful

* Audit your **contrib footprint** and spot unused packages.
* Generate **deterministic Composer constraints** from what you already have installed.
* Prepare **lockstep updates** across environments by exporting consistent `"vendor/package": "^x.y",` lines.

---

## Requirements

* Drupal **11.x**
* Composer **2.x** (reads versions via `Composer\InstalledVersions`)
* Admin access (`access administration pages`)

---

## Installation (Drupal-recommended project structure)

1. Place the module in your codebase (custom path):

```
web/modules/custom/bm_module_report
```

2. Enable it:

```bash
drush en bm_module_report -y
drush cr
```

*(Optional) Declare it in your root `composer.json` as a path repo if you like to manage custom modules with Composer:*

```json
{
  "repositories": [
    { "type": "path", "url": "web/modules/custom/*", "options": { "symlink": true } }
  ]
}
```

> This module itself is custom; it **does not** require a Packagist package.

---

## Usage

* Go to **Reports → Module Composer Report**
  URL: `/admin/reports/module-composer`

What you’ll see:

| composer require (with version)              | Module name   | Name on disk    | Composer package       | Enabled |
| -------------------------------------------- | ------------- | --------------- | ---------------------- | ------- |
| `composer require drupal/admin_toolbar ^3.6` | Admin Toolbar | `admin_toolbar` | `drupal/admin_toolbar` | Yes     |

* **Sorting**: The table is sorted by the **composer require** column first, then by **Module name**.
* **Highlighting**: Rows for a package where **none** of its modules are enabled get a **yellow** background.
* **Core modules are hidden** automatically.

### Download composer.json lines

Click the button **“Download composer.json lines (enabled packages)”** to get a file like:

```txt
"drupal/admin_toolbar": "^3.6",
"drupal/token": "^1.13",
"drupal/pathauto": "^1.12",
```

Paste these into your root `composer.json` under `"require"`, then run:

```bash
composer update
```

---

## How version detection works

* The module uses `Composer\InstalledVersions::getPrettyVersion('vendor/package')` to read the version from your **installed** packages (effectively from `composer.lock`).
* It converts pretty versions to sensible constraints for day-to-day work:

  * `3.6.0` → `^3.6`
  * `v1.2.3` → `^1.2`
  * `1.x-dev` / `dev-main` remain as dev refs (no caret).
  * Unknown → `*` (download only).

---

## What’s included / not included

✅ Included

* Automatic mapping of modules under `modules/contrib/**` to `drupal/<projectRoot>`.
* Safe fallback guessing when Composer metadata is incomplete.
* Custom modules are labeled **`custom`** (no composer line).

❌ Not included (yet)

* CSV export
* UI filters (enabled/disabled/custom/contrib)
* Click-sortable headers (tablesort)

*(If you need any of these, they’re straightforward additions.)*

---

## Troubleshooting

* **“Call to undefined method Drupal\Core\Url::render()”**
  This module renders links as **final HTML strings** (no `Url` objects in attributes). If you see this error, it’s likely coming from a theme/preprocess or another module on the same page. Test on **Claro/Olivero** and confirm no preprocess is altering the table rows.

* **No versions shown**
  Ensure Composer 2 is present and modules are installed via Composer so `InstalledVersions` can see them.

* **Missing packages in mapping**
  The report resolves a module’s **project root** inside `modules/contrib/<project>`; deeply nested submodules are automatically mapped to their top-level package. If your contrib path is non-standard, adjust your layout or the mapping logic.

---

## Security policy

This module **does not process user input** and only reads local Composer/extension metadata.
If you discover a security issue, treat it like any custom code in your organization: restrict access to **admin routes** and review diffs before deployment.

---

## Uninstall

```bash
drush pmu bm_module_report -y
drush cr
```

*(This only removes the admin page; it does not change Composer state.)*

---

## Changelog (summary)

* **1.0.0**

  * Initial release: module report, core filter, package grouping, yellow highlight, download JSON lines, version constraints from `composer.lock`.
  * Columns: *composer require (with version)*, *Module name*, *Name on disk*, *Composer package*, *Enabled*.

---

## License

Proprietary / internal use (custom). Adjust to your organization’s policy as needed.

---

## Maintainers

Blue Marloc – Engineering
Open an internal ticket with your Ops/Platform team for changes or feature requests.
