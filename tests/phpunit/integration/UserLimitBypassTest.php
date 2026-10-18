<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Integration;

use MediaWiki\Extension\KZChatbot\UserLimitBypass;
use MediaWiki\Request\FauxRequest;
use MediaWikiIntegrationTestCase;
use RequestContext;

/**
 * @covers \MediaWiki\Extension\KZChatbot\UserLimitBypass
 */
class UserLimitBypassTest extends MediaWikiIntegrationTestCase {

	private const PARAM = 'kzchatbot_access';
	private const TOKEN = 'correct-horse-battery-staple';

	/**
	 * @param mixed $token Value of $wgKZChatbotLimitBypassToken
	 * @param array $query Request parameters
	 * @return bool
	 */
	private function shouldBypassWith( $token, array $query ): bool {
		$this->overrideConfigValue( 'KZChatbotLimitBypassToken', $token );
		RequestContext::getMain()->setRequest( new FauxRequest( $query ) );
		return UserLimitBypass::shouldBypass();
	}

	public function testFalseTokenDisablesBypass() {
		$this->assertFalse( $this->shouldBypassWith( false, [ self::PARAM => '' ] ) );
		$this->assertFalse( $this->shouldBypassWith( false, [ self::PARAM => 'anything' ] ) );
	}

	/**
	 * The case that matters: a site that fills the setting from an unset
	 * environment variable ends up with ''. A present-but-empty parameter
	 * must not match it.
	 */
	public function testEmptyStringTokenDisablesBypass() {
		$this->assertFalse( $this->shouldBypassWith( '', [ self::PARAM => '' ] ) );
		$this->assertFalse( $this->shouldBypassWith( '', [ self::PARAM => 'anything' ] ) );
		$this->assertFalse( $this->shouldBypassWith( '', [] ) );
	}

	public static function provideNonStringTokens(): array {
		return [
			'null' => [ null ],
			'true' => [ true ],
			'zero' => [ 0 ],
			'integer' => [ 12345 ],
			'array' => [ [ self::TOKEN ] ],
		];
	}

	/**
	 * @dataProvider provideNonStringTokens
	 * @param mixed $token
	 */
	public function testNonStringTokenDisablesBypass( $token ) {
		$this->assertFalse( $this->shouldBypassWith( $token, [ self::PARAM => '' ] ) );
		$this->assertFalse( $this->shouldBypassWith( $token, [ self::PARAM => '12345' ] ) );
		$this->assertFalse( $this->shouldBypassWith( $token, [ self::PARAM => self::TOKEN ] ) );
	}

	public function testCorrectTokenBypasses() {
		$this->assertTrue( $this->shouldBypassWith( self::TOKEN, [ self::PARAM => self::TOKEN ] ) );
	}

	public function testWrongTokenDoesNotBypass() {
		$this->assertFalse( $this->shouldBypassWith( self::TOKEN, [ self::PARAM => 'wrong' ] ) );
		$this->assertFalse( $this->shouldBypassWith( self::TOKEN, [ self::PARAM => '' ] ) );
		$this->assertFalse( $this->shouldBypassWith( self::TOKEN, [ self::PARAM => self::TOKEN . 'x' ] ) );
		$this->assertFalse( $this->shouldBypassWith( self::TOKEN, [ self::PARAM => [ self::TOKEN ] ] ) );
	}

	public function testAbsentParamDoesNotBypass() {
		$this->assertFalse( $this->shouldBypassWith( self::TOKEN, [] ) );
		$this->assertFalse( $this->shouldBypassWith( self::TOKEN, [ 'other' => self::TOKEN ] ) );
	}
}
