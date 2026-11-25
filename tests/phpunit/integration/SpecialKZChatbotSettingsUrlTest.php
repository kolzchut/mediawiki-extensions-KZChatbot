<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Integration;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\KZChatbot\Hooks;
use MediaWiki\Extension\KZChatbot\KZChatbot;
use MediaWiki\Extension\KZChatbot\Slugs;
use MediaWiki\Extension\KZChatbot\SpecialKZChatbotSettings;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Session\CsrfTokenSet;
use ReflectionProperty;
use SpecialPageTestBase;

/**
 * The terms-of-service and usage-help URLs are rendered as links on every
 * page that shows the chatbot. The form's 'url' field type is checked only in
 * the browser, so the server has to refuse a URL that is not http(s) itself,
 * and a value already stored that is not one must not reach the page.
 *
 * @covers \MediaWiki\Extension\KZChatbot\SpecialKZChatbotSettings
 * @covers \MediaWiki\Extension\KZChatbot\Hooks::onResourceLoaderGetConfigVars
 * @group Database
 */
class SpecialKZChatbotSettingsUrlTest extends SpecialPageTestBase {

	private const GOOD_TERMS = 'https://www.kolzchut.org.il/he/terms';

	private const GOOD_HELP = 'https://www.kolzchut.org.il/he/help';

	protected function setUp(): void {
		parent::setUp();
		$this->clearCaches();
	}

	protected function tearDown(): void {
		$this->clearCaches();
		parent::tearDown();
	}

	protected function newSpecialPage() {
		return new SpecialKZChatbotSettings();
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

	private function storedSetting( string $name ): ?string {
		$value = $this->getDb()->newSelectQueryBuilder()
			->select( 'kzcbs_value' )
			->from( 'kzchatbot_settings' )
			->where( [ 'kzcbs_name' => $name ] )
			->caller( __METHOD__ )
			->fetchField();
		return $value === false ? null : $value;
	}

	/**
	 * A complete, otherwise valid submission of the settings form by a
	 * chatbot admin, with a real CSRF token.
	 *
	 * @param string $termsUrl
	 * @param string $helpUrl
	 * @return FauxRequest
	 */
	private function submission( string $termsUrl, string $helpUrl ): FauxRequest {
		$admin = $this->getTestUser( [ 'chatbot-admin' ] )->getUser();
		$request = new FauxRequest( [
			'wpFormIdentifier' => 'KZChatbotSettingsForm',
			'wpkzcNewUsersChatbotRate' => '50',
			'wpkzcActiveUsersLimit' => '',
			'wpkzcActiveUsersLimitDays' => '30',
			'wpkzcQuestionsDailyLimit' => '10',
			'wpkzcQuestionCharacterLimit' => '150',
			'wpkzcFeedbackCharacterLimit' => '150',
			'wpkzcCookieExpiryDays' => '365',
			'wpkzcUUIDRequestLimit' => '20',
			'wpkzcTermsOfServiceUrl' => $termsUrl,
			'wpkzcUsageHelpUrl' => $helpUrl,
			'wpkzcMaxQuestionsPerConversation' => '8',
			'wpkzcConversationSessionTtlHours' => '24',
			'wpkzcEnableCrossTabSync' => '1',
			'wpkzcHistoryStorageMode' => 'local',
		], true, [] );
		// The token is checked against the session's user, so the session gets
		// the admin before the token is minted from it.
		$request->getSession()->setUser( $admin );
		$request->setVal( 'wpEditToken', ( new CsrfTokenSet( $request ) )->getToken()->toString() );
		$this->setRequest( $request );
		return $request;
	}

	private function submit( FauxRequest $request ): string {
		$admin = $this->getTestUser( [ 'chatbot-admin' ] )->getUser();
		[ $html ] = $this->executeSpecialPage( '', $request, 'qqx', $admin );
		return $html;
	}

	/**
	 * The control: a valid submission is saved, so a refusal below is the
	 * URL check and not a broken fixture.
	 */
	public function testHttpsUrlsAreSaved() {
		$request = $this->submission( self::GOOD_TERMS, self::GOOD_HELP );

		$this->submit( $request );

		$this->assertSame( self::GOOD_TERMS, $this->storedSetting( 'terms_of_service_url' ) );
		$this->assertSame( self::GOOD_HELP, $this->storedSetting( 'usage_help_url' ) );
		$this->assertSame( 1, $request->getSession()->get( 'kzChatbotSettingsSaveSuccess' ) );
	}

	public static function provideRefusedUrls(): iterable {
		yield 'javascript' => [ 'javascript:alert(document.cookie)' ];
		yield 'data' => [ 'data:text/html,<b>x</b>' ];
		yield 'protocol-relative' => [ '//example.org/terms' ];
		yield 'relative' => [ '/he/terms' ];
	}

	/**
	 * @dataProvider provideRefusedUrls
	 */
	public function testTermsOfServiceUrlThatIsNotHttpIsRefused( string $url ) {
		$request = $this->submission( $url, self::GOOD_HELP );

		$html = $this->submit( $request );

		$this->assertNull( $this->storedSetting( 'terms_of_service_url' ), 'Nothing was saved' );
		$this->assertNull( $request->getSession()->get( 'kzChatbotSettingsSaveSuccess' ) );
		$this->assertStringContainsString( 'kzchatbot-settings-error-url-not-http', $html );
	}

	/**
	 * @dataProvider provideRefusedUrls
	 */
	public function testUsageHelpUrlThatIsNotHttpIsRefused( string $url ) {
		$request = $this->submission( self::GOOD_TERMS, $url );

		$html = $this->submit( $request );

		$this->assertNull( $this->storedSetting( 'usage_help_url' ), 'Nothing was saved' );
		$this->assertStringContainsString( 'kzchatbot-settings-error-url-not-http', $html );
	}

	/**
	 * A value stored before the form checked the scheme, or written to the
	 * table directly, is not sent to the page.
	 */
	public function testStoredUrlThatIsNotHttpIsNotSentToThePage() {
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'kzchatbot_settings' )
			->rows( [
				[ 'kzcbs_name' => 'terms_of_service_url', 'kzcbs_value' => 'javascript:alert(1)' ],
				[ 'kzcbs_name' => 'usage_help_url', 'kzcbs_value' => self::GOOD_HELP ],
			] )
			->caller( __METHOD__ )
			->execute();
		$this->clearCaches();

		$vars = [];
		( new Hooks() )->onResourceLoaderGetConfigVars(
			$vars, 'vector', new HashConfig( [ 'KZChatbotAutoOpenParam' => 'chatbot' ] )
		);

		$this->assertNull( $vars['KZChatbotSettings']['termsofServiceUrl'] );
		$this->assertSame( self::GOOD_HELP, $vars['KZChatbotSettings']['usageHelpUrl'] );
	}
}
