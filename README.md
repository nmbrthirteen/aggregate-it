# Aggregate It

**An SEO content engine that uses RSS as raw material.**

Aggregate It imports RSS/Atom feeds, faithfully rewrites the content with AI (no
fact changes — news stays news), deduplicates stories semantically, and maintains
*living* topic pages that stay up to date as new sources arrive. It automatically
extracts entities (companies, people, products…), resolves them against your
custom post types, creates researched hub pages, and builds an internal-link graph
that compounds into topical authority.

It is **not** "an aggregator that happens to have AI." It is an SEO machine whose
fuel happens to be RSS.

- **License:** GPL-2.0-or-later
- **Distribution:** GitHub releases + Plugin Update Checker self-update (matches `crm-connect`); WordPress.org listing optional later. Freemium add-ons via provider seams.
- **Slug:** `aggregate-it`
- **Conventions:** follows the `crm-connect` house style — see [`docs/CONVENTIONS.md`](docs/CONVENTIONS.md)
- **Status:** planning / pre-implementation

## What makes it different

| Capability | Why it matters for SEO |
|---|---|
| **Living canonical posts** — one authoritative URL per story, updated over time | Concentrates link equity + sends `dateModified` freshness signals instead of splitting equity across duplicates |
| **Faithful rewrite** — reword + strip promo junk, never alter facts | Original prose without the hallucination/legal risk; a documented selling point |
| **Novelty gate** — only update when genuinely new facts arrive | Avoids the thin-rewrite pattern that triggers Google's Scaled Content Abuse policy |
| **Entity hub engine** — auto-match/create CPT entities + contextual backlinks | Builds hub-and-spoke topical authority + machine-readable `about`/`sameAs` schema |
| **Native-rich schema** — Article/NewsArticle + entity + citation graph | The durable moat thin-rewrite competitors can't replicate |

## Documentation

- [`docs/DECISIONS.md`](docs/DECISIONS.md) — the resolved design decisions and their rationale
- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — data model, pipeline, subsystems, config schema, extension API
- [`docs/CONVENTIONS.md`](docs/CONVENTIONS.md) — engineering house style, ported from `crm-connect`
- [`docs/EXTENDING.md`](docs/EXTENDING.md) — provider/hook seams for premium add-ons
- [`docs/PLAN.md`](docs/PLAN.md) — phased implementation plan (phases 0–4 built)

## Operating profile (chosen constraints)

- **Scale:** single site, moderate volume — Action Scheduler, no external worker
- **Editorial:** fully automatic publish; manual edits after the fact
- **AI:** cheapest-viable models behind a swappable, bring-your-own-key adapter
- **Content stance:** full faithful rewrite, facts preserved, promo stripped
- **Goal, above all else:** SEO
