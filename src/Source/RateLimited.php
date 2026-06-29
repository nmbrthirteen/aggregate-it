<?php

namespace AggregateIt\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a fetch is skipped purely to honor the per-host rate limit. Distinguishable
 * from a genuine failure so the queue can defer (retry without consuming an attempt)
 * instead of giving up — otherwise same-host article bursts lose their og:image.
 */
final class RateLimited extends \RuntimeException {}
