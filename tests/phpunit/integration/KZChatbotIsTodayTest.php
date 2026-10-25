<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Integration;

use MediaWiki\Extension\KZChatbot\KZChatbot;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Every instant here is built with mktime(), which reads PHP's default
 * timezone, the same one KZChatbot::isToday() compares in. So the cases hold
 * whatever $wgLocaltimezone the test wiki runs with.
 *
 * @covers \MediaWiki\Extension\KZChatbot\KZChatbot::isToday
 */
class KZChatbotIsTodayTest extends MediaWikiIntegrationTestCase {

	/**
	 * 15 March 2026, noon local time.
	 */
	private function freezeNow(): int {
		$now = mktime( 12, 0, 0, 3, 15, 2026 );
		ConvertibleTimestamp::setFakeTime( $now );
		return $now;
	}

	public function testEarlierTodayIsToday() {
		$this->freezeNow();
		$this->assertTrue( KZChatbot::isToday( wfTimestamp( TS_MW, mktime( 0, 0, 1, 3, 15, 2026 ) ) ) );
	}

	public function testNowIsToday() {
		$now = $this->freezeNow();
		$this->assertTrue( KZChatbot::isToday( wfTimestamp( TS_MW, $now ) ) );
	}

	/**
	 * The defect this guards: the same day of the year, one year back, used
	 * to count as today, carrying last year's question count over.
	 */
	public function testSameDayOfYearLastYearIsNotToday() {
		$now = $this->freezeNow();
		$lastYear = mktime( 12, 0, 0, 3, 15, 2025 );
		$this->assertSame( date( 'z', $now ), date( 'z', $lastYear ),
			'Fixture must share the day of the year with now' );
		$this->assertFalse( KZChatbot::isToday( wfTimestamp( TS_MW, $lastYear ) ) );
	}

	public function testLateYesterdayIsNotToday() {
		$this->freezeNow();
		$this->assertFalse( KZChatbot::isToday( wfTimestamp( TS_MW, mktime( 23, 59, 59, 3, 14, 2026 ) ) ) );
	}

	public static function provideUnreadable(): array {
		return [
			'null' => [ null ],
			'empty string' => [ '' ],
			'empty BINARY(14) column, as MySQL returns it' => [ str_repeat( "\0", 14 ) ],
			'NUL padded' => [ "\0\0\0  \0" ],
			'whitespace only' => [ "  \t " ],
			'garbage' => [ 'not a timestamp' ],
		];
	}

	/**
	 * @dataProvider provideUnreadable
	 * @param mixed $timestamp
	 */
	public function testUnreadableIsNotToday( $timestamp ) {
		$this->freezeNow();
		$this->assertFalse( KZChatbot::isToday( $timestamp ) );
	}
}
