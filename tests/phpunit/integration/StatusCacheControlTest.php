<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Integration;

use MediaWiki\Extension\KZChatbot\ApiKZChatbotGetStatus;
use MediaWiki\Extension\KZChatbot\KZChatbot;
use MediaWiki\MainConfigNames;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Rest\RequestData;
use MediaWiki\Session\Session;
use MediaWiki\Session\SessionId;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use ReflectionProperty;

/**
 * Every status answer must be kept out of shared caches (#89). The edge
 * caches a 200 that carries no Cache-Control, and core adds one only when the
 * response sets a cookie. Here that happens only as a side effect of a
 * database write, so the answers that write nothing were cacheable: the
 * read-only answer, the returning visitor, and the visitor who was not
 * selected.
 *
 * Each case asserts the body as well, to show the branch it names was the one
 * that ran.
 *
 * @covers \MediaWiki\Extension\KZChatbot\ApiKZChatbotGetStatus
 * @group Database
 */
class StatusCacheControlTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	private const BYPASS_TOKEN = 'status-cache-control-test-token';

	private const RETURNING_UUID = '0123abcd-0123-4abc-8def-0123456789ab';

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( 'KZChatbotLimitBypassToken', self::BYPASS_TOKEN );
		$this->clearSettingsCache();
	}

	protected function tearDown(): void {
		$this->clearSettingsCache();
		parent::tearDown();
	}

	/**
	 * The general settings are memoised in a static property, which survives
	 * between tests in the same PHP process.
	 */
	private function clearSettingsCache(): void {
		$cache = new ReflectionProperty( KZChatbot::class, 'generalSettings' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );
	}

	/**
	 * @param bool $bypass Select every new visitor, skipping the lottery
	 * @param int $rate new_users_chatbot_rate, for when the lottery runs
	 */
	private function setSelection( bool $bypass, int $rate ): void {
		$this->setRequest( new FauxRequest( $bypass ? [ 'kzchatbot_access' => self::BYPASS_TOKEN ] : [] ) );
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'kzchatbot_settings' )
			->row( [ 'kzcbs_name' => 'new_users_chatbot_rate', 'kzcbs_value' => (string)$rate ] )
			->caller( __METHOD__ )
			->execute();
	}

	private function insertReturningUser(): void {
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'kzchatbot_users' )
			->row( [
				'kzcbu_uuid' => KZChatbot::rawUuidFromFormatted( self::RETURNING_UUID ),
				'kzcbu_ip_address' => inet_pton( '127.0.0.1' ),
				'kzcbu_cookie_expiry' => wfTimestamp( TS_MW, time() + 86400 ),
				'kzcbu_last_active' => wfTimestampNow(),
				'kzcbu_questions_last_active_day' => 0,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	public static function provideBranches(): array {
		return [
			'new visitor, selected' => [ false, null, true, 100, false, static function ( $t, $data ) {
				$t->assertTrue( $data['chatbotIsShown'] );
				$t->assertNotSame( '', $data['uuid'] );
			} ],
			'new visitor, not selected' => [ false, null, false, 0, false, static function ( $t, $data ) {
				$t->assertSame( [ 'uuid' => false ], $data );
			} ],
			'returning visitor' => [ false, self::RETURNING_UUID, false, 0, true, static function ( $t, $data ) {
				$t->assertSame( self::RETURNING_UUID, $data['uuid'] );
				$t->assertTrue( $data['chatbotIsShown'] );
			} ],
			'new visitor, read-only' => [ true, null, true, 100, false, static function ( $t, $data ) {
				$t->assertSame( [ 'uuid' => '', 'chatbotIsShown' => false, 'readOnly' => true ], $data );
			} ],
			'returning visitor, read-only' => [
				true, self::RETURNING_UUID, false, 0, true, static function ( $t, $data ) {
					$t->assertSame( self::RETURNING_UUID, $data['uuid'] );
					$t->assertTrue( $data['chatbotIsShown'] );
				}
			],
		];
	}

	/**
	 * @dataProvider provideBranches
	 */
	public function testStatusIsNeverCacheable(
		bool $readOnly, ?string $uuid, bool $bypass, int $rate, bool $returning, callable $checkBody
	) {
		$this->setSelection( $bypass, $rate );
		if ( $returning ) {
			$this->insertReturningUser();
		}
		if ( $readOnly ) {
			$this->overrideConfigValue( MainConfigNames::ReadOnly, 'Maintenance window (test)' );
			$this->assertTrue( KZChatbot::isReadOnly(), 'Fixture: the wiki must report read-only' );
		}

		// An anonymous reader with no session cookie, which is who the edge
		// caches for. The trait's default session is persistent, and core marks
		// every answer to a persistent session private by itself, which would
		// hide a missing header here.
		$session = $this->createMock( Session::class );
		$session->method( 'getSessionId' )->willReturn( new SessionId( 'test' ) );
		$session->method( 'getProvider' )->willReturn( $this->getSession( true )->getProvider() );
		$session->method( 'isPersistent' )->willReturn( false );

		$handler = new ApiKZChatbotGetStatus();
		$response = $this->executeHandler(
			$handler,
			new RequestData( [ 'method' => 'GET', 'queryParams' => $uuid === null ? [] : [ 'uuid' => $uuid ] ] ),
			[], [], [], [], null, $session
		);
		// The REST module runs this after execute(); the test trait does not.
		$handler->applyCacheControl( $response );

		$this->assertSame( 200, $response->getStatusCode() );
		$checkBody( $this, json_decode( (string)$response->getBody(), true ) );
		$cacheControl = $response->getHeaderLine( 'Cache-Control' );
		$this->assertMatchesRegularExpression( '/(^|,)\s*private\s*(,|$)/', $cacheControl );
		$this->assertMatchesRegularExpression( '/(^|,)\s*no-store\s*(,|$)/', $cacheControl );
	}
}
