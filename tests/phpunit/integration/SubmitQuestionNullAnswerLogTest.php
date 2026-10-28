<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Integration;

use MediaWiki\Extension\KZChatbot\ApiKZChatbotSubmitQuestion;
use MediaWiki\Extension\KZChatbot\KZChatbot;
use MediaWiki\Extension\KZChatbot\Slugs;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use ReflectionProperty;
use TestLogger;

/**
 * When the RAG backend answers without an answer, the question route logs the
 * failure. A reader's question can carry personal details, so that log line
 * must not hold the question text (#94).
 *
 * @covers \MediaWiki\Extension\KZChatbot\ApiKZChatbotSubmitQuestion
 * @group Database
 */
class SubmitQuestionNullAnswerLogTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;
	use MockHttpTrait;

	private const UUID = '0123abcd-0123-4abc-8def-0123456789ab';

	/** Reader content a log must never hold. */
	private const QUESTION = 'My ID number is 987654321, am I entitled to a grant?';

	protected function setUp(): void {
		parent::setUp();
		$this->clearCaches();
		// BannedWord, on the question path, still reads through wfGetDB().
		$this->filterDeprecated( '/Use of wfGetDB was deprecated/' );
		$this->overrideConfigValue( 'KZChatbotLlmApiUrl', 'http://backend.test' );
		$this->overrideConfigValue( 'KZChatbotReplaceAnswerWhenNoLinks', false );
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'kzchatbot_settings' )
			->rows( [
				// Slugs::getSlugs() substitutes both limits into the slugs.
				[ 'kzcbs_name' => 'question_character_limit', 'kzcbs_value' => '500' ],
				[ 'kzcbs_name' => 'feedback_character_limit', 'kzcbs_value' => '500' ],
				[ 'kzcbs_name' => 'questions_daily_limit', 'kzcbs_value' => '10' ],
			] )
			->caller( __METHOD__ )
			->execute();
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'kzchatbot_users' )
			->row( [
				'kzcbu_uuid' => KZChatbot::rawUuidFromFormatted( self::UUID ),
				'kzcbu_ip_address' => inet_pton( '127.0.0.1' ),
				'kzcbu_cookie_expiry' => wfTimestamp( TS_MW, time() + 86400 ),
				'kzcbu_last_active' => wfTimestampNow(),
				'kzcbu_questions_last_active_day' => 0,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	protected function tearDown(): void {
		$this->clearCaches();
		parent::tearDown();
	}

	/**
	 * Slugs, the general settings and the logger are memoised in static
	 * properties, which survive between tests in the same PHP process.
	 */
	private function clearCaches(): void {
		foreach ( [ [ Slugs::class, 'slugsRaw' ], [ KZChatbot::class, 'generalSettings' ] ] as [ $class, $prop ] ) {
			$cache = new ReflectionProperty( $class, $prop );
			$cache->setAccessible( true );
			$cache->setValue( null, null );
		}
	}

	public function testNullAnswerLogCarriesNoQuestionText() {
		// A well-formed RAG response with no llm_answer: the null-answer path.
		$this->installMockHttp( $this->makeFakeHttpRequest( json_encode( [
			'docs' => [ [ 'title' => 'Some page', 'url' => 'https://example.test/Some_page' ] ],
			'conversation_id' => 'conv-1',
		] ) ) );

		$logger = new TestLogger( true, null, true );
		$cached = new ReflectionProperty( KZChatbot::class, 'logger' );
		$cached->setAccessible( true );
		$cached->setValue( null, $logger );
		try {
			$e = $this->executeHandlerAndGetHttpException(
				new ApiKZChatbotSubmitQuestion(),
				new RequestData( [
					'method' => 'POST',
					'headers' => [ 'Content-Type' => 'application/json' ],
					'bodyContents' => json_encode( [
						'query' => self::QUESTION,
						'uuid' => self::UUID,
						'referrer' => 0,
					] ),
				] )
			);
		} finally {
			$cached->setValue( null, LoggerFactory::getInstance( 'KZChatbot' ) );
		}
		$this->assertSame( 500, $e->getCode() );

		$buffer = $logger->getBuffer();
		$this->assertCount( 1, $buffer, 'The null answer is logged once' );
		[ , $message, $context ] = $buffer[0];
		$logged = $message . json_encode( $context, JSON_UNESCAPED_UNICODE );
		foreach ( [ self::QUESTION, '987654321', 'entitled' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $logged );
		}
		// What is logged instead is enough to find the exchange.
		$this->assertSame( mb_strlen( self::QUESTION ), $context['question_length'] ?? null );
		$this->assertSame( 'conv-1', $context['conversation_id'] ?? null );
		$this->assertStringStartsWith( self::UUID . ':', $context['thread_id'] ?? '' );
	}
}
