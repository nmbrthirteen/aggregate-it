# Design Decisions

Resolved during the design interview. Each entry: the decision, the alternatives
considered, and why. These are the load-bearing choices; everything in
`ARCHITECTURE.md` and `PLAN.md` follows from them.

## Operating constraints (givens)

- **Scale / runtime:** single WordPress site, moderate volume (dozens of feeds,
  hundreds of items/day). → A **custom claim-based DB queue** (the `crm-connect`
  pattern); no external worker service. *(Supersedes an earlier Action Scheduler lean —
  see D14.)*
- **Editorial model:** fully automatic publish. The owner edits anything off after
  the fact. → No human review queue, but rich observability so problems are findable.
- **AI / budget:** cheapest viable. → Small/cheap default models behind a
  provider-agnostic, bring-your-own-key adapter; batch + caching everywhere.
- **Content stance:** full **faithful** rewrite. News stays news — no fact changes.
  Strip promotional/boilerplate junk from the source.
- **Overriding goal:** SEO. Every decision below is filtered through "does this rank?"

## D1 — Content model: living canonical posts (B), built via hybrid path

One authoritative post per *story/topic*, updated over time — not one post per feed
item. Built incrementally: every item still becomes a post in phase 1, but each
carries a `topic_cluster_id` from day one, so the "update existing instead of
duplicate" behavior layers in later with **no migration**.

- *Rejected:* item-per-post (classic aggregator) — splits link equity + freshness
  across duplicates; the opposite of what we want for SEO.
- *Why:* one living URL concentrates equity and emits `dateModified` freshness.

## D2 — Updates: append timeline-style + novelty gate

When new info arrives for an existing story, append a dated section ("Update —
June 25: …"). Never rewrite the whole post. **And** only update when the new item
carries genuinely new facts (novelty gate) — restated wire copy is suppressed.

- *Rejected:* wholesale re-synthesis (expensive, churns SEO, mangle risk on a cheap
  model); structured section-merge (highest complexity + mangle risk).
- *Why:* cheapest, lowest blast radius under full-auto, preserves facts and URL
  stability; novelty gate is what keeps it from becoming thin-content spam.

## D3 — Clustering: 3-signal conservative match

Two items are "the same story" only if **all three** hold: semantic similarity
(embedding cosine over a configurable threshold) **and** shared primary entity
**and** within a rolling time window (default ~7 days, per-feed configurable).

- *Rejected:* similarity-only — merges different stories about the same subject.
- *Why:* under full-auto a **false merge corrupts a live post**; a false split just
  leaves a stray duplicate. Bias hard toward not merging. Borderline cases are
  logged, not silently merged.

## D4 — URLs & post type: flat keyword slugs, configurable target

Canonical posts go to a **configurable target post type (default `post`)**. URLs are
**flat, keyword-rich slugs with no dates** (`/ai-generated-keyword-slug/`), generated
by AI from the post's target keyword (not the source headline). Topical structure
comes from the entity internal-link graph, not deep URL nesting.

- *Why:* dates in permalinks make *living* (updated) posts look stale and dilute the
  keyword. Google News/Discover targeting deferred (compatible, but extra
  requirements not taken on now).

## D5 — SEO metadata: hybrid adapter

Detect the active SEO plugin (Yoast / Rank Math / SEOPress) and write the
AI-generated **target keyword, SEO title, meta description** into *its* fields. If
none is installed, a **minimal native fallback** outputs title + meta only.

- *Rejected:* fully native SEO module — duplicate/competing meta tags when the user
  also runs Yoast (they will).
- *Principle:* an SEO engine generates the *inputs*; let the user's chosen plugin
  render the `<head>`.

## D6 — Structured data: native-rich JSON-LD

Schema is the one place we go native (plugins can't build it from our data). One
clean JSON-LD `@graph`: `Article`/`NewsArticle` (per-source toggle), always
`datePublished` **and** `dateModified`, plus `author`, `publisher`, `image`,
`mainEntityOfPage`, **`about`/`mentions`** → entity hubs, **`citation`** → sources.
Entity hubs emit `Organization`/`Person`/`Product` + **`sameAs`**. The SEO-plugin
adapter **suppresses the plugin's competing Article schema** on our objects.

- *Why:* the entity + citation graph is the durable SEO moat and the "added value"
  proof against Scaled Content Abuse.

## D7 — Keyword targeting: 3 layers, auto-infer default

1. **Default — AI-inferred** primary keyword per cluster (volume mode: publish all).
2. **Optional — keyword strategy list** (global/per-feed) with a **strategic-mode
   toggle** (only publish clusters that match a target keyword).
3. **Optional/premium — pluggable keyword-data provider** (DataForSEO/Semrush/Ahrefs,
   BYO key) for volume + difficulty, to prioritize/skip clusters.

Guardrails: **keyword locked at cluster creation** (never recomputed — appends must
not churn the slug); rewrite prompt treats the keyword as "include naturally, never
stuff."

## D8 — Full-content extraction: hybrid + polite

Use feed content when it's full enough (length heuristic); otherwise fetch the source
URL and run **server-side readability extraction (no JS)**. **Polite by default:**
respect `robots.txt`, honest User-Agent, per-domain rate limiting, caching. On
extraction failure: fall back to feed content **only if it clears a minimum length
bar**, else skip + log. **Never auto-publish a thin stub.**

- *Why:* truncated feeds → thin posts → ranking failure + spam-policy flag. Politeness
  protects the user's server IP and the plugin's reputation. Headless rendering for
  JS-heavy/paywalled sites is a deferred premium enhancement.

## D9 — Entity engine: conservative resolution + graceful degradation

Per extracted mention, **type-constrained** matching within the target CPT:
- **Strong match** (exact/alias or high score) → link.
- **Clearly novel** (low similarity to every existing entity of that type) → create stub.
- **Ambiguous middle** → **skip the link + log** "needs resolution." Don't guess.
- **Batch-dedup guard:** the same brand-new entity mentioned twice in one run is
  created once.

Creation degrades gracefully: a stub is built from **in-article context** with **zero
external dependency**; rich research (cited external facts + `sameAs`) is a **pluggable
BYO-key provider** (premium). Stubs are strict — only cited fields, no hallucinated
fills. Ship an **entity merge tool** (repoints links/aliases/schema) as the cleanup valve.

- *Why:* a clean sparse graph outranks a dense corrupted one. Duplicate entities are
  recoverable (merge); wrong links corrupt published posts + schema.

## D10 — Entity linking & hubs

Contextual in-body link on the **first mention only**, natural-name anchor, **capped
links per post**. Bidirectional relationship (postmeta/taxonomy) powers schema +
hub. **Hubs are pillar pages:** AI description + cited facts + `sameAs` + an
**auto-growing related-posts list** — every new post about an entity strengthens its
hub (the topical-authority + freshness flywheel).

## D11 — Freshness & indexing

Sitemaps **delegated to the SEO plugin** (ensure entity CPTs included). **IndexNow**
built in (free) — ping on publish and on a real novelty update. Freshness loop: novelty
append → bump `dateModified` → update sitemap `lastmod` → fire IndexNow. No faked
instant-index API for normal content.

## D12 — Cost strategy

**One structured AI call per item** returns `{rewritten_body, seo_title,
meta_description, slug, primary_keyword, entities[], facts[]}` — collapses
rewrite+SEO+NER+fact-extraction into one round trip. Embeddings are a separate cheap
call. **Batch API** for non-urgent work (~50% off). **Content-hash + prompt caching.**
**Per-feed/per-stage cost tracking** with a **hard daily spend cap** that pauses the
queue. **Deterministic facts-guard** (numbers/dates/proper-noun subset check — no
extra AI call) flags invented facts.

## D13 — Images & EEAT

Featured image **downloaded into the media library** (never hotlinked); import is
**configurable** (off / import / import-if-licensed), default conservative due to
copyright. **AI-generated alt text** (image SEO + a11y). **Author/EEAT:** configurable
real author with bio + `author` schema — **no fabricated human personas**. Optional
"AI-assisted" disclosure toggle.

## D14 — Engineering conventions: match `crm-connect`

The author's existing `crm-connect` plugin sets the house style; Aggregate It mirrors
it for consistency. Full detail in [`CONVENTIONS.md`](CONVENTIONS.md). The two choices
that *changed* prior decisions:

- **Queue: custom claim-based DB queue, not Action Scheduler.** crm-connect runs a
  proven dependency-free queue — atomic `claim_token` claiming, stale-claim recovery,
  exponential backoff, dead-letter, transient auto-pause, and a non-blocking
  "nudge" (admin-ajax self-POST) that makes a 1-minute cron feel real-time. This
  answers the WP-Cron reliability concern *without* Action Scheduler, and keeps one
  mental model across both plugins. Adapted for AI: advance one stage per claim, small
  batch + per-run time budget, and Batch-API submit/poll fits the `next_attempt_at`
  model perfectly.
- **Distribution: GitHub releases + Plugin Update Checker, not WordPress.org (for v1).**
  Matches crm-connect's `bin/release.sh` + tag-triggered GitHub Action. A wp.org listing
  stays optional later (good for the plugin's *own* SEO discoverability) but would
  require conditionally disabling PUC and disclosing external-API/BYO-key in `readme.txt`.

Also adopted wholesale: PSR-4 `AggregateIt\` + spl fallback bootstrap, singleton
`Plugin` with manual DI + `register()` services, `Schema` with state constants +
`dbDelta` + db_version, single-option `Settings` with typed accessors, `Support\Crypto`
for encrypted API keys, `EventLog` ring buffer for admin notices, provider
factory-with-filter (the freemium seam), and the Admin menu + REST + view-template
pattern.

## Deferred

- WordPress.org listing (dual build target: disable PUC, disclose BYO-key)
- Google News / Discover targeting (news sitemap, publisher setup, stricter schema)
- Multilingual translation (natural premium sibling)
- Headless-browser extraction for JS-rendered / paywalled sources
