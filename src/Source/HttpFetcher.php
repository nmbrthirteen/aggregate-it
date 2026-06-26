<?php

namespace AggregateIt\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Polite-by-default HTTP fetching: honors robots.txt, rate-limits per domain, sends an
 * honest User-Agent, and caches responses. A rude scraper gets the host's server IP
 * blocked, so these defaults are reputation-critical for an OSS plugin.
 */
final class HttpFetcher {

	// Many publisher sites block non-browser user-agents (returning a stripped page or
	// a 403), which loses og:image and article content. Present as a real browser so we
	// get the same HTML a visitor would; we still respect robots.txt and rate-limit.
	private const UA            = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
	private const MIN_GAP       = 3;      // seconds between requests to the same host
	private const CACHE_TTL     = 3600;   // response cache
	private const ROBOTS_TTL    = 43200;  // robots.txt cache

	public function fetch( string $url ): ?string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return null;
		}

		$this->block_private( $host );

		$cache_key = 'aggregate_it_http_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return is_string( $cached ) ? $cached : null;
		}

		if ( ! $this->robots_allow( $url, $host ) ) {
			throw new \RuntimeException( 'Blocked by robots.txt: ' . esc_url_raw( $url ) );
		}

		$this->throttle( $host );

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => 15,
				'redirection' => 3,
				'user-agent'  => self::UA,
				'headers'     => [
					'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'en-US,en;q=0.9',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Fetch failed: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			set_transient( $cache_key, '', self::CACHE_TTL );
			throw new \RuntimeException( 'HTTP ' . $code . ' for ' . esc_url_raw( $url ) );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		set_transient( $cache_key, $body, self::CACHE_TTL );
		return $body;
	}

	/**
	 * SSRF guard: refuse hosts that resolve to private/reserved IP ranges, so a malicious
	 * or compromised feed can't make the site fetch internal services (metadata endpoints,
	 * localhost admin panels, etc.). Override with the aggregate_it_allow_private_hosts filter.
	 */
	private function block_private( string $host ): void {
		if ( apply_filters( 'aggregate_it_allow_private_hosts', false, $host ) ) {
			return;
		}

		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );

		if ( filter_var( $ip, FILTER_VALIDATE_IP ) && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			throw new \RuntimeException( 'Refusing to fetch private/reserved host: ' . $host );
		}
	}

	private function throttle( string $host ): void {
		$key = 'aggregate_it_rl_' . md5( $host );
		if ( get_transient( $key ) !== false ) {
			throw new \RuntimeException( 'Rate-limited for host ' . $host . '; will retry.' );
		}
		set_transient( $key, 1, self::MIN_GAP );
	}

	private function robots_allow( string $url, string $host ): bool {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME ) ?: 'https';
		$key    = 'aggregate_it_robots_' . md5( $host );
		$rules  = get_transient( $key );

		if ( $rules === false ) {
			$rules    = [];
			$response = wp_remote_get(
				$scheme . '://' . $host . '/robots.txt',
				[ 'timeout' => 8, 'user-agent' => self::UA ]
			);
			if ( ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) < 400 ) {
				$rules = $this->parse_disallow( (string) wp_remote_retrieve_body( $response ) );
			}
			set_transient( $key, $rules, self::ROBOTS_TTL );
		}

		$path = wp_parse_url( $url, PHP_URL_PATH ) ?: '/';
		foreach ( (array) $rules as $disallow ) {
			if ( $disallow !== '' && strpos( $path, $disallow ) === 0 ) {
				return false;
			}
		}
		return true;
	}

	/** @return string[] disallowed path prefixes for `*` and our UA */
	private function parse_disallow( string $robots ): array {
		$lines     = preg_split( '/\r\n|\r|\n/', $robots ) ?: [];
		$applies   = false;
		$disallows = [];

		foreach ( $lines as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( $line === '' ) {
				continue;
			}
			if ( preg_match( '/^User-agent:\s*(.+)$/i', $line, $m ) ) {
				$agent   = strtolower( trim( $m[1] ) );
				$applies = ( $agent === '*' || strpos( 'aggregateit', $agent ) !== false );
				continue;
			}
			if ( $applies && preg_match( '/^Disallow:\s*(.*)$/i', $line, $m ) ) {
				$path = trim( $m[1] );
				if ( $path !== '' ) {
					$disallows[] = $path;
				}
			}
		}
		return array_values( array_unique( $disallows ) );
	}
}
