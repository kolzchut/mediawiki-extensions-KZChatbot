<?php

namespace MediaWiki\Extension\KZChatbot;

/**
 * Timeouts for the calls this extension makes to the RAG backend.
 *
 * Every user request reaches the wiki through Cloudflare, which gives up on an
 * origin that has not answered within EDGE_LIMIT seconds and shows the user its
 * own HTML error page instead. A backend call allowed to run longer than that
 * means the extension's own error (JSON the chatbot can display) is never seen:
 * the edge has already answered (kolzchut/kz-mediawiki-main#179).
 *
 * So every backend call is bounded twice:
 *  - a connect timeout of a few seconds, so an unreachable backend fails fast;
 *  - a total timeout that ends HEADROOM seconds before the edge would give up,
 *    counted from the start of this web request, so the time MediaWiki spent
 *    before the call is subtracted, whatever $wgKZChatbotLlmApiTimeout says.
 */
final class BackendTimeouts {

	/** Seconds Cloudflare waits for an origin response before giving up. */
	public const EDGE_LIMIT = 100;

	/** Seconds reserved for the work around the backend call and the edge hop. */
	public const HEADROOM = 10;

	/** Longest total timeout any backend call can get. */
	public const MAX_TOTAL = self::EDGE_LIMIT - self::HEADROOM;

	/** Connect timeout used when none is configured. */
	public const DEFAULT_CONNECT = 5;

	/**
	 * Total timeout for a backend call, in whole seconds (at least 1).
	 *
	 * @param mixed $configured Requested total timeout in seconds
	 * @param float|null $now Current time; defaults to microtime( true )
	 * @param float|null $requestStart When this request started; see requestStart()
	 * @return int
	 */
	public static function total( $configured, ?float $now = null, ?float $requestStart = null ): int {
		$remaining = (int)floor( self::remaining( $now, $requestStart ) );
		return max( 1, min( self::seconds( $configured, self::MAX_TOTAL, self::MAX_TOTAL ), $remaining ) );
	}

	/**
	 * Seconds this request may still spend waiting on the backend: MAX_TOTAL
	 * less the time since the request started. Zero or less once it is spent,
	 * and a caller about to make one more of several calls should then give up
	 * rather than start it.
	 *
	 * @param float|null $now Current time; defaults to microtime( true )
	 * @param float|null $requestStart When this request started; see requestStart()
	 * @return float
	 */
	public static function remaining( ?float $now = null, ?float $requestStart = null ): float {
		$now ??= microtime( true );
		$requestStart ??= self::requestStart() ?? $now;
		return self::MAX_TOTAL - max( 0.0, $now - $requestStart );
	}

	/**
	 * When the current web request started, or null outside one. On the
	 * command line (maintenance scripts, PHPUnit) no edge is waiting, and
	 * REQUEST_TIME_FLOAT is when the whole process started.
	 *
	 * @return float|null
	 */
	private static function requestStart(): ?float {
		if ( PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' || !isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			return null;
		}
		return (float)$_SERVER['REQUEST_TIME_FLOAT'];
	}

	/**
	 * Connect timeout for a backend call, in whole seconds (at least 1), never
	 * longer than the call's total timeout.
	 *
	 * @param mixed $configured Requested connect timeout in seconds
	 * @param int $total The call's total timeout, from total()
	 * @return int
	 */
	public static function connect( $configured, int $total ): int {
		return max( 1, min( self::seconds( $configured, self::DEFAULT_CONNECT, $total ), $total ) );
	}

	/**
	 * @param mixed $value A configured number of seconds
	 * @param int $fallback Used when $value is not a positive number
	 * @param int $max Used when $value is at least this (or INF)
	 * @return int
	 */
	private static function seconds( $value, int $fallback, int $max ): int {
		if ( !is_numeric( $value ) || $value <= 0 ) {
			return $fallback;
		}
		if ( $value >= $max ) {
			return $max;
		}
		return max( 1, (int)ceil( (float)$value ) );
	}
}
