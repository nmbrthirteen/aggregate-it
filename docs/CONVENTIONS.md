# Engineering Conventions

Aggregate It follows the house style established in the author's `crm-connect`
plugin. This document is the canonical reference so the two codebases stay
consistent. Where this doc and the earlier architecture notes disagreed, **this doc
wins** (it superseded the initial Action Scheduler / WordPress.org assumptions).

Naming map from crm-connect → aggregate-it:

| Thing | crm-connect | aggregate-it |
|---|---|---|
| PHP namespace (PSR-4 → `src/`) | `CrmConnect\` | `AggregateIt\` |
| Constant prefix | `CRM_CONNECT_` | `AGGREGATE_IT_` |
| Text domain | `crm-connect` | `aggregate-it` |
| Option / table prefix | `crm_connect_` | `aggregate_it_` |
| REST namespace | `crm-connect/v1` | `aggregate-it/v1` |
| Composer package | `nmbrthirteen/crm-connect` | `nmbrthirteen/aggregate-it` |
| Hook prefix | `crm_connect_` | `aggregate_it_` |

---

## 1. Bootstrap (main plugin file)

Mirror `crm-connect.php` exactly:

- Plugin header with **GitHub `Plugin URI`**, `Version`, `Requires PHP: 8.0`,
  `License: GPL-2.0-or-later`, `Text Domain`.
- `defined( 'ABSPATH' ) || exit;`
- Define `AGGREGATE_IT_VERSION`, `_FILE`, `_PATH`, `_URL`.
- Composer `vendor/autoload.php` if readable, **else a `spl_autoload_register` fallback**
  for the `AggregateIt\` prefix (so the plugin runs from a git checkout without
  `composer install`).
- **Plugin Update Checker (PUC)** wired to the GitHub repo with
  `enableReleaseAssets()` and `setBranch( 'main' )`.
- `register_activation_hook` → `Database\Schema::install`.
- `register_deactivation_hook` → `Plugin::deactivate` (clears scheduled hooks).
- `add_action( 'init', … )` with a **PHP-version guard** (admin notice + bail if too
  old) then `Plugin::instance()->boot()` wrapped in `try/catch` that `error_log`s under
  `WP_DEBUG`.

## 2. Plugin container (`src/Plugin.php`)

- `final class Plugin` **singleton** (`instance()`), private constructor.
- **Manual dependency injection** — no DI container. Services are constructed in the
  constructor; shared collaborators are passed in explicitly.
- `boot()` calls `->register()` on each service, loads textdomain, runs
  `Schema::maybe_upgrade()`, and fires `do_action( 'aggregate_it_booted', $this )`.
- Typed accessor methods (`settings()`, `queue()`, `providers()`, …) for services that
  others need.

## 3. Service pattern

Every subsystem is a `final class` with:
- constructor **property promotion** for its dependencies,
- a `register(): void` method that wires all its WordPress hooks (and nothing else
  runs at construction time).

```php
final class Importer {
    public function __construct(
        private QueueStore $queue,
        private SourceRepository $sources,
        private Settings $settings
    ) {}

    public function register(): void {
        add_action( 'aggregate_it_import_feeds', [ $this, 'run' ] );
    }
}
```

## 4. Database (`src/Database/Schema.php`)

- One `Schema` class. **Status/state values are class constants** (`STATE_FETCHED`,
  `STATE_EXTRACTED`, …) — never bare strings in queries.
- `table( string $name )` → `$wpdb->prefix . 'aggregate_it_' . $name`.
- `install()` builds tables with **`dbDelta`** + `$wpdb->get_charset_collate()`, then
  `update_option( 'aggregate_it_db_version', AGGREGATE_IT_VERSION )`.
- `maybe_upgrade()` re-runs `install()` when the stored db_version differs.
- `uninstall()` drops tables + deletes options (called from `uninstall.php`).
- All timestamps stored UTC via `gmdate( 'Y-m-d H:i:s' )`. Always `$wpdb->prepare`.

## 5. The queue — follow crm-connect's custom claim-based queue (NOT Action Scheduler)

This **supersedes the earlier Action Scheduler decision.** crm-connect's queue is a
proven, dependency-free pattern that solves the WP-Cron reliability complaints, and
matching it keeps one mental model across both plugins. Reuse it verbatim in shape:

- **DB-backed queue** with atomic claiming: a single `UPDATE … SET claim_token = …
  ORDER BY id LIMIT n` claims a batch, then `SELECT … WHERE claim_token = …` fetches it.
- **Stale-claim recovery:** rows stuck in an in-flight state past `STALE_CLAIM_MINUTES`
  are reclaimable (crash-safe).
- **Retries with exponential backoff:** `min( HOUR_IN_SECONDS, 30 * 2^(attempts-1) )`,
  `next_attempt_at` gates re-claim, `MAX_ATTEMPTS` → dead-letter.
- **Auto-pause:** a transient pause flag trips when consecutive failures (or, here,
  the spend cap) cross a configurable threshold; `do_action` notifies.
- **The "nudge":** a recurring **1-minute** custom cron interval is the floor, but a
  non-blocking `wp_remote_post` to `admin-ajax.php` (guarded by a `run_token` +
  `hash_equals`) kicks the worker *immediately* after enqueue for low latency. Reuse
  this — it's why the cron-backed queue feels real-time.

**Adaptations for the heavier AI pipeline** (the one place AI work stresses the
pattern — LLM calls take seconds, not the sub-second HTTP of a CRM upsert):

- The worker advances each `ai_items` row **one stage per claim**, not the whole
  pipeline in one request.
- **Small batch + a per-run wall-clock budget** (stop claiming new items when the run
  has burned, say, 20s) so a run never approaches `max_execution_time`.
- The **Batch API path composes naturally**: submit a batch job → set
  `next_attempt_at` to a poll time → the claim loop polls until the batch is ready,
  then advances the stage. Async-by-design, no long blocking calls.

## 6. Settings (`src/Settings.php`)

- Single option blob (`aggregate_it_settings`) loaded once; `get/set/update` helpers.
- **Typed accessor methods per setting** with sane defaults
  (`daily_spend_cap_usd(): float`, `target_post_type(): string`, …).
- **Secrets (AI/provider API keys) stored encrypted** via `Support\Crypto`
  (`set_api_key()` encrypts, `api_key()` decrypts) — never plaintext in the DB.

## 7. Support utilities (`src/Support/`)

Port these from crm-connect; they're directly reusable:
- **`Crypto`** — `aes-256-cbc`, key derived from `AUTH_KEY` via sha256, base64 envelope,
  graceful fallback when openssl is absent. For all stored API keys.
- **`EventLog`** — bounded ring buffer (max ~200) in an option, `error/warning/info`.
  Use for admin-facing notices (facts-guard flags, ambiguous entities, paused-queue).
  Per-stage **cost** accounting goes in the queryable `ai_log` *table*, not here.
- **`Json`**, **`Url`**, plus new ones as needed (`Html`/readability glue, `Vector`
  for cosine + float32 packing).

## 8. Provider abstraction (factory + filter override)

Mirror `Crm\ProviderFactory`: an interface, a factory that **resolves via filter first,
then falls back to the bundled default**, caching the resolved instance.

```php
public function get(): Ai_Provider {
    if ( $this->resolved === null ) {
        $provider = apply_filters( 'aggregate_it_ai_provider', null, $this->settings->provider_key(), $this->settings );
        $this->resolved = $provider instanceof Ai_Provider ? $provider : new DefaultAiProvider( /* … */ );
    }
    return $this->resolved;
}
```

Same shape for `Research_Provider`, `Keyword_Provider`, and the `Seo_Adapter`
(resolved by detecting the active SEO plugin). This **is** the freemium seam — premium
add-ons register via the filter.

## 9. Admin (`src/Admin/`)

- `Admin` registers `admin_menu` (menu + submenus), `admin_enqueue_scripts` (scoped to
  our screens, asset version via `filemtime`), and `admin_post_*` handlers.
- Every handler calls a `guard( $action )` helper: `current_user_can( 'manage_options' )`
  + `check_admin_referer( $action )`, then PRG-redirects with a notice query arg.
- A **`RestController`** under `aggregate-it/v1` backs the JS (nonce `wp_rest`); JS
  config + i18n via `wp_localize_script`.
- Views are plain PHP templates in `src/Admin/views/` rendered through a `render(
  $view, $context )` helper (`extract` + `require`).

## 10. Distribution & release

**Primary channel: GitHub releases + PUC self-update** (matches crm-connect) — *not*
WordPress.org for v1.

- `bin/release.sh X.Y.Z`: validates semver, bumps the version in the header + the
  `AGGREGATE_IT_VERSION` constant (perl in-place), `php -l`, commit `Release X.Y.Z`,
  tag `vX.Y.Z`, push.
- `.github/workflows/release.yml`: on tag, build `aggregate-it.zip` (prod composer
  install, exclude dev files) and publish the GitHub release asset; PUC consumes it.

> **If a WordPress.org listing is added later** (worth it for the SEO-discoverability of
> the plugin itself): PUC must be **conditionally disabled** in wp.org builds — the
> directory forbids third-party update sources — and external-AI-API usage must be
> disclosed in `readme.txt` with BYO-key opt-in. Keep the two build targets in mind but
> don't carry the cost now.

## 11. Code style

- `final` classes; `defined( 'ABSPATH' ) || exit;` atop every file.
- Typed properties + constructor promotion; return types everywhere.
- **snake_case method names** (WP idiom), `camelCase` only where matching a vendor API.
- i18n on every user-facing string: `__()`, `esc_html__()` with the `aggregate-it`
  text domain.
- `wp_json_encode`, `gmdate` for UTC, `$wpdb->prepare` always, escape on output.
- WordPress Coding Standards via PHPCS; targeted `// phpcs:ignore` with a reason, never
  blanket-disabled.
