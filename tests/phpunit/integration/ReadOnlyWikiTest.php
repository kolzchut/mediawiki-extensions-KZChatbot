<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Integration;

use MediaWiki\Extension\KZChatbot\ApiKZChatbotGetStatus;
use MediaWiki\Extension\KZChatbot\ApiKZChatbotSubmitQuestion;
use MediaWiki\Extension\KZChatbot\KZChatbot;
use MediaWiki\Extension\KZChatbot\Slugs;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use ReflectionProperty;
use Wikimedia\Rdbms\DBReadOnlyError;

/**
 * While the wiki is read-only every chatbot write throws DBReadOnlyError, and
 * those used to reach readers as 500s (#87): a new visitor's status, and every
 * question, because counting a question is a write.
 *
 * Read-only mode is switched on through $wgReadOnly, which the ReadOnlyMode
 * service reads. The test database itself stays writable, so a write that
 * slipped past the guard would succeed rather than throw. That is why each
 * read-only case also checks the table, and why each has a writable twin
 * showing the same request does write when the guard is not in the way.
 *
 * @covers \MediaWiki\Extension\KZChatbot\ApiKZChatbotGetStatus
 * @covers \MediaWiki\Extension\KZChatbot\ApiKZChatbotSubmitQuestion
 * @covers \MediaWiki\Extension\KZChatbot\KZChatbot::isReadOnly
 * @group Database
 */
class ReadOnlyWikiTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;
	use MockHttpTrait;

	private const BYPASS_TOKEN = 'read-only-wiki-test-token';

	private const RETURNING_UUID = '0123abcd-0123-4abc-8def-0123456789ab';

	protected function setUp(): void {
		parent::setUp();
		// The bypass selects every new visitor without consulting the rate or the
		// active-users cap. So a visitor who is not created was refused by the
		// read-only guard, not by the selection lottery.
		$this->overrideConfigValue( 'KZChatbotLimitBypassToken', self::BYPASS_TOKEN );
		$this->setRequest( new FauxRequest( [ 'kzchatbot_access' => self::BYPASS_TOKEN ] ) );
		$this->clearCaches();
		// BannedWord, on the question path, still reads through wfGetDB().
		$this->filterDeprecated( '/Use of wfGetDB was deprecated/' );
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'kzchatbot_settings' )
			->rows( [
				[ 'kzcbs_name' => 'question_character_limit', 'kzcbs_value' => '500' ],
				[ 'kzcbs_name' => 'feedback_character_limit', 'kzcbs_value' => '500' ],
				[ 'kzcbs_name' => 'questions_daily_limit', 'kzcbs_value' => '10' ],
			] )
			->caller( __METHOD__ )
			->execute();
	}

	protected function tearDown(): void {
		$this->clearCaches();
		parent::tearDown();
	}

	/**
	 * Slugs and the general settings are memoised in static properties, which
	 * survive between tests in the same PHP process.
	 */
	private function clearCaches(): void {
		foreach ( [ [ Slugs::class, 'slugsRaw' ], [ KZChatbot::class, 'generalSettings' ] ] as [ $class, $prop ] ) {
			$cache = new ReflectionProperty( $class, $prop );
			$cache->setAccessible( true );
			$cache->setValue( null, null );
		}
	}

	private function setReadOnly(): void {
		$this->overrideConfigValue( MainConfigNames::ReadOnly, 'Maintenance window (test)' );
		$this->assertTrue( KZChatbot::isReadOnly(), 'Fixture: the wiki must report read-only' );
	}

	private function insertReturningUser( int $questionsToday ): void {
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'kzchatbot_users' )
			->row( [
				'kzcbu_uuid' => KZChatbot::rawUuidFromFormatted( self::RETURNING_UUID ),
				'kzcbu_ip_address' => inet_pton( '127.0.0.1' ),
				'kzcbu_cookie_expiry' => wfTimestamp( TS_MW, time() + 86400 ),
				'kzcbu_last_active' => wfTimestampNow(),
				'kzcbu_questions_last_active_day' => $questionsToday,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	private function userCount(): int {
		return (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'kzchatbot_users' )
			->caller( __METHOD__ )
			->fetchField();
	}

	private function questionsToday(): int {
		return (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'kzcbu_questions_last_active_day' )
			->from( 'kzchatbot_users' )
			->where( [ 'kzcbu_uuid' => KZChatbot::rawUuidFromFormatted( self::RETURNING_UUID ) ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	private function status( ?string $uuid ): array {
		$query = $uuid === null ? [] : [ 'uuid' => $uuid ];
		return $this->executeHandlerAndGetBodyData(
			new ApiKZChatbotGetStatus(),
			new RequestData( [ 'method' => 'GET', 'queryParams' => $query ] )
		);
	}

	private function questionRequest(): RequestData {
		return new RequestData( [
			'method' => 'POST',
			'headers' => [ 'Content-Type' => 'application/json' ],
			'bodyContents' => json_encode( [
				'query' => 'What am I entitled to?',
				'uuid' => self::RETURNING_UUID,
				'referrer' => 0,
			] ),
		] );
	}

	public function testNewVisitorIsCreatedWhileWritable() {
		$data = $this->status( null );

		$this->assertTrue( $data['chatbotIsShown'] );
		$this->assertIsString( $data['uuid'] );
		$this->assertNotSame( '', $data['uuid'] );
		$this->assertSame( 1, $this->userCount() );
	}

	/**
	 * The launcher renders nothing unless chatbotIsShown is true, and touches
	 * the cookie only when uuid differs from the one it sent. `uuid: false`
	 * would set the one-day exclusion cookie instead.
	 */
	public function testNewVisitorGetsNotAvailableWhileReadOnly() {
		$this->setReadOnly();

		$data = $this->status( null );

		$this->assertSame(
			[ 'uuid' => '', 'chatbotIsShown' => false, 'readOnly' => true ],
			$data
		);
		$this->assertSame( 0, $this->userCount() );
	}

	/**
	 * A cookie whose user row is gone (pruned, or from another wiki) goes down
	 * the new-visitor path. Echoing it back keeps the launcher from rewriting it.
	 */
	public function testUnknownUuidIsEchoedBackWhileReadOnly() {
		$this->setReadOnly();

		$data = $this->status( self::RETURNING_UUID );

		$this->assertSame( self::RETURNING_UUID, $data['uuid'] );
		$this->assertFalse( $data['chatbotIsShown'] );
		$this->assertSame( 0, $this->userCount() );
	}

	public function testReturningVisitorStillGetsTheChatbotWhileReadOnly() {
		$this->insertReturningUser( 2 );
		$this->setReadOnly();

		$data = $this->status( self::RETURNING_UUID );

		$this->assertSame( self::RETURNING_UUID, $data['uuid'] );
		$this->assertTrue( $data['chatbotIsShown'] );
		$this->assertArrayNotHasKey( 'readOnly', $data );
	}

	public function testQuestionIsAnsweredAndCountedWhileWritable() {
		$this->insertReturningUser( 2 );
		$this->installMockHttp( $this->makeFakeHttpRequest(
			json_encode( [ 'llm_answer' => 'An answer', 'docs' => [] ] )
		) );

		$data = $this->executeHandlerAndGetBodyData(
			new ApiKZChatbotSubmitQuestion(), $this->questionRequest()
		);

		$this->assertSame( 'An answer', $data['llmResult'] );
		$this->assertSame( 3, $this->questionsToday() );
	}

	/**
	 * The status must be a 4xx: the React client shows the server's message
	 * only for a 4xx, and shows its own general error for anything else.
	 */
	public function testQuestionIsRefusedWhileReadOnly() {
		$this->insertReturningUser( 2 );
		$this->setReadOnly();
		// Any call to the RAG backend fails the test.
		$this->installMockHttp();

		$e = $this->executeHandlerAndGetHttpException(
			new ApiKZChatbotSubmitQuestion(), $this->questionRequest()
		);

		$this->assertSame( 423, $e->getCode() );
		$this->assertSame( Slugs::getDefaultSlugs()['read_only'], $e->getMessage() );
		$this->assertSame( 2, $this->questionsToday(), 'A refused question must not be counted' );
	}

	public function testReadOnlySlugCanBeOverridden() {
		$this->insertReturningUser( 2 );
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'kzchatbot_text' )
			->row( [ 'kzcbt_slug' => 'read_only', 'kzcbt_text' => 'Back soon' ] )
			->caller( __METHOD__ )
			->execute();
		$this->setReadOnly();
		$this->installMockHttp();

		$e = $this->executeHandlerAndGetHttpException(
			new ApiKZChatbotSubmitQuestion(), $this->questionRequest()
		);

		$this->assertSame( 'Back soon', $e->getMessage() );
	}

	/**
	 * The up-front check can be overtaken: the wiki may go read-only between it
	 * and the write. Whatever write then throws, the reader gets the read-only
	 * answer, not the general error. The fault is injected where the handler
	 * calls out, since the test database cannot be made read-only.
	 */
	public function testReadOnlyErrorMidRequestBecomesTheReadOnlyAnswer() {
		$this->insertReturningUser( 2 );
		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willThrowException(
			new DBReadOnlyError( null, 'Database is read-only: test' )
		);
		$this->setService( 'HttpRequestFactory', $factory );

		$e = $this->executeHandlerAndGetHttpException(
			new ApiKZChatbotSubmitQuestion(), $this->questionRequest()
		);

		$this->assertSame( 423, $e->getCode() );
		$this->assertSame( Slugs::getDefaultSlugs()['read_only'], $e->getMessage() );
	}
}
