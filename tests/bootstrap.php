<?php
/**
 * Test bootstrap. The plugin's logic classes call a handful of WordPress functions;
 * we stub just those so the pure-unit suite runs with no WordPress install. Tests
 * drive behaviour through $GLOBALS registries (options, HTTP responses, filters).
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) . '/' );
defined( 'AGGREGATE_IT_VERSION' ) || define( 'AGGREGATE_IT_VERSION', '0.0.0-test' );
defined( 'AGGREGATE_IT_FILE' ) || define( 'AGGREGATE_IT_FILE', dirname( __DIR__ ) . '/aggregate-it.php' );
defined( 'AGGREGATE_IT_PATH' ) || define( 'AGGREGATE_IT_PATH', dirname( __DIR__ ) . '/' );
defined( 'AGGREGATE_IT_URL' ) || define( 'AGGREGATE_IT_URL', 'http://example.test/wp-content/plugins/aggregate-it/' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'WPINC' ) || define( 'WPINC', 'wp-includes' );

$GLOBALS['__options']      = [];
$GLOBALS['__filters']      = [];   // tag => callable
$GLOBALS['__http']         = null; // ['code'=>int,'body'=>string] or WP_Error
$GLOBALS['__hooks']        = [ 'action' => 0, 'filter' => 0 ];
$GLOBALS['__posts_meta']   = [];   // post_id => [key => value]
$GLOBALS['__norm_posts']   = [];   // for entity repo: id => norm name

// Autoload plugin classes (composer if present, else a simple PSR-4 fallback).
if ( is_readable( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	require dirname( __DIR__ ) . '/vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( $class ) {
			foreach ( [ 'AggregateIt\\Tests\\' => __DIR__ . '/', 'AggregateIt\\' => dirname( __DIR__ ) . '/src/' ] as $prefix => $dir ) {
				if ( strncmp( $class, $prefix, strlen( $prefix ) ) === 0 ) {
					$file = $dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
					if ( is_readable( $file ) ) {
						require $file;
					}
					return;
				}
			}
		}
	);
}

// --- WordPress function stubs ------------------------------------------------

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		if ( $key === 'aggregate_it_db_version' ) {
			return AGGREGATE_IT_VERSION; // keep Schema::maybe_upgrade a no-op
		}
		return $GLOBALS['__options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = true ) {
		$GLOBALS['__options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		unset( $GLOBALS['__options'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		if ( isset( $GLOBALS['__filters'][ $tag ] ) ) {
			return ( $GLOBALS['__filters'][ $tag ] )( $value, ...$args );
		}
		return $value;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $h, $c = null, $p = 10, $a = 1 ) {
		$GLOBALS['__hooks']['action']++;
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $h, $c = null, $p = 10, $a = 1 ) {
		$GLOBALS['__hooks']['filter']++;
		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $h, ...$a ) {}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s, $break = false ) {
		return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) );
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $s ) {
		return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $s ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) );
	}
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $len = 12, $special = true ) {
		return substr( str_repeat( 'a1b2c3d4', 8 ), 0, $len );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $s ) {
		return (string) $s;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v ) {
		return json_encode( $v );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) {
		return $s;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $d = null ) {
		return $s;
	}
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults ) {
		return array_merge( $defaults, (array) $args );
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return true;
	}
}
if ( ! function_exists( 'load_plugin_textdomain' ) ) {
	function load_plugin_textdomain( ...$a ) {
		return true;
	}
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $f ) {
		return basename( (string) $f );
	}
}
if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $t ) {
		return false;
	}
}
if ( ! function_exists( 'register_post_type' ) ) {
	function register_post_type( $t, $a ) {
		return true;
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $h ) {
		return false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $t, $r, $h ) {
		return true;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $c ) {
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) {
		return false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $t ) {
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) {
		return true;
	}
}

// HTTP
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $message = 'error' ) {}
		public function get_error_message() {
			return $this->message;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $x ) {
		return $x instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = [] ) {
		return $GLOBALS['__http'] ?? [ 'code' => 200, 'body' => '' ];
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = [] ) {
		return $GLOBALS['__http'] ?? [ 'code' => 200, 'body' => '' ];
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) {
		return is_array( $r ) ? ( $r['code'] ?? 200 ) : 200;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) {
		return is_array( $r ) ? ( $r['body'] ?? '' ) : '';
	}
}

// Posts / meta (for entity repo + resolver tests)
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = true ) {
		if ( $key === '_ai_norm_name' ) {
			return $GLOBALS['__norm_posts'][ $id ] ?? '';
		}
		return $GLOBALS['__posts_meta'][ $id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['__posts_meta'][ $id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = [] ) {
		return array_keys( $GLOBALS['__norm_posts'] );
	}
}
if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public array $posts = [];
		public function __construct( $args = [] ) {
			$this->posts = [];
		}
	}
}
