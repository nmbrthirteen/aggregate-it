# Implementation Plan

Four phases, each independently useful and shippable. Build order is dependency-driven:
a working aggregator first, then the AI+SEO core that makes it rank, then the entity
moat, then polish + premium seams.

- **Stack:** PHP 8.0+, WordPress 6.4+, custom claim-based queue (the `crm-connect`
  pattern — see [`CONVENTIONS.md`](CONVENTIONS.md)), Composer for dev deps.
- **Standard:** WordPress Coding Standards (PHPCS), GPL-2.0+ only.
- **Conventions:** match `crm-connect` — namespace `AggregateIt\`, singleton `Plugin`
  with `register()` services, `Schema`/`dbDelta`, encrypted `Settings`, provider
  factory-with-filter, GitHub + PUC distribution. Details in `CONVENTIONS.md`.
- **Testing:** PHPUnit + WP test suite; integration tests against canned feed fixtures
  and a mock AI provider (deterministic, zero-cost).
- **Definition of done per phase:** feature works end-to-end on a real feed, has tests,
  and the relevant hooks/filters from the extension API are in place.

> The extension-API seams (`ARCHITECTURE.md` §6) are NOT a final phase — each phase
> wires its own hooks as it lands. Retrofitting them is the one thing that forces a
> rewrite later.

---

## Phase 0 — Scaffold (foundation)

- Plugin bootstrap (mirror `crm-connect.php`): header w/ GitHub `Plugin URI`, constants,
  composer autoload + spl fallback, **PUC GitHub updater**, activation→`Schema::install`,
  init-boot with PHP guard + try/catch.
- Singleton `Plugin` container (manual DI, `register()` services); `Settings`
  (single-option, `Crypto`-encrypted keys); `Support\` ports (`Crypto`, `EventLog`,
  `Json`, `Url`, `Vector`).
- **Custom claim-based `QueueStore` + `QueueWorker`** (port crm-connect's queue): atomic
  claiming, stale-claim recovery, backoff, dead-letter, transient auto-pause, the nudge.
  Drive the item **state machine** (one stage per claim; states defined, transitions
  logged, no-op stages).
- DB migrations for all custom tables (`ai_sources`, `ai_items`, `ai_clusters`,
  `ai_vectors`, `ai_log`) via `Schema` + `dbDelta` + db_version.
- `CostMeter` + `ai_log` writer + `SpendCap` (wired to the queue's auto-pause, even
  before paid calls exist).
- The provider/adapter **interfaces** (`Ai_Provider`, `Research_Provider`,
  `Keyword_Provider`, `Seo_Adapter`) + factory-with-filter resolution + a **mock AI
  provider** for tests.
- Release tooling: `bin/release.sh X.Y.Z` + `.github/workflows/release.yml` (tag → build
  `aggregate-it.zip` → publish GitHub release asset).

*Ships:* nothing user-visible, but every later phase plugs into a stable spine.

---

## Phase 1 — Ingestion engine (a working aggregator) — ✅ built

**Goal:** add feeds, import items, deduplicate, land them as posts. No AI yet.

> Status: implemented. `Source\SourceRepository` (CRUD + due-scheduling),
> `Source\Importer` (RSS/Atom via core SimplePie + JSON Feed fallback),
> `Source\HttpFetcher` (robots.txt + per-domain rate limit + cache + honest UA),
> `Source\ContentExtractor` (hybrid feed/readability, swappable via
> `aggregate_it_extract_html`), `Pipeline\ExtractStage` (replaces the `fetched`
> passthrough; flags thin items), GUID + content-hash dedup in `ItemStore`, and the
> Sources admin screen. Items currently flow through to `published` via passthroughs
> (no rewrite yet — that's Phase 2).

- **Sources admin:** CRUD for feeds, per-feed settings, schedule, status, health view.
- **Fetch + parse:** SimplePie (RSS/Atom) + JSON Feed; map items → `ai_items`.
- **`HttpFetcher`:** robots.txt compliance, per-domain rate limiting, honest UA,
  response caching.
- **`ContentExtractor`:** feed-content-if-full-enough → else readability (no JS);
  structural boilerplate strip; **min-length gate** (skip + log thin items).
- **Syntactic dedup:** `(source_id, guid)` + `content_hash`.
- **Scheduling:** per-feed import enqueued onto the custom queue; a 1-minute cron floor
  plus the nudge (NOT raw WP-Cron for the work itself).
- Items publish as plain posts (rewrite is phase 2) so the pipeline is observable.

*Ships:* a polite, reliable RSS aggregator with full-content extraction — already on
par with the baseline tools, minus AI.

**Exit criteria:** a real feed imports on schedule; truncated feeds get full content;
duplicates are suppressed; rude-scraping safeguards verified.

---

## Phase 2 — AI + SEO core (the ranking engine) — ✅ built

**Goal:** faithful rewrite, semantic dedup, living canonical posts, full SEO output.

> Status: implemented. `Vector\VectorStore` (brute-force cosine), `Pipeline\EmbedStage`
> (paid), `Cluster\Clusterer` + `ClusterStage` (3-signal: similarity ∧ time window ∧
> shared salient fact), `Ai\Rewriter` (single structured call), `Ai\FactsGuard`
> (invented-fact + novelty detection), `Keyword\KeywordStrategy` (auto-infer + list +
> strategic skip), `Publish\PostFactory` (living posts + dated append), `Seo\Seo`
> (Yoast/RankMath/SEOPress meta adapter + schema suppression + native fallback),
> `Seo\SchemaGraph` (native JSON-LD with dateModified + citations), `Pipeline\ComposeStage`
> (new-cluster create vs matched-cluster novelty-append), `PaidStage` deferral on spend
> cap, and the original-vs-rewrite editor meta box. Verified with the MockProvider.

- **`Embedder`** → `ai_vectors`; `VectorStore` with brute-force cosine.
- **`Clusterer`** — 3-signal match (similarity ∧ shared entity ∧ time window);
  borderline → log + new cluster. Assign `topic_cluster_id`.
- **`Rewriter`** — the **single structured AI call** → `{body, seo_title,
  meta_description, slug, primary_keyword, entities[], facts[]}`. Template-driven
  prompt (per-feed overridable): faithful rewrite, strip promo, keyword-natural.
- **`FactsGuard`** — deterministic invented-fact detector → flags item.
- **Living posts (B):** new cluster → create canonical post; matched cluster →
  **`NoveltyGate`** → append dated section + bump `dateModified` + merge `fact_set`.
- **Keyword layer 1** (auto-infer) + **layer 2** scaffold (keyword list + strategic-mode
  toggle). Keyword **locked at cluster creation**.
- **`SlugGenerator`** (flat keyword slug, no dates).
- **`SeoAdapter`** — Yoast / Rank Math / SEOPress strategies + native fallback (title +
  meta); **suppress plugin schema** on our objects.
- **`SchemaGraph`** — native JSON-LD: Article/NewsArticle, `datePublished` +
  `dateModified`, author, publisher, image, `mainEntityOfPage`, `citation` → sources.
  (`about`/`mentions`/entity hubs arrive with phase 3.)
- **Cost controls live:** batch API path, content-hash + prompt caching, per-feed cost
  tracking, hard daily spend cap pauses paid stages.
- **Diff view:** original vs rewrite in the admin.

*Ships:* an SEO content engine — original faithful posts, one living URL per story,
freshness signals, correct meta + schema, bounded cost.

**Exit criteria:** two near-duplicate feed items produce ONE living post; a genuine
update appends + bumps `dateModified`; restated wire copy is suppressed; meta lands in
the active SEO plugin with no duplicate schema; spend cap halts paid work on trip.

---

## Phase 3 — Entity engine (the moat) — ✅ built

**Goal:** auto-build the entity hub graph + internal links + entity schema.

> Status: implemented. `Entity\DelegationRules` (config store) + `EntityRegistrar`
> (registers a public CPT per rule), `Entity\Name` (normalization), `EntityRepository`
> (entity CPT CRUD + aliases/sameAs + merge), `EntityResolver` (conservative
> link/create/skip bands, type-constrained fuzzy), `EntityResearcher` (in-article stub +
> pluggable provider for cited facts/sameAs), `EntityLinker` (first-mention capped links
> + `_ai_entity` relationships), `Pipeline\EntityStage` (replaces the `entity_linked`
> passthrough), `HubRenderer` (entity schema + auto-growing related-coverage list),
> `SchemaGraph` about/mentions, and an Entities admin screen (rules + entity list + merge
> tool). Verified: normalization + the three resolution bands.

- **Delegation-rule config** (`ARCHITECTURE.md` §5) + admin UI: map entity type → CPT →
  field map → match/research/linking settings.
- **`EntityResolver`** — normalize → exact/alias → type-constrained fuzzy+embedding →
  band (link / create / skip-and-log); **batch-dedup guard**.
- **`EntityResearcher`** — default in-article stub (zero deps); pluggable provider path
  for cited external facts + `sameAs`. Strict cited stubs.
- **`EntityLinker`** — first-mention contextual links, capped per post; relationship
  taxonomy (bidirectional).
- **`HubRenderer`** — pillar template: description + cited facts + `sameAs` +
  auto-growing related-posts list; Organization/Person/Product schema.
- **Schema upgrade:** add `about`/`mentions` to post graph now that hubs exist.
- **`EntityMerge`** admin tool — merge B→A, repoint everything.
- **Ambiguous-entity queue** in the dashboard.

*Ships:* the durable SEO advantage — topical authority via auto hub-and-spoke linking +
machine-readable entity/citation graph.

**Exit criteria:** a mentioned company links to an existing hub when present, creates a
strict stub when novel, and **skips + logs** when ambiguous; no duplicate entities
across a batch; merge tool repoints cleanly; hub pages render with schema + growing
related list.

---

## Phase 4 — Polish + premium seams — ✅ built

**Goal:** the indexing/freshness loop, media, EEAT, ops maturity, and the paid hooks.

> Status: implemented. `Seo\IndexNow` (key file + publish/update ping via
> `aggregate_it_publish_ping`), `Publish\ImageImporter` (sideload featured image + alt;
> feed-enclosure + og:image capture), EEAT author + `Seo::append_disclosure`, feed-health
> tracking in `Importer` (consecutive-fail → dead) + admin notice, a full **Settings**
> screen (provider/key, spend cap, post type, author, images, IndexNow, clustering,
> keywords, feeds), and `docs/EXTENDING.md` documenting every provider/hook seam. All
> phases 0–4 complete.

- **`IndexNow`** — ping on publish + novelty update; sitemap `lastmod` via SEO plugin.
- **Images:** featured-image import (configurable off/import/if-licensed, default
  conservative) + **AI alt text**.
- **EEAT:** configurable author (bio + `author` schema); optional AI-assisted
  disclosure. No fake personas.
- **Feed-health monitor:** dead/changed/stale detection + admin alerts.
- **Observability dashboard:** pipeline funnel, per-feed cost, facts-guard flags,
  ambiguous-entity queue, feed health.
- **Premium provider seams finalized & documented:** keyword-data provider (layer 3),
  research provider, headless extraction, multilingual, strategic-mode packs — all as
  consumers of the phase-0…3 interfaces.

*Ships:* production-grade ops + fast indexing + the freemium boundary.

**Exit criteria:** a novelty update fires IndexNow and bumps lastmod; images import with
alt text; dead feeds alert; the dashboard surfaces cost + flags; a sample premium
provider registers through the public interface with zero core changes.

---

## Cross-cutting (every phase)

- **Tests:** mock AI provider keeps the suite deterministic and free; feed fixtures for
  ingestion; assertions on state transitions.
- **Cost discipline:** no feature added without its `CostMeter` accounting.
- **WordPress.org readiness:** external-API disclosure in `readme.txt`, BYO-key opt-in,
  no obfuscation, sanitize/escape/nonce everywhere, GPL-compatible deps only.
- **Docs:** keep `DECISIONS.md` authoritative — if a decision changes during build,
  amend it there first, then the code.

---

## Open items to verify at build time

1. **Readability library:** confirm a maintained, **GPL-compatible** PHP readability
   port (license check is a hard gate — Apache-2.0 is compatible with GPLv3 but not
   GPLv2-only; pick accordingly given GPL-2.0-**or-later**).
2. **Cheapest viable model IDs + batch availability** for the default `Ai_Provider`
   (decided at implementation, behind the adapter — not baked into core).
3. **Embedding model + dims** (sets `ai_vectors.dims` and the cosine packing format).
4. **SEO-plugin meta keys** for Yoast / Rank Math / SEOPress current versions (adapter
   strategies); confirm each exposes a schema-suppression filter.
5. **IndexNow key** provisioning + host-key file placement.
