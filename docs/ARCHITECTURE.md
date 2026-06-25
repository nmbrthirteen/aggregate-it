# Architecture

How Aggregate It is put together: the pipeline, the data model, the subsystems, the
configuration schema, and the extension API that the freemium seams depend on.

Read [`DECISIONS.md`](DECISIONS.md) first — this document assumes those choices.

---

## 1. System shape

```
                    ┌──────────────────────────────────────────────┐
   RSS / Atom  ───▶ │  INGESTION   fetch · parse · extract · dedup  │
                    └───────────────────────┬──────────────────────┘
                                            │  ai_items (state machine)
                    ┌───────────────────────▼──────────────────────┐
   AI provider ◀──▶ │  PROCESSING  embed · cluster · rewrite(1 call)│
   (BYO key)        │              novelty-gate · facts-guard       │
                    └───────────────────────┬──────────────────────┘
                                            │  clusters · vectors
                    ┌───────────────────────▼──────────────────────┐
   Research ◀─────▶ │  ENTITY      extract · resolve · create · link│
   provider (opt)   │              hub pages · merge tool           │
                    └───────────────────────┬──────────────────────┘
                                            │  posts · entity CPTs · meta
                    ┌───────────────────────▼──────────────────────┐
   SEO plugin ◀───▶ │  PUBLISH     slug · meta(adapter) · schema    │
   IndexNow   ◀──── │              dateModified · IndexNow ping     │
                    └──────────────────────────────────────────────┘

      Every stage is a job on the custom claim-based queue (the crm-connect pattern).
```

The plugin is the runtime *and* the CMS — no external worker. A **custom claim-based DB
queue** (see [`CONVENTIONS.md`](CONVENTIONS.md) §5) drives every stage: atomic
`claim_token` claiming, stale-claim recovery, exponential backoff, dead-letter,
auto-pause, and a non-blocking "nudge" for low latency. The only outbound calls are: AI
provider, optional research/keyword providers, source-site fetches, and IndexNow.

---

## 2. The pipeline (item state machine)

Each row in `ai_items` advances through explicit, **idempotent, resumable** states —
**one stage per queue claim**, never the whole pipeline in a single request. A
monolithic "import + rewrite" call is the anti-pattern we are avoiding.

| State | Stage | Action | Cost |
|---|---|---|---|
| `fetched` | Ingestion | Item pulled from feed; GUID/permalink syntactic dedup | free |
| `extracted` | Ingestion | Full content obtained (feed or readability); boilerplate stripped structurally; min-length gate | free |
| `embedded` | Processing | Embedding computed → `ai_vectors` | cheap |
| `clustered` | Processing | 3-signal match → new cluster **or** matched to existing | free |
| ↳ `rewritten` | Processing (new cluster) | **Single structured AI call** → body, title, meta, slug, keyword, entities, facts; facts-guard runs | 1 paid call |
| ↳ `updated` | Processing (matched cluster) | Novelty gate; if new facts → append dated section, bump `dateModified` | cheap |
| `entity_linked` | Entity | Resolve/create entities, inject first-mention links, write relationships | optional paid |
| `published` | Publish | Slug, meta via adapter, JSON-LD, IndexNow ping | free |
| `failed` | — | Retried w/ exponential backoff; permanent failures → dead-letter + log | — |

**Idempotency:** every stage keys off `content_hash`; re-running a stage is safe.
**Re-runs:** a post can be re-rewritten (new prompt/model) without re-importing.
**Pause:** when the daily spend cap trips, the queue's transient auto-pause halts paid
stages (mirrors crm-connect's auto-pause); free stages continue.
**Batch API:** the rewrite stage may submit a batch job and set `next_attempt_at` to a
poll time — the claim loop polls until ready, then advances. No long blocking calls.

---

## 3. Data model

WordPress objects for anything user-facing; custom tables for engine state and the
vector index.

### Custom tables

```sql
-- Feed sources
CREATE TABLE {prefix}_ai_sources (
  id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  url           TEXT NOT NULL,
  title         VARCHAR(255),
  status        VARCHAR(20)  NOT NULL DEFAULT 'active',   -- active|paused|dead
  settings      LONGTEXT,                                 -- JSON: per-feed overrides
  health        LONGTEXT,                                 -- JSON: last_ok, errors, cadence
  last_checked  DATETIME,
  created_at    DATETIME NOT NULL
);

-- Raw imported items + pipeline state. Doubles as the work queue:
-- the queue columns (attempts, claim_token, next_attempt_at, last_error) let the
-- QueueWorker claim and advance items one stage at a time.
CREATE TABLE {prefix}_ai_items (
  id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  source_id       BIGINT UNSIGNED NOT NULL,
  guid            VARCHAR(255) NOT NULL,
  url             TEXT NOT NULL,
  raw_content     LONGTEXT,
  content_hash    CHAR(64) NOT NULL,
  state           VARCHAR(20) NOT NULL DEFAULT 'fetched', -- pipeline position
  cluster_id      BIGINT UNSIGNED,
  post_id         BIGINT UNSIGNED,                        -- canonical WP post
  flags           LONGTEXT,                               -- JSON: facts-guard, ambiguous-entity
  cost_tokens     INT DEFAULT 0,
  attempts        SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- queue: retry count
  claim_token     VARCHAR(36) DEFAULT NULL,               -- queue: in-flight claim
  next_attempt_at DATETIME DEFAULT NULL,                  -- queue: backoff gate
  last_error      TEXT DEFAULT NULL,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,
  UNIQUE KEY uq_guid (source_id, guid),
  KEY k_hash (content_hash),
  KEY k_state (state),
  KEY k_cluster (cluster_id),
  KEY k_claim (claim_token),
  KEY k_next (next_attempt_at)
);

-- Story/topic clusters → one canonical post each
CREATE TABLE {prefix}_ai_clusters (
  id               BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  primary_keyword  VARCHAR(255),                          -- LOCKED at creation
  canonical_post_id BIGINT UNSIGNED,
  primary_entities LONGTEXT,                              -- JSON: entity ids
  fact_set         LONGTEXT,                              -- JSON: facts already in the post (novelty gate)
  status           VARCHAR(20) NOT NULL DEFAULT 'live',   -- live|archived
  window_until     DATETIME,                              -- clustering time window edge
  created_at       DATETIME NOT NULL,
  updated_at       DATETIME
);

-- Vector index (brute-force cosine; fine at single-site scale)
CREATE TABLE {prefix}_ai_vectors (
  id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  owner_type  VARCHAR(10) NOT NULL,                       -- item|entity
  owner_id    BIGINT UNSIGNED NOT NULL,
  vector      LONGBLOB NOT NULL,                          -- packed float32
  dims        SMALLINT NOT NULL,
  KEY k_owner (owner_type, owner_id)
);

-- Per-stage cost + event log
CREATE TABLE {prefix}_ai_log (
  id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  item_id     BIGINT UNSIGNED,
  source_id   BIGINT UNSIGNED,
  stage       VARCHAR(20),
  level       VARCHAR(10),                                -- info|warn|error
  message     TEXT,
  tokens      INT DEFAULT 0,
  cost_usd    DECIMAL(10,5) DEFAULT 0,
  created_at  DATETIME NOT NULL,
  KEY k_item (item_id),
  KEY k_stage (stage),
  KEY k_created (created_at)
);
```

### WordPress objects

- **Canonical posts** → configurable target post type (default `post`). Carry meta:
  `_ai_cluster_id`, `_ai_source_ids[]`, `_ai_original` (pre-rewrite, for diff/legal),
  `_ai_prompt_version`, `_ai_facts[]`.
- **Entities** → CPTs (`company`, `person`, `product`, … user-defined). Carry meta:
  `_ai_canonical_name`, `_ai_aliases[]`, `_ai_sameas[]`, `_ai_citations[]`,
  `_ai_is_stub`. Embedding lives in `ai_vectors`.
- **Post ↔ entity relationship** → a private taxonomy (fast bidirectional queries:
  "posts mentioning entity X" and "entities in post Y") + ordered meta for first-mention.

### Why brute-force vectors are fine

At single-site moderate volume the live vector set is thousands, not millions.
Cosine over a few thousand packed float32 rows in PHP is sub-millisecond-per-compare
and runs inside a queue job, not a web request. External vector DBs
(Pinecone/Qdrant) are explicitly avoided — they'd break "activate and it just works"
and the OSS no-paid-dependency rule. A `pgvector`/scale path can be added behind the
same `VectorStore` interface later.

---

## 4. Subsystems

### 4.1 Ingestion

- Feed parsing via WordPress core `SimplePie` (already bundled) for RSS/Atom; JSON
  Feed handled separately.
- **Full-content extraction:** `ContentExtractor` — feed-content-if-full-enough, else
  fetch + GPL-compatible readability port (no JS). `HttpFetcher` enforces `robots.txt`,
  per-domain rate limit, honest UA, response caching.
- Syntactic dedup by `(source_id, guid)` unique key + `content_hash`.

### 4.2 Processing

- `Embedder` (provider adapter) → `ai_vectors`.
- `Clusterer` — 3-signal: cosine ≥ threshold **∧** shared primary entity **∧** within
  `window_until`. Borderline (near threshold) → log, treat as new cluster.
- `Rewriter` — the **single structured call**. Strict JSON schema output. Prompt is
  template-driven (per-feed overridable), enforces faithful-rewrite + strip-promo +
  keyword-natural rules.
- `FactsGuard` — extract numbers/dates/proper-nouns from input & output; any in output
  not in input → flag on the item (publishes, but visible in the dashboard).
- `NoveltyGate` — diff new item's facts against `cluster.fact_set`; no new facts →
  suppress; new facts → append + merge into `fact_set`.

### 4.3 Entity engine

- `EntityExtractor` — comes from the rewrite call's `entities[]` (no separate NER call).
- `EntityResolver` — normalize → exact/alias → type-constrained fuzzy+embedding →
  confidence band (link / create / skip-and-log). Batch-dedup guard via a per-run
  pending-creation set.
- `EntityResearcher` — pluggable. Default: stub from in-article context (zero deps).
  With a research provider: cited external facts + `sameAs`.
- `EntityLinker` — first-mention contextual link, capped per post, writes the
  relationship taxonomy.
- `HubRenderer` — entity CPT template: description + cited facts + `sameAs` +
  auto-growing related-posts list + Organization/Person/Product schema.
- `EntityMerge` — admin tool: merge B→A, repoint links/aliases/relationships/schema.

### 4.4 Publish / SEO

- `SlugGenerator` — from locked primary keyword.
- `SeoAdapter` — strategy per active plugin (Yoast / Rank Math / SEOPress / native
  fallback): writes title + meta + focus keyword; **suppresses the plugin's schema** on
  our objects.
- `SchemaGraph` — builds the native JSON-LD `@graph`.
- `IndexNow` — pings on publish and on novelty update; bumps `dateModified`.

### 4.5 Cost & observability

- `CostMeter` — per call/stage tokens + USD → `ai_log`; aggregates per feed/day.
- `SpendCap` — hard daily ceiling; trips → paid stages pause, admin notice.
- Dashboard: pipeline funnel, per-feed costs, facts-guard flags, ambiguous-entity
  queue, original-vs-rewrite diff, feed-health.

---

## 5. Configuration: the delegation-rule schema

The entity engine is **data-driven**, not hardcoded. A delegation rule maps an entity
type to a CPT and tells the engine how to match, create, research, and link.

```jsonc
{
  "entity_type": "company",          // logical type the extractor emits
  "target_cpt": "company",           // WP post type for the hub
  "enabled": true,
  "match": {
    "embedding_threshold": 0.86,     // strong-match floor
    "novel_threshold": 0.62,         // below this vs ALL existing → create new
    "use_aliases": true,
    "type_constrained": true         // only match within target_cpt
  },
  "field_map": {                     // structured-call output → CPT fields/meta
    "name":        "post_title",
    "description": "post_content",
    "industry":    "meta:_industry",
    "founded":     "meta:_founded",
    "website":     "meta:_website"
  },
  "research": {
    "provider": "default",           // "default" = in-article only; or a provider id
    "require_citations": true,
    "collect_sameas": true,
    "max_lookups": 3                 // bounds cost when a provider is connected
  },
  "linking": {
    "first_mention_only": true,
    "max_links_per_post": 5,
    "anchor": "canonical_name"
  },
  "schema_type": "Organization"      // Organization | Person | Product | ...
}
```

Rules live in `ai_delegation_rules` (or an options blob) and are editable in the admin.
Adding support for a new post type = adding a rule. No code.

---

## 6. Extension API (the freemium seams)

Free core must expose clean hooks so premium add-ons (and the community) extend without
forking. Retrofitting these later is painful — they go in from phase 1.

**Provider interfaces** (premium add-ons register implementations):

```php
interface Ai_Provider {        // rewrite + embed behind one swappable adapter
    public function structured( string $prompt, array $schema, array $opts ): array;
    public function embed( string $text ): array;
}
interface Research_Provider {  // entity enrichment; absent by default
    public function research( string $name, string $type, int $max ): array; // facts + sameAs
}
interface Keyword_Provider {   // volume/difficulty; absent by default
    public function metrics( array $keywords ): array;
}
interface Seo_Adapter {        // one per SEO plugin
    public function write_meta( int $post_id, array $seo ): void;
    public function suppress_schema( int $post_id ): void;
}
```

**Key filters/actions** (illustrative):

- `aggregate_it_register_ai_provider`, `…_research_provider`, `…_keyword_provider`
- `aggregate_it_rewrite_prompt` — mutate the structured-call prompt/schema
- `aggregate_it_cluster_match` — override/augment the 3-signal decision
- `aggregate_it_entity_resolve` — inject custom resolution
- `aggregate_it_before_publish` / `…_after_update` — hook the lifecycle
- `aggregate_it_schema_graph` — mutate the JSON-LD before output

Premium add-ons (keyword-data provider, research provider, headless extraction,
multilingual, strategic-mode packs) are pure consumers of these seams.

---

## 7. Failure & safety posture

- **Scaled Content Abuse:** mitigated structurally — living posts + novelty gate +
  entity value + citations = genuine added value, not thin rewrites.
- **Hallucination:** faithful-rewrite prompt + deterministic facts-guard; strict cited
  entity stubs; entities degrade to in-article-only without a research provider.
- **Cost runaway:** hard daily spend cap pauses paid stages; single-call design + batch
  + caching minimize spend.
- **Scraping reputation:** robots.txt + rate limiting + honest UA + caching by default.
- **Image copyright:** import configurable, default conservative, alt text generated.
- **Bad merges/links:** conservative clustering + conservative resolution + the entity
  merge tool as the human cleanup valve.
