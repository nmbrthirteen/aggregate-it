<?php

namespace AggregateIt\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts stored API keys with libsodium's authenticated secretbox, keyed from the site's
 * AUTH_KEY and SECURE_AUTH_SALT. New values carry a "v2:" prefix. Values written by older
 * releases (unauthenticated AES-256-CBC, base64 only) still decrypt and are re-encrypted
 * the next time settings are saved.
 */
final class Crypto {

	private const PREFIX        = 'v2:';
	private const LEGACY_METHOD = 'aes-256-cbc';

	/** True when this site can encrypt: sodium is loaded and a non-empty salt exists. */
	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && self::secret() !== '';
	}

	/** True when $payload was written by the current scheme and needs no re-encryption. */
	public static function is_current( string $payload ): bool {
		return strncmp( $payload, self::PREFIX, strlen( self::PREFIX ) ) === 0;
	}

	/** @throws \RuntimeException when sodium or a site salt is missing; keys are never stored in plaintext. */
	public static function encrypt( string $plaintext ): string {
		if ( $plaintext === '' ) {
			return '';
		}
		if ( ! self::available() ) {
			throw new \RuntimeException( 'Cannot store the API key: this site has no libsodium or no AUTH_KEY/SECURE_AUTH_SALT to encrypt it with.' );
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $box );
	}

	public static function decrypt( string $payload ): string {
		if ( self::is_current( $payload ) ) {
			return self::decrypt_secretbox( substr( $payload, strlen( self::PREFIX ) ) );
		}
		return self::decrypt_legacy( $payload );
	}

	private static function decrypt_secretbox( string $encoded ): string {
		if ( ! self::available() ) {
			return '';
		}
		$raw = base64_decode( $encoded, true );
		if ( $raw === false || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce     = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plaintext = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, self::key() );
		return $plaintext === false ? '' : $plaintext;
	}

	private static function decrypt_legacy( string $payload ): string {
		$raw = base64_decode( $payload, true );
		if ( $raw === false ) {
			return '';
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return $raw;
		}
		$ivlen = openssl_cipher_iv_length( self::LEGACY_METHOD );
		if ( strlen( $raw ) <= $ivlen ) {
			return '';
		}
		$iv     = substr( $raw, 0, $ivlen );
		$cipher = substr( $raw, $ivlen );

		foreach ( self::legacy_keys() as $key ) {
			$plaintext = openssl_decrypt( $cipher, self::LEGACY_METHOD, $key, OPENSSL_RAW_DATA, $iv );
			if ( $plaintext !== false ) {
				return $plaintext;
			}
		}
		return '';
	}

	/** Site secret; empty when the install has no usable salt. */
	private static function secret(): string {
		$auth = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
		$salt = defined( 'SECURE_AUTH_SALT' ) ? (string) SECURE_AUTH_SALT : '';
		if ( $auth !== '' && $salt !== '' ) {
			return $auth . $salt;
		}
		return function_exists( 'wp_salt' ) ? (string) wp_salt( 'secure_auth' ) : '';
	}

	private static function key(): string {
		return hash( 'sha256', 'aggregate-it/secretbox/' . self::secret(), true );
	}

	/** @return string[] keys earlier releases may have used, including their weak fallbacks */
	private static function legacy_keys(): array {
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY !== '' ) {
			$salt = AUTH_KEY;
		} elseif ( function_exists( 'wp_salt' ) ) {
			$salt = wp_salt( 'secure_auth' );
		} else {
			$salt = __DIR__;
		}
		return [
			hash( 'sha256', $salt, true ),
			hash( 'sha256', __DIR__, true ),
			hash( 'sha256', '', true ),
		];
	}
}
