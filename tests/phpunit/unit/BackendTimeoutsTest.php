<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Unit;

use MediaWiki\Extension\KZChatbot\BackendTimeouts;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\KZChatbot\BackendTimeouts
 */
class BackendTimeoutsTest extends MediaWikiUnitTestCase {

	private const START = 1000000.0;

	public function testMaxTotalEndsBeforeTheEdgeGivesUp() {
		$this->assertLessThan( BackendTimeouts::EDGE_LIMIT, BackendTimeouts::MAX_TOTAL );
		$this->assertLessThan( BackendTimeouts::MAX_TOTAL, BackendTimeouts::DEFAULT_CONNECT );
	}

	public static function provideTotal() {
		// configured, seconds already spent in the request, expected
		yield 'within the cap, at request start' => [ 60, 0.0, 60 ];
		yield 'the old 120 s default is capped' => [ 120, 0.0, BackendTimeouts::MAX_TOTAL ];
		yield 'the old 180 s proxy timeout is capped' => [ 180, 0.0, BackendTimeouts::MAX_TOTAL ];
		yield 'INF is capped' => [ INF, 0.0, BackendTimeouts::MAX_TOTAL ];
		yield 'time already spent is subtracted' => [ 90, 7.5, 82 ];
		yield 'a short timeout is untouched by time spent' => [ 25, 7.5, 25 ];
		yield 'a spent budget still allows one second' => [ 90, 95.0, 1 ];
		yield 'a fractional value rounds up' => [ 2.2, 0.0, 3 ];
		yield 'numeric string' => [ '45', 0.0, 45 ];
		yield 'zero means the cap' => [ 0, 0.0, BackendTimeouts::MAX_TOTAL ];
		yield 'negative means the cap' => [ -5, 0.0, BackendTimeouts::MAX_TOTAL ];
		yield 'null means the cap' => [ null, 0.0, BackendTimeouts::MAX_TOTAL ];
		yield 'non-numeric means the cap' => [ 'default', 0.0, BackendTimeouts::MAX_TOTAL ];
		yield 'a clock behind the request start counts as no time spent' => [ 90, -3.0, 90 ];
	}

	/**
	 * @dataProvider provideTotal
	 */
	public function testTotal( $configured, float $spent, int $expected ) {
		$this->assertSame(
			$expected,
			BackendTimeouts::total( $configured, self::START + $spent, self::START )
		);
	}

	public function testTotalNeverReachesTheEdgeLimit() {
		foreach ( [ 1, 25, 90, 99, 100, 120, 180, 3600, INF ] as $configured ) {
			foreach ( [ 0.0, 0.4, 1.0, 10.0, 89.0 ] as $spent ) {
				$total = BackendTimeouts::total( $configured, self::START + $spent, self::START );
				$this->assertLessThan(
					BackendTimeouts::EDGE_LIMIT,
					$spent + $total,
					"configured $configured, $spent s spent"
				);
			}
		}
	}

	public function testCommandLineSpendsNoBudgetBeforeTheCall() {
		// REQUEST_TIME_FLOAT is when a CLI process started (a maintenance script,
		// this PHPUnit run); no edge waits on it, so it must not eat the budget.
		$saved = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
		try {
			$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ) - 300;
			$this->assertSame( 60, BackendTimeouts::total( 60 ) );
			$this->assertGreaterThan( BackendTimeouts::MAX_TOTAL - 1, BackendTimeouts::remaining() );
		} finally {
			if ( $saved === null ) {
				unset( $_SERVER['REQUEST_TIME_FLOAT'] );
			} else {
				$_SERVER['REQUEST_TIME_FLOAT'] = $saved;
			}
		}
	}

	public function testRemaining() {
		$max = BackendTimeouts::MAX_TOTAL;
		$this->assertSame( (float)$max, BackendTimeouts::remaining( self::START, self::START ) );
		$this->assertSame( 30.5, BackendTimeouts::remaining( self::START + $max - 30.5, self::START ) );
		$this->assertLessThan( 1, BackendTimeouts::remaining( self::START + $max, self::START ) );
	}

	public static function provideConnect() {
		// configured, total, expected
		yield 'the default' => [ 5, 90, 5 ];
		yield 'never longer than the total' => [ 5, 3, 3 ];
		yield 'a huge value is capped by the total' => [ 600, 90, 90 ];
		yield 'INF is capped by the total' => [ INF, 90, 90 ];
		yield 'a fractional value rounds up' => [ 0.5, 90, 1 ];
		yield 'unset falls back to the default' => [ null, 90, BackendTimeouts::DEFAULT_CONNECT ];
		yield 'zero falls back to the default' => [ 0, 90, BackendTimeouts::DEFAULT_CONNECT ];
		yield 'non-numeric falls back to the default' => [ 'default', 90, BackendTimeouts::DEFAULT_CONNECT ];
		yield 'the default is capped by a short total' => [ null, 2, 2 ];
	}

	/**
	 * @dataProvider provideConnect
	 */
	public function testConnect( $configured, int $total, int $expected ) {
		$this->assertSame( $expected, BackendTimeouts::connect( $configured, $total ) );
	}
}
