<?php

namespace AggregateIt\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Back-compat facade over ActivityLog. Existing call sites log a plain message; richer
 * call sites (state transitions, before/after detail) use ActivityLog::record directly.
 */
final class EventLog {

	public static function error( string $message ): void {
		ActivityLog::record( 'error', $message );
	}

	public static function warning( string $message ): void {
		ActivityLog::record( 'warning', $message );
	}

	public static function info( string $message ): void {
		ActivityLog::record( 'info', $message );
	}

	/** @return array<int,array{time:string,level:string,message:string}> */
	public static function all(): array {
		return ActivityLog::recent( 200 );
	}

	public static function clear(): void {
		ActivityLog::clear();
	}
}
