<?php

namespace AggregateIt\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Marker for stages that spend money (AI calls). When the daily spend cap is reached the
 * worker defers these instead of running them — free stages keep flowing.
 */
interface PaidStage extends Stage {
}
