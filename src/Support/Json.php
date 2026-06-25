<?php

namespace AggregateIt\Support;

defined( 'ABSPATH' ) || exit;

final class Json {

	public static function encode( $value ): string {
		$json = wp_json_encode( $value );
		return $json === false ? '' : $json;
	}

	/** @return mixed */
	public static function decode( ?string $json, $default = [] ) {
		if ( $json === null || $json === '' ) {
			return $default;
		}
		$value = json_decode( $json, true );
		return $value === null && json_last_error() !== JSON_ERROR_NONE ? $default : $value;
	}
}
