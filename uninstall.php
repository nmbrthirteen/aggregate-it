<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Database/Schema.php';

AggregateIt\Database\Schema::uninstall();
