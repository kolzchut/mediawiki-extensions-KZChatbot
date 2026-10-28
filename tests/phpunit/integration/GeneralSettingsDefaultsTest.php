<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Integration;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\KZChatbot\ApiKZChatbotRateAnswer;
use MediaWiki\Extension\KZChatbot\ApiKZChatbotSubmitQuestion;
use MediaWiki\Extension\KZChatbot\Hooks;
use MediaWiki\Extension\KZChatbot\KZChatbot;
use MediaWiki\Extension\KZChatbot\Slugs;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use ReflectionProperty;

/**
 * The two character limits used to be read with no fallback. With no settings
 * row for them (a fresh install, or a table never saved through
 * Special:KZChatbotSettings), each read raised an undefined-key warning, and
 * each length check compared against null, which refuses any non-empty text
 * (#93). Both now fall back to 150, the React client's own fallback.
 *
 * @covers \MediaWiki\Extension\KZChatbot\KZChatbot::getGeneralSettings
 * @covers \MediaWiki\Extension\KZChatbot\ApiKZChatbotRateAnswer
 * @covers \MediaWiki\Extension\KZChatbot\ApiKZChatbotSubmitQuestion
 * @covers \MediaWiki\Extension\KZChatbot\Hooks::onResourceLoaderGetConfigVars
 * @group Database
 */
class GeneralSettingsDefaultsTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;
	use MockHttpTrait;

	private const UUID = '0123abcd-0123-4abc-8def-0123456789ab';

	private const DEFAULT_LIMIT = 150;

	protected function setUp(): void {
		parent::setUp();
		$this->clearCaches();
		// BannedWord, on the question path, still reads through wfGetDB().
		$this->filterDeprecated( '/Use of wfGetDB was deprecated/' );
		$this->overrideConfigValue( 'KZChatbotLlmApiUrl', 'http://backend.test' );
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

	private function insertSetting( string $name, string $value ): void {
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'kzchatbot_settings' )
			->row( [ 'kzcbs_name' => $name, 'kzcbs_value' => $value ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * A real JSON body rather than pre-validated parameters: validate() checks
	 * the length only once the body has been validated.
	 */
	private function rateRequest( string $text ): RequestData {
		return new RequestData( [
			'method' => 'POST',
			'headers' => [ 'Content-Type' => 'application/json' ],
			'bodyContents' => json_encode( [
				'thread_id' => 'thread', 'conversation_id' => 'turn', 'like' => true, 'text' => $text,
			] ),
		] );
	}

	public function testMissingLimitsFallBackToTheDefault() {
		$settings = KZChatbot::getGeneralSettings();

		$this->assertSame( self::DEFAULT_LIMIT, $settings['feedback_character_limit'] ?? null );
		$this->assertSame( self::DEFAULT_LIMIT, $settings['question_character_limit'] ?? null );
	}

	public function testStoredLimitOverridesTheDefault() {
		$this->insertSetting( 'feedback_character_limit', '20' );

		$this->assertSame( '20', KZChatbot::getGeneralSettings()['feedback_character_limit'] );
	}

	public function testRatingWithinTheDefaultLimitIsSentWithNoLimitRow() {
		$this->installMockHttp( $this->makeFakeHttpRequest( '', 200 ) );

		$response = $this->executeHandler(
			new ApiKZChatbotRateAnswer(), $this->rateRequest( str_repeat( 'x', self::DEFAULT_LIMIT ) )
		);

		$this->assertSame( 200, $response->getStatusCode() );
	}

	public function testRatingOverTheDefaultLimitIsRefusedWithNoLimitRow() {
		// Any call to the RAG backend fails the test.
		$this->installMockHttp();

		$e = $this->executeHandlerAndGetHttpException(
			new ApiKZChatbotRateAnswer(), $this->rateRequest( str_repeat( 'x', self::DEFAULT_LIMIT + 1 ) )
		);

		$this->assertSame( 400, $e->getCode() );
	}

	public function testQuestionIsAnsweredWithNoLimitRow() {
		$this->insertSetting( 'questions_daily_limit', '10' );
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
		$this->installMockHttp( $this->makeFakeHttpRequest(
			json_encode( [ 'llm_answer' => 'An answer', 'docs' => [] ] )
		) );

		$data = $this->executeHandlerAndGetBodyData(
			new ApiKZChatbotSubmitQuestion(),
			new RequestData( [
				'method' => 'POST',
				'headers' => [ 'Content-Type' => 'application/json' ],
				'bodyContents' => json_encode( [
					'query' => 'What am I entitled to?',
					'uuid' => self::UUID,
					'referrer' => 0,
				] ),
			] )
		);

		$this->assertSame( 'An answer', $data['llmResult'] );
	}

	public function testConfigVarsCarryTheDefaultWithNoLimitRows() {
		$vars = [];
		( new Hooks() )->onResourceLoaderGetConfigVars(
			$vars, 'vector', new HashConfig( [ 'KZChatbotAutoOpenParam' => 'chatbot' ] )
		);

		$this->assertSame( self::DEFAULT_LIMIT, $vars['KZChatbotSettings']['feedbackCharacterLimit'] );
		$this->assertSame( self::DEFAULT_LIMIT, $vars['KZChatbotSettings']['questionCharacterLimit'] );
		// The limit is substituted into the slugs the client shows.
		$this->assertStringContainsString(
			(string)self::DEFAULT_LIMIT, $vars['KZChatbotSlugs']['feedback_character_limit']
		);
		$this->assertStringContainsString(
			(string)self::DEFAULT_LIMIT, $vars['KZChatbotSlugs']['question_character_limit']
		);
	}
}
