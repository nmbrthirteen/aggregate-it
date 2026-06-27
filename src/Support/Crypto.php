<?php

namespace AggregateIt\Support;

defined( 'ABSPATH' ) || exit;

final class Crypto {

	private const METHOD = 'aes-256-cbc';

	public static function encrypt( string $plaintext ): string {
		if ( $plaintext === '' || ! function_exists( 'openssl_encrypt' ) ) {
			return base64_encode( $plaintext );
		}
		$iv     = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::METHOD ) );
		$cipher = openssl_encrypt( $plaintext, self::METHOD, self::key(), OPENSSL_RAW_DATA, $iv );
		return base64_encode( $iv . $cipher );
	}

	public static function decrypt( string $payload ): string {
		$raw = base64_decode( $payload, true );
		if ( $raw === false ) {
			return '';
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return $raw;
		}
		$ivlen = openssl_cipher_iv_length( self::METHOD );
		if ( strlen( $raw ) <= $ivlen ) {
			return '';
		}
		$iv     = substr( $raw, 0, $ivlen );
		$cipher = substr( $raw, $ivlen );

		foreach ( self::keys() as $key ) {
			$plaintext = openssl_decrypt( $cipher, self::METHOD, $key, OPENSSL_RAW_DATA, $iv );
			if ( $plaintext !== false ) {
				return $plaintext;
			}
		}
		return '';
	}

	private static function key(): string {
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY !== '' ) {
			$salt = AUTH_KEY;
		} elseif ( function_exists( 'wp_salt' ) ) {
			$salt = wp_salt( 'secure_auth' );
		} else {
			$salt = __DIR__;
		}
		return hash( 'sha256', $salt, true );
	}

	/** @return string[] */
	private static function keys(): array {
		return [
			self::key(),
			hash( 'sha256', __DIR__, true ),
			hash( 'sha256', '', true ),
		];
	}
}
