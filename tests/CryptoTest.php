<?php

namespace AggregateIt\Tests;

use AggregateIt\Support\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase {

	protected function setUp(): void {
		defined( 'AUTH_KEY' ) || define( 'AUTH_KEY', 'test-auth-key' );
		defined( 'SECURE_AUTH_SALT' ) || define( 'SECURE_AUTH_SALT', 'test-salt' );
	}

	public function test_round_trips_and_marks_current_scheme(): void {
		$stored = Crypto::encrypt( 'sk-secret' );
		$this->assertTrue( Crypto::is_current( $stored ) );
		$this->assertStringNotContainsString( 'sk-secret', base64_decode( substr( $stored, 3 ) ) );
		$this->assertSame( 'sk-secret', Crypto::decrypt( $stored ) );
	}

	public function test_tampered_value_does_not_decrypt(): void {
		$stored = Crypto::encrypt( 'sk-secret' );
		$raw    = base64_decode( substr( $stored, 3 ) );
		$raw[ strlen( $raw ) - 1 ] = $raw[ strlen( $raw ) - 1 ] ^ "\x01";
		$this->assertSame( '', Crypto::decrypt( 'v2:' . base64_encode( $raw ) ) );
	}

	public function test_legacy_cbc_values_still_decrypt(): void {
		$iv     = random_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );
		$cipher = openssl_encrypt( 'old-key', 'aes-256-cbc', hash( 'sha256', AUTH_KEY, true ), OPENSSL_RAW_DATA, $iv );
		$legacy = base64_encode( $iv . $cipher );

		$this->assertFalse( Crypto::is_current( $legacy ) );
		$this->assertSame( 'old-key', Crypto::decrypt( $legacy ) );
	}
}
