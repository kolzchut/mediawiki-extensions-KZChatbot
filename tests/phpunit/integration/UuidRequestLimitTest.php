<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Integration;

use MediaWiki\Extension\KZChatbot\ApiKZChatbotGetStatus;
use MediaWiki\Extension\KZChatbot\KZChatbot;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use ReflectionProperty;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\WRStats\BagOStuffStatsStore;
use Wikimedia\WRStats\WRStatsFactory;
use Wikimedia\WRStats\WRStatsRateLimiter;

/**
 * uuid_request_limit caps how many chatbot identities `status` creates for one
 * IP address within an hour. Each identity has its own daily question quota
 * and takes an active-user seat, so without the cap a client that drops its
 * cookie can reset its quota, or fill the seats, at will.
 *
 * @covers \MediaWiki\Extension\KZChatbot\KZChatbot::newUser
 * @covers \MediaWiki\Extension\KZChatbot\ApiKZChatbotGetStatus
 * @group Database
 */
class UuidRequestLimitTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	private const LIMIT = 3;

	private const IP = '198.51.100.7';

	private const OTHER_IP = '198.51.100.8';

	private const BYPASS_TOKEN = 'uuid-request-limit-test-token';

	/** A fixed clock, so the hour's window can be stepped past. */
	private const NOW = 1800000000;

	private WRStatsFactory $wrStatsFactory;

	protected function setUp(): void {
		parent::setUp();
		$this->clearSettingsCache();
		$this->overrideConfigValue( 'KZChatbotLimitBypassToken', self::BYPASS_TOKEN );
		// A private, empty store for the counts, so nothing carries over from
		// another test or from the configured MicroStash.
		$this->wrStatsFactory = new WRStatsFactory( new BagOStuffStatsStore( new HashBagOStuff() ) );
		$this->wrStatsFactory->setCurrentTime( self::NOW );
		$this->setService( 'WRStatsFactory', $this->wrStatsFactory );
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
	 * @param int $rate new_users_chatbot_rate: 100 selects every new visitor
	 * @param string|null $limit uuid_request_limit, or null for no row at all
	 */
	private function setSettings( int $rate, ?string $limit ): void {
		$dbw = $this->getDb();
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'kzchatbot_settings' )
			->where( [ 'kzcbs_name' => [ 'new_users_chatbot_rate', 'uuid_request_limit' ] ] )
			->caller( __METHOD__ )
			->execute();
		$rows = [ [ 'kzcbs_name' => 'new_users_chatbot_rate', 'kzcbs_value' => (string)$rate ] ];
		if ( $limit !== null ) {
			$rows[] = [ 'kzcbs_name' => 'uuid_request_limit', 'kzcbs_value' => $limit ];
		}
		$dbw->newInsertQueryBuilder()
			->insertInto( 'kzchatbot_settings' )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();
		$this->clearSettingsCache();
	}

	/**
	 * A first-visit `status` call (no uuid) from the given address.
	 *
	 * @param string $ip
	 * @param bool $bypass Send the limit bypass token
	 * @return array The decoded response body
	 */
	private function newVisitorStatus( string $ip, bool $bypass = false ): array {
		$request = new FauxRequest( $bypass ? [ 'kzchatbot_access' => self::BYPASS_TOKEN ] : [] );
		$request->setIP( $ip );
		$this->setRequest( $request );
		return $this->executeHandlerAndGetBodyData(
			new ApiKZChatbotGetStatus(),
			new RequestData( [ 'method' => 'GET', 'queryParams' => [] ] )
		);
	}

	private function assertIdentityCreated( array $data, string $message = '' ): void {
		$this->assertIsString( $data['uuid'] ?? null, $message );
		$this->assertNotSame( '', $data['uuid'], $message );
		$this->assertTrue( $data['chatbotIsShown'] ?? null, $message );
	}

	private function countUsersFrom( string $ip ): int {
		return (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'kzchatbot_users' )
			->where( [ 'kzcbu_ip_address' => inet_pton( $ip ) ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * The acceptance case: the first LIMIT calls from one address get a new
	 * uuid each, and the next one in the same hour gets `uuid: false` and
	 * creates nothing.
	 */
	public function testCallPastTheLimitFromOneIpGetsNoUuid() {
		$this->setSettings( 100, (string)self::LIMIT );

		for ( $i = 1; $i <= self::LIMIT; $i++ ) {
			$this->assertIdentityCreated( $this->newVisitorStatus( self::IP ), "Call $i is within the limit" );
		}
		$this->assertSame( [ 'uuid' => false ], $this->newVisitorStatus( self::IP ) );
		$this->assertSame( self::LIMIT, $this->countUsersFrom( self::IP ), 'The refused call inserted no user' );
	}

	public function testOtherAddressesHaveTheirOwnCount() {
		$this->setSettings( 100, (string)self::LIMIT );

		for ( $i = 1; $i <= self::LIMIT; $i++ ) {
			$this->newVisitorStatus( self::IP );
		}
		$this->assertSame( [ 'uuid' => false ], $this->newVisitorStatus( self::IP ) );
		$this->assertIdentityCreated( $this->newVisitorStatus( self::OTHER_IP ) );
	}

	public function testIPv6AddressesInOneSlash64ShareTheirCount() {
		$this->setSettings( 100, '1' );

		$this->assertIdentityCreated( $this->newVisitorStatus( '2001:db8:1:2::1' ) );
		$this->assertSame( [ 'uuid' => false ], $this->newVisitorStatus( '2001:db8:1:2::abcd' ) );
		$this->assertIdentityCreated( $this->newVisitorStatus( '2001:db8:1:3::1' ), 'Another /64' );
	}

	public function testTheLimitIsPerHour() {
		$this->setSettings( 100, (string)self::LIMIT );

		for ( $i = 1; $i <= self::LIMIT; $i++ ) {
			$this->newVisitorStatus( self::IP );
		}
		$this->assertSame( [ 'uuid' => false ], $this->newVisitorStatus( self::IP ) );

		$window = KZChatbot::UUID_REQUEST_LIMIT_WINDOW;
		$this->wrStatsFactory->setCurrentTime( self::NOW + $window - 1 );
		$this->assertSame( [ 'uuid' => false ], $this->newVisitorStatus( self::IP ), 'Still within the hour' );

		// WRStats counts in time buckets, so a count ages out up to one bucket
		// after the window.
		$bucket = $window / WRStatsRateLimiter::BUCKET_COUNT;
		$this->wrStatsFactory->setCurrentTime( self::NOW + $window + $bucket + 1 );
		$this->assertIdentityCreated( $this->newVisitorStatus( self::IP ), 'A new hour, a new count' );
	}

	public static function provideNoLimit(): iterable {
		yield 'zero, the value of a field never filled in' => [ '0' ];
		yield 'empty' => [ '' ];
		yield 'negative' => [ '-1' ];
		yield 'no settings row' => [ null ];
	}

	/**
	 * @dataProvider provideNoLimit
	 */
	public function testUnsetOrZeroLimitLimitsNothing( ?string $limit ) {
		$this->setSettings( 100, $limit );

		for ( $i = 1; $i <= self::LIMIT + 2; $i++ ) {
			$this->assertIdentityCreated( $this->newVisitorStatus( self::IP ), "Call $i" );
		}
	}

	public function testBypassTokenSkipsTheLimit() {
		$this->setSettings( 100, '1' );

		$this->assertIdentityCreated( $this->newVisitorStatus( self::IP ) );
		$this->assertSame( [ 'uuid' => false ], $this->newVisitorStatus( self::IP ) );
		$this->assertIdentityCreated( $this->newVisitorStatus( self::IP, true ) );
	}

	/**
	 * Only created identities count. A visitor who was not selected gets
	 * `uuid: false` anyway, and must not use up the address's allowance.
	 */
	public function testVisitorsNotSelectedDoNotUseUpTheLimit() {
		$this->setSettings( 0, (string)self::LIMIT );
		for ( $i = 1; $i <= self::LIMIT + 2; $i++ ) {
			$this->assertSame( [ 'uuid' => false ], $this->newVisitorStatus( self::IP ) );
		}
		$this->assertSame( 0, $this->countUsersFrom( self::IP ) );

		$this->setSettings( 100, (string)self::LIMIT );
		for ( $i = 1; $i <= self::LIMIT; $i++ ) {
			$this->assertIdentityCreated( $this->newVisitorStatus( self::IP ), "Call $i after selection opens" );
		}
	}

	/**
	 * A returning visitor creates no identity, so the limit never refuses one.
	 */
	public function testReturningVisitorIsNotLimited() {
		$this->setSettings( 100, '1' );
		$first = $this->newVisitorStatus( self::IP );
		$this->assertIdentityCreated( $first );
		$this->assertSame( [ 'uuid' => false ], $this->newVisitorStatus( self::IP ) );

		$request = new FauxRequest();
		$request->setIP( self::IP );
		$this->setRequest( $request );
		$data = $this->executeHandlerAndGetBodyData(
			new ApiKZChatbotGetStatus(),
			new RequestData( [ 'method' => 'GET', 'queryParams' => [ 'uuid' => $first['uuid'] ] ] )
		);
		$this->assertSame( $first['uuid'], $data['uuid'] );
		$this->assertTrue( $data['chatbotIsShown'] );
	}
}
