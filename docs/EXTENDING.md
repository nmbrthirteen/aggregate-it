# Extending Aggregate It

Aggregate It's core is free and provider-agnostic. Every paid/external capability is a
seam you (or a premium add-on) plug into via a filter — no core fork required. This is
also the freemium boundary: the free plugin ships the engine; add-ons ship providers.

## AI provider (rewrite + embeddings)

The one required capability. Four providers ship in core — pick one in Settings, no
filter needed:

- **`MockProvider`** — deterministic, free, the default. Good for testing the pipeline.
- **`GeminiProvider`** — cheapest live option. `generateContent` JSON mode + native
  `text-embedding-004`. Default model `gemini-2.0-flash-lite`.
- **`OpenAiProvider`** — Chat Completions JSON mode + native `text-embedding-3-small`.
  Default model `gpt-4o-mini`.
- **`AnthropicProvider`** — Claude Messages API (structured outputs). Default Haiku 4.5.
  Anthropic has no embeddings endpoint, so it uses Voyage AI when a Voyage key is set,
  else a local lexical fallback.

All call their HTTP APIs via the WP HTTP layer (no SDK bundling). The model is a free
Settings field — blank uses the provider's cheapest default.

To register a *different* provider (a local model, Azure, etc.):

```php
add_filter( 'aggregate_it_ai_provider', function ( $provider, string $key, $settings ) {
    if ( $key !== 'anthropic' ) {
        return $provider;
    }
    return new My_Anthropic_Provider( $settings->api_key() );
}, 10, 3 );
```

Implement `AggregateIt\Ai\AiProvider`:

```php
interface AiProvider {
    public function key(): string;
    // returns ['result' => <object matching $schema>, 'tokens' => int, 'cost_usd' => float]
    public function structured( string $prompt, array $schema, array $opts = [] ): array;
    // returns ['vector' => float[], 'tokens' => int, 'cost_usd' => float]
    public function embed( string $text ): array;
}
```

The structured schema is the one from `Ai\Rewriter::schema()` —
`rewritten_body, seo_title, meta_description, slug, primary_keyword, entities[], facts[]`.
Report real `tokens`/`cost_usd` so the spend cap and dashboard work. Use the
Anthropic Batch API for the ~50% discount where latency allows.

## Research provider (entity enrichment, optional)

Absent by default — entities are built from in-article context only. Register one to add
cited external facts + `sameAs`:

```php
add_filter( 'aggregate_it_research_provider', fn ( $p, $settings ) => new My_Research( $settings ), 10, 2 );
```

Implement `AggregateIt\Research\ResearchProvider::research( $name, $type, $max )` →
`['facts' => [['value','source'], …], 'sameas' => string[], 'tokens', 'cost_usd']`.

## Keyword data provider (volume/difficulty, optional/premium)

```php
add_filter( 'aggregate_it_keyword_provider', fn ( $p, $settings ) => new My_DataForSEO( $settings ), 10, 2 );
```

Implement `AggregateIt\Keyword\KeywordProvider::metrics( array $keywords )` →
`['keyword' => ['volume' => int, 'difficulty' => int], …]`.

## Content / prompt / schema hooks

- `aggregate_it_rewrite_prompt` ( $prompt, $title, $content, $target_keyword ) — mutate the rewrite instruction.
- `aggregate_it_rewrite_schema` ( $schema ) — extend the structured-output contract.
- `aggregate_it_extract_html` ( null, $html ) — return a string to override the built-in readability heuristic with a real library.
- `aggregate_it_schema_graph` ( $graph, $post_id ) — mutate the JSON-LD before output.
- `aggregate_it_entity_post_types` ( $types ) — declare extra entity CPTs for the dashboard count.

## Lifecycle

- `aggregate_it_publish_ping` ( $post_id ) — fired when a post is published or updated (IndexNow listens here).
- `aggregate_it_spend_cap_reached` ( $cap ) — daily spend ceiling hit.
- `aggregate_it_dead_letter` ( $item, $exception ) — an item failed permanently.
- `aggregate_it_booted` ( $plugin ) — container ready.

## Notes for premium add-ons

- Ship as a separate plugin that registers the filters above on `plugins_loaded`/`init`.
- The free core never hard-depends on any add-on — every seam degrades gracefully.
- Keep GPL-2.0-or-later for WordPress.org compatibility.
