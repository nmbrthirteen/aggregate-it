# Plan: Custom Website Sources · Activity Log · Topic Hubs (Linked Pages)

Three independent but mutually reinforcing features. Build order below is by
dependency, not by the order they were requested. Everything here is
config-driven — no site-specific code, no hardcoded selectors or post types.

Guiding constraints (from project memory + this request):
- Generalize everything; nothing hardcoded for any specific site.
- Observability over silent fallbacks — every automated decision is recorded.
- Clean/minimal UI, no decorative code comments.

---

## Verified ground-truth (2nd research pass) — corrections that change the build

These were checked against the actual source; several invalidate the obvious-but-wrong
design:

1. **Live pipeline order is `fetched → extracted → embedded → clustered →
   entity_linked → published`** (`Pipeline::default_order()`). `STATE_REWRITTEN`
   exists in `Schema` but is **not in the active order** — the AI rewrite happens
   *inside* the `clustered` stage (`ComposeStage`), which is the real publish step.
2. **`PassthroughStage` is a no-op Phase-0 placeholder** (advances state, does
   nothing). It is NOT a "publish structured data verbatim" mechanism — do not
   reuse it for passthrough scraping.
3. **`PostFactory::create()` is hard-coupled to AI `$structured` output**
   (`seo_title`, `slug`, `rewritten_body`, `facts`) and to the **global**
   `Settings::target_post_type()`. There is **no per-source post type** today — it
   is a genuine gap a scraper must fill (add `PostFactory::create_mapped()`).
4. **Robots.txt + throttle will self-sabotage a naive scraper.** `HttpFetcher::fetch()`
   *throws* on a robots.txt disallow, and `throttle()` *throws* `RateLimited` if the
   same host was hit within 3s. `Importer::import()` wraps parsing in a try/catch
   that increments `consecutive_fails` and can mark the source **dead**. So multiple
   fetches at import time would kill the source. Mandatory design: **import-time does
   exactly one fetch** (the listing or sitemap page); **detail-page fetches are
   deferred to a per-item pipeline stage** where `RateLimited → defer` already works.
   This mirrors how RSS already splits lightweight import from heavy `ExtractStage`.
   There is an SSRF override filter (`aggregate_it_allow_private_hosts`) but **no
   robots override** — a per-source robots toggle (with a visible ToS warning) must
   be added.
5. **No CSS-selector or HTML5 library is present**; runtime deps are zero (only
   `php >=8.0`), and the plugin autoloads from `src/` when `vendor/` is absent
   (`aggregate-it.php:33-51`). Recommended: stay dependency-free — use
   `DOMDocument`/`DOMXPath` (already used in `ContentExtractor`) and accept **XPath**
   plus a small CSS→XPath converter for the common subset. Adding
   `symfony/dom-crawler` is the fallback only if the release workflow is confirmed to
   `composer install --no-dev` and bundle `vendor/`.

Reusable as-is: **`EntityRegistrar::register_post_types()` is the exact template**
for registering a scraper's target CPT from config; **`HttpFetcher`** gives SSRF
guard, robots, per-host throttle, UA, and caching; the **`Entry` format**
(`{guid,url,title,content,image,date}`) and `Importer::ingest()`'s guid/hash dedup
work unchanged — but custom scraped fields (location, venue, end_date) need a new
`fields` payload threaded through `ItemStore::enqueue()` into `Item->flags`.

---

## Phase 0 — Activity Log (foundation) — IMPLEMENTED

Rationale: a durable "what changed from what" record is the substrate that makes
both the scraper and Topic Hubs legible. Build it first; the other phases emit
into it.

**Shipped in working tree** (not yet released): built on the pre-existing
`wp_aggregate_it_log` table (was cost-only) rather than a new table. Added
`ActivityLog` (record/query/count/recent/clear), made `EventLog` a thin facade so
all 17 call sites are now durable + DB-backed, surfaced the three previously-silent
`ComposeStage` suppressions (thin / no-keyword / no-novelty) and an `ExtractStage`
before→after summary, added a `/activity` REST route and an **Activity** admin page
(server-rendered + 5s live refresh + filters + per-row before/after detail). `clear`
deletes only zero-cost rows so spend history survives. New `ActivityLogTest`; full
suite 64 green.

Migration: gated on a new `Schema::DB_VERSION` (not the plugin version), so the
dbDelta column-add runs on the next boot of any existing install — independent of a
release. Hardened after an adversarial review pass: activity writes that fail
`error_log` once instead of vanishing silently; `wp_json_encode` failures store NULL;
the 5s live refresh pauses while a row is expanded or text is selected; the Live
toggle disables on paged views; JS-built labels are i18n'd. Note (volume): the old
`EventLog` capped at 200 rows; the activity log now writes ~1 info row per extracted
item, pruned by `Retention` at `retention_days` (default 90) — deliberate trade for
durability/observability.

### Problem with today's logging
`src/Support/EventLog.php` stores at most 200 events in a single WP option,
errors/warnings only, no state transitions, no before/after. The pipeline is a
clean state machine but none of its transformations are recorded.

### Data model
New table `wp_aggregate_it_activity` (migration in `src/Database/Schema.php`):

| column | type | notes |
|---|---|---|
| id | bigint PK | |
| item_id | varchar | nullable; ai_items row |
| source_id | bigint | nullable |
| post_id | bigint | nullable |
| type | varchar(40) | extract / cluster / compose / entity / image / import / scrape / suppress |
| from_state | varchar(20) | nullable |
| to_state | varchar(20) | nullable |
| summary | text | human one-liner ("feed snippet → 4.2k-char body") |
| detail | longtext JSON | compact before/after payload |
| level | varchar(10) | info / warning / error |
| created_at | datetime | UTC |

Index on (created_at), (item_id), (source_id), (type).

### Code
- `src/Support/ActivityLog.php` — `record(type, summary, detail, ctx)`,
  `query(filters, paging)`, `prune(keep_days)`. Keep `EventLog` as a thin
  back-compat shim that forwards to ActivityLog, or fold its call sites over.
- Instrument transitions at the single choke point `ItemStore::advance()` plus
  each stage where a transformation has a meaningful before/after:
  - `ExtractStage` — feed length → extracted length, image source chosen/missed.
  - `ClusterStage`/`Clusterer` — new story vs merged into #N, similarity score,
    shared fact (the "close call" data already exists).
  - `ComposeStage` — original feed title → SEO title; raw → rewritten length;
    **suppression + reason** (thin / keyword / strategic) which is currently
    silent.
  - `EntityStage` — entities detected → linked / created / skipped + score.
  - `ImageImporter` — which candidate won.
- Retention: hook into `src/Maintenance/Retention.php` to prune by age/row cap.

### UI
- New admin page **Activity** (or repurpose the Tools "Activity log" block).
- REST endpoint in `src/Admin/RestController.php` returning paged rows + filters
  (source, item, type, level, date).
- View: live (poll every few seconds) timeline, newest first; filter bar;
  each row expandable to a per-item **journey** showing each transition's
  before→after diff. Reuse existing `ai-events` styling.

---

## Phase 1 — Custom Website Sources (generalized scraper)

The centerpiece. Lets a user point at any HTML site (e.g. igamingcalendar.com),
extract repeating items, and map fields onto a chosen post type. igaming is
server-rendered HTML with a sitemap but **no RSS / JSON / JSON-LD** — so an HTML
scraper is required, and feeds/structured-data are an accuracy bonus when present.

### 1a. Source type + parser factory
- Add `source_type` to the Source model (`src/Source/Source.php`), default
  `"rss"` → fully backward compatible. Values: `rss` | `scrape`.
- Introduce `src/Source/Parser/SourceParser.php` (interface `parse(Source): Entry[]`).
- Extract today's feed logic from `Importer::parse()` into
  `src/Source/Parser/RssParser.php`.
- Add `src/Source/Parser/ScraperParser.php`.
- `Importer::parse()` becomes a dispatch on `source_type` (the existing seam).

### 1b. Discovery — how items are found (config)
Stored in `Source::settings['scrape']['discovery']`:
- `mode: "list" | "sitemap"`
- list mode: `list_urls[]`, `item_selector` (the repeating row), `detail` (bool —
  follow each row's link to a detail page for richer fields).
- sitemap mode: `sitemap_url`, `url_filter` (regex) — each matching URL is an item.

`src/Source/Scrape/Discovery.php` resolves a discovery config into a list of
item handles (inline row DOM and/or detail URL). **Runs at import time with a single
`HttpFetcher::fetch()`** (the listing or sitemap page) — per finding #4, detail-page
fetches must NOT happen here. Each handle becomes an `Entry` whose `url` is the
detail link and whose inline row fields ride in a new `fields` payload. The detail
fetch + richer extraction is deferred to `ExtractStage` (per item, throttle-safe).
Scraping a path blocked by robots.txt requires the per-source robots-override toggle
(finding #4) — surfaced in the UI with a ToS warning, implemented as an
`aggregate_it_respect_robots` filter check keyed by source.

### 1c. Extraction — accuracy cascade
`src/Source/Scrape/FieldExtractor.php`. For each item, resolve every configured
field by trying, in order of accuracy:
1. **Structured data** — schema.org JSON-LD / microdata on the page (most
   accurate when present).
2. **Feed/sitemap metadata** — when the discovery source already carries it.
3. **CSS/XPath selectors** — `{ selector, attr (text/html/href/src/datetime/any
   attr), regex?, transform? (date-parse, trim) }`. Add a small CSS→XPath
   converter (or accept XPath directly) over `DOMDocument`/`DOMXPath`.

Field set is open-ended: `title, url, guid, date, image, content` + arbitrary
custom fields (`location, venue, end_date, category`, …). Nothing event-specific
is hardcoded.

### 1d. Selector configuration — AI-assisted + validated (most accurate practical path)
`src/Source/Scrape/SelectorAssistant.php`:
- User pastes a listing URL (and optionally a detail URL).
- Fetch sample page(s) via `HttpFetcher` (already has SSRF guard, robots,
  per-host rate-limit, cache).
- First detect structured data; if absent, ask the **already-configured AI
  provider** to propose `item_selector` + a field→destination map.
- **Validate before saving**: run the proposed selectors against N sample rows
  and show the extracted preview; user confirms/edits. Manual selector entry is
  always available as the floor.
- On each scheduled scrape, re-validate; selector drift (rows suddenly empty)
  emits a `warning` activity row — the activity log becomes the early-warning
  system for site changes.

### 1e. Mapping to a post type ("post type connection")
`Source::settings['scrape']['mapping']`:
- `post_type` (per-source; e.g. `event`).
- `fields`: each extracted field → a destination:
  `post_title | post_content | post_excerpt | post_date | featured_image |
  taxonomy:<tax> | meta:<key>`.

`src/Publish/FieldMapper.php` applies the map to build the post + meta + terms +
featured image. Example (igaming): `name→post_title`, `date→meta:event_start`,
`location→taxonomy:event_location`, `detail_url→meta:source_url`, into an `event`
CPT.

Generic CPT/taxonomy registration from config (reuse the existing
`DelegationRules` / `EntityRegistrar` pattern) so a user can define an "Events"
post type without code.

### 1f. Processing mode — passthrough by default
- Per-source `processing: "passthrough" | "rewrite"`, set at enqueue as
  `Item->flags['passthrough']`. Scraped structured data defaults to **passthrough**:
  fields map in verbatim, no AI rewrite, so dates/venues can't be hallucinated.
  News-style sources opt into `rewrite`.
- Mechanism (corrected — the pipeline is one-stage-per-state and stages route via
  their return value): add a cheap `if ($item->flags['passthrough'])` guard to each
  AI/cost stage so passthrough items skip the expensive work but still traverse the
  state machine:
  - `ExtractStage` (fetched): if passthrough, optionally fetch the detail page
    (throttle-safe; `RateLimited → defer`) and run `FieldExtractor` into
    `flags['fields']`; advance — no readability/AI.
  - `EmbedStage` / `ClusterStage`: if passthrough, skip embedding/clustering; advance.
  - `ComposeStage` (clustered): if passthrough, call **`PostFactory::create_mapped()`**
    (no `Rewriter`, no `CostMeter`) using the per-source post type + field/meta/
    taxonomy/image map; advance.
  - `EntityStage`: if passthrough, skip entity linking; advance to published.
  - This adds no pipeline refactor and zero AI cost. Dedup-by-guid/content-hash in
    `Importer::ingest()` still applies unchanged.

### 1g. UI
`src/Admin/views/sources.php` + handlers in `src/Admin/Admin.php`:
- Source form branches on `source_type`.
- Scrape config: discovery mode, listing/sitemap URL, "Auto-detect fields"
  (runs SelectorAssistant) → editable field table with live preview → post-type
  selector → field→destination mapping → processing mode → recurrence/filters
  (e.g. skip past-dated events via a chosen date field).

### Caveats to surface in UI (not hidden)
- robots.txt: `HttpFetcher` honors it; scraping may need a per-source override
  with a visible warning (the user owns the ToS decision per source).
- JS-rendered sites won't work with DOM scraping (igaming is fine). Detect an
  empty extraction and warn rather than silently importing nothing.

---

## Phase 2 — Reimagine "Linked Pages" → legible Topic Hubs

Same entity-hub engine (`EntityStage`, `DelegationRules`, `EntityRepository`,
`HubRenderer`), made legible. The engine is fine; its **invisibility** is the bug.

### Changes
- **Rename** the display label "Linked Pages" → e.g. **Topic Hubs** (Admin menu,
  dashboard card, `entities.php` heading). Keep internal slugs. Add a one-paragraph
  explainer of what it does: "AI finds companies/people/products in your articles,
  creates a hub page per topic, links the first mention, and grows a timeline."
- **Per-article legibility**: a panel (`src/Admin/PostMetaBox.php`) showing, for
  that article, every entity detected → linked / created / skipped, with the
  match score and reason. The data already exists in `EntityStage`; today it's
  thrown away.
- **Pipe decisions into the Activity log** (Phase 0): create/link/skip become
  visible activity rows.
- **Editable thresholds**: the hardcoded 92% link / 75% ambiguous floor move into
  settings (`DelegationRules` or `Settings`).
- **Optional stub review queue**: new hub pages created as `draft` until approved,
  so auto-creation can't publish thin pages unsupervised.

---

## Suggested sequencing
1. Phase 0 (Activity log) — small, foundational, immediately useful.
2. Phase 1 (Custom sources) — the headline feature; emits into the log.
3. Phase 2 (Topic Hubs legibility) — mostly UI + surfacing existing data into the log.

Each phase ships independently and is backward compatible (new `source_type`
defaults to `rss`; activity table is additive; Topic Hubs reuses the existing
engine).
