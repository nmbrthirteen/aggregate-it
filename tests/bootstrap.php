<?php
/**
 * Minimal bootstrap for pure-unit tests that don't need the WordPress test harness.
 * Defines the ABSPATH guard so plugin files can be required in isolation.
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../src/Support/Vector.php';
