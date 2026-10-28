<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Integration;

use MediaWiki\Extension\KZChatbot\ApiKZChatbotRateAnswer;
use MediaWiki\Extension\KZChatbot\KZChatbot;
use MediaWiki\Rest\RequestData;
use MediaWiki\Rest\Response;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use ReflectionProperty;

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
