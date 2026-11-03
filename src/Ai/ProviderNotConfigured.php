<?php

namespace AggregateIt\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a paid step cannot run because no real AI provider is usable. The queue
 * defers the item without consuming an attempt, so it resumes once a provider is set up.
 */
final class ProviderNotConfigured extends \RuntimeException {}
