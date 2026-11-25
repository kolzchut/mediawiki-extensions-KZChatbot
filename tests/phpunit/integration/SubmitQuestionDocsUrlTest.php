<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Integration;

use MediaWiki\Extension\KZChatbot\ApiKZChatbotSubmitQuestion;
use MediaWiki\Extension\KZChatbot\KZChatbot;
use MediaWiki\Extension\KZChatbot\Slugs;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use ReflectionProperty;

/**
 * The answer's source documents are rendered as links, so a document whose
 * url from the RAG backend is not an absolute http(s) URL is dropped before
 * the answer is returned.
 *
 * @covers \MediaWiki\Extension\KZChatbot\ApiKZChatbotSubmitQuestion
 * @group Database
 */
class SubmitQuestionDocsUrlTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;
	use MockHttpTrait;

	private const UUID = '0123abcd-0123-4abc-8def-0123456789ab';

	protected function setUp(): void {
		parent::setUp();
		$this->clearCaches();
		// BannedWord, on the question path, still reads through wfGetDB().
		$this->filterDeprecated( '/Use of wfGetDB was deprecated/' );
		$this->overrideConfigValue( 'KZChatbotLlmApiUrl', 'http://backend.test' );
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'kzchatbot_settings' )
			->rows( [
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

	private function ask( array $backendDocs ): array {
		$this->installMockHttp( $this->makeFakeHttpRequest( json_encode( [
			'llm_answer' => 'An answer.',
			'docs' => $backendDocs,
			'conversation_id' => 'conv-1',
		] ) ) );
		return $this->executeHandlerAndGetBodyData(
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
	}

	public function testDocsWithoutAnHttpUrlAreDropped() {
		$this->overrideConfigValue( 'KZChatbotReplaceAnswerWhenNoLinks', false );

		$data = $this->ask( [
			[ 'title' => 'Script', 'url' => 'javascript:alert(document.cookie)' ],
			[ 'title' => 'Web page', 'url' => 'https://www.kolzchut.org.il/he/Page' ],
			[ 'title' => 'Data', 'url' => 'data:text/html,x' ],
			[ 'title' => 'No url' ],
		] );

		$this->assertSame(
			[ [ 'title' => 'Web page', 'url' => 'https://www.kolzchut.org.il/he/Page' ] ],
			$data['docs']
		);
		$this->assertSame( 'An answer.', $data['llmResult'] );
	}

	/**
	 * With every link dropped the answer has no links, and the
	 * replace-when-no-links setting treats it so.
	 */
	public function testAnswerWithOnlyUnsafeLinksCountsAsHavingNoLinks() {
		$this->overrideConfigValue( 'KZChatbotReplaceAnswerWhenNoLinks', true );

		$data = $this->ask( [ [ 'title' => 'Script', 'url' => 'javascript:alert(1)' ] ] );

		$this->assertSame( [], $data['docs'] );
		$this->assertSame( Slugs::getSlug( 'returning_links_empty' ), $data['llmResult'] );
	}
}
