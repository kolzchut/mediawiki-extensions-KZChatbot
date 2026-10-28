<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Integration;

use MediaWiki\Extension\KZChatbot\ApiKZChatbotRateAnswer;
use MediaWiki\Extension\KZChatbot\KZChatbot;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Rest\RequestData;
use MediaWiki\Rest\Response;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use ReflectionProperty;
use StatusValue;
use TestLogger;

/**
 * The rate route passes the RAG backend's status through. When the backend
 * gave no HTTP answer, that status is 0, and nginx answers a bare 502 for it
 * ("upstream sent invalid status"). The route must answer a well-formed 503
 * with the localized JSON error the chatbot client reads instead (#91).
 *
 * @covers \MediaWiki\Extension\KZChatbot\ApiKZChatbotRateAnswer
 * @group Database
 */
class RateAnswerBackendStatusTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;
	use MockHttpTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->clearSettingsCache();
		// validate() reads the feedback length limit from the settings table.
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'kzchatbot_settings' )
			->row( [ 'kzcbs_name' => 'feedback_character_limit', 'kzcbs_value' => '150' ] )
			->caller( __METHOD__ )
			->execute();
	}

	protected function tearDown(): void {
		$this->clearSettingsCache();
		parent::tearDown();
	}

	/**
	 * The general settings, which validate() reads, are memoised in a static
	 * property that survives between tests in the same PHP process.
	 */
	private function clearSettingsCache(): void {
		$cache = new ReflectionProperty( KZChatbot::class, 'generalSettings' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );
	}

	private function rate(): Response {
		return $this->executeHandler(
			new ApiKZChatbotRateAnswer(),
			new RequestData( [ 'method' => 'POST' ] ),
			[], [], [],
			[ 'thread_id' => 'thread', 'conversation_id' => 'turn', 'like' => true, 'text' => '' ]
		);
	}

	private function assertUnreachableAnswer( Response $response ): void {
		$this->assertSame( 503, $response->getStatusCode() );
		$this->assertSame( '30', $response->getHeaderLine( 'Retry-After' ) );
		$this->assertStringStartsWith( 'application/json', $response->getHeaderLine( 'Content-Type' ) );
		$data = json_decode( (string)$response->getBody(), true );
		$this->assertSame( 503, $data['httpCode'] ?? null );
		$this->assertSame( 'kzchatbot-rate-backend-unreachable', $data['errorKey'] ?? null );
		// The client reads messageTranslations.<lang>; the test formatter is qqx.
		$this->assertStringContainsString(
			'kzchatbot-rate-backend-unreachable',
			$data['messageTranslations']['qqx'] ?? ''
		);
	}

	public function testNoHttpAnswerIsA503WithJsonError() {
		// Status 0 is what MWHttpRequest reports when no HTTP answer arrived.
		$this->installMockHttp( $this->makeFakeHttpRequest( '', 0 ) );
		$this->assertUnreachableAnswer( $this->rate() );
	}

	public function testFailureLogCarriesNoUrl() {
		// An HTTP error message can carry the request URL, and this URL's query
		// string holds the reader's feedback text. The fixture is shaped like a
		// Guzzle error that quotes the URL; only the message keys may be logged.
		$url = 'http://backend.test/rating?thread_id=thread&text=private+feedback';
		$status = StatusValue::newGood( 0 );
		$status->fatal( 'http-curl-error', "cURL error 7: Failed to connect for $url" );
		$status->fatal( 'http-bad-status', 0, 'Error' );
		$this->installMockHttp( $this->makeFakeHttpRequest( '', $status ) );

		$logger = new TestLogger( true, null, true );
		$cached = new ReflectionProperty( KZChatbot::class, 'logger' );
		$cached->setAccessible( true );
		$cached->setValue( null, $logger );
		try {
			$this->assertSame( 503, $this->rate()->getStatusCode() );
		} finally {
			$cached->setValue( null, LoggerFactory::getInstance( 'KZChatbot' ) );
		}

		$buffer = $logger->getBuffer();
		$this->assertCount( 1, $buffer, 'The failure is logged once' );
		[ , , $context ] = $buffer[0];
		$logged = json_encode( $context );
		foreach ( [ 'backend.test', '/rating', 'thread_id', 'private' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $logged );
		}
		$this->assertSame( 0, $context['code'] ?? null );
		$this->assertSame( [ 'http-curl-error', 'http-bad-status' ], $context['errors'] ?? null );
	}

	public static function provideBackendStatuses(): array {
		return [
			'ok' => [ 200 ],
			'backend error' => [ 500 ],
		];
	}

	/**
	 * @dataProvider provideBackendStatuses
	 */
	public function testBackendStatusIsPassedThrough( int $code ) {
		$this->installMockHttp( $this->makeFakeHttpRequest( '', $code ) );
		$this->assertSame( $code, $this->rate()->getStatusCode() );
	}
}
