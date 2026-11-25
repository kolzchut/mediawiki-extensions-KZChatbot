<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Unit;

use MediaWiki\Extension\KZChatbot\KZChatbot;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\KZChatbot\KZChatbot::uuidRequestLimitKey
 */
class UuidRequestLimitKeyTest extends MediaWikiUnitTestCase {

	public function testIPv4AddressesCountApart() {
		$this->assertNotSame(
			KZChatbot::uuidRequestLimitKey( '198.51.100.7' ),
			KZChatbot::uuidRequestLimitKey( '198.51.100.8' )
		);
		$this->assertSame(
			KZChatbot::uuidRequestLimitKey( '198.51.100.7' ),
			KZChatbot::uuidRequestLimitKey( '198.51.100.7' )
		);
	}

	public function testIPv6AddressesInOneSlash64CountTogether() {
		$this->assertSame(
			KZChatbot::uuidRequestLimitKey( '2001:db8:1:2::1' ),
			KZChatbot::uuidRequestLimitKey( '2001:db8:1:2:ffff:ffff:ffff:fffe' )
		);
	}

	public function testIPv6SpellingDoesNotMatter() {
		$this->assertSame(
			KZChatbot::uuidRequestLimitKey( '2001:db8:1:2::1' ),
			KZChatbot::uuidRequestLimitKey( '2001:0DB8:0001:0002:0000:0000:0000:0001' )
		);
	}

	public function testDifferentIPv6Slash64sCountApart() {
		$this->assertNotSame(
			KZChatbot::uuidRequestLimitKey( '2001:db8:1:2::1' ),
			KZChatbot::uuidRequestLimitKey( '2001:db8:1:3::1' )
		);
	}
}
