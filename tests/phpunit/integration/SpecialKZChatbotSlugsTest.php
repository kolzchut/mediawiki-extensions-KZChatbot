<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Integration;

use ErrorPageError;
use MediaWiki\Extension\KZChatbot\Slugs;
use MediaWiki\Extension\KZChatbot\SpecialKZChatbotSlugs;
use MediaWiki\Permissions\Authority;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Session\CsrfTokenSet;
use ReflectionProperty;
use SpecialPageTestBase;

/**
 * @covers \MediaWiki\Extension\KZChatbot\SpecialKZChatbotSlugs
 * @group Database
 */
class SpecialKZChatbotSlugsTest extends SpecialPageTestBase {

	/** Stands in for a key that was renamed or retired, leaving its row behind. */
	private const RETIRED_SLUG = 'retired_slug_for_testing';

	/** A key that is a current default. */
	private const LIVE_SLUG = 'send_button';

	protected function setUp(): void {
		parent::setUp();
		$this->clearSlugCache();
	}

	protected function tearDown(): void {
		$this->clearSlugCache();
		parent::tearDown();
	}

	protected function newSpecialPage() {
		return new SpecialKZChatbotSlugs();
	}

	private function clearSlugCache(): void {
		$cache = new ReflectionProperty( Slugs::class, 'slugsRaw' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );
	}

	private function insertOverride( string $slug, string $text ): void {
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'kzchatbot_text' )
			->row( [ 'kzcbt_slug' => $slug, 'kzcbt_text' => $text ] )
			->caller( __METHOD__ )
			->execute();
		$this->clearSlugCache();
	}

	private function storedText( string $slug ): ?string {
		$this->clearSlugCache();
		return Slugs::getSlugsFromDB()[$slug] ?? null;
	}

	/**
	 * An admin allowed to manage the chatbot's texts. A real user in the real
	 * group, because SpecialPage::userCanExecute() resolves rights through
	 * PermissionManager from a User rather than from the context Authority — a
	 * mock authority's permission list would never be consulted.
	 */
	private function admin(): Authority {
		return $this->getTestUser( [ 'chatbot-admin' ] )->getUser();
	}

	private function executeAsAdmin( FauxRequest $request ): array {
		return $this->executeSpecialPage( '', $request, 'qqx', $this->admin() );
	}

	public function testRetiredSlugIsNotADefault() {
		$this->assertFalse(
			Slugs::isValidSlugName( self::RETIRED_SLUG ),
			'The fixture slug must not be one of the defaults, or these tests prove nothing'
		);
	}

	public function testObsoleteRowIsMarkedInTheTable() {
		$this->insertOverride( self::RETIRED_SLUG, 'text left behind by a rename' );

		[ $html ] = $this->executeAsAdmin( new FauxRequest() );

		$this->assertStringContainsString( 'obsolete-value', $html );
		$this->assertStringContainsString( 'kzchatbot-slugs-obsolete', $html );
	}

	public function testObsoleteRowOffersDeleteButNotEdit() {
		$this->insertOverride( self::RETIRED_SLUG, 'text left behind by a rename' );

		[ $html ] = $this->executeAsAdmin( new FauxRequest() );

		$this->assertStringContainsString( 'delete=' . self::RETIRED_SLUG, $html );
		$this->assertStringNotContainsString( 'edit=' . self::RETIRED_SLUG, $html );
	}

	public function testCurrentDefaultKeepsItsEditLink() {
		[ $html ] = $this->executeAsAdmin( new FauxRequest() );

		$this->assertStringContainsString( 'edit=' . self::LIVE_SLUG, $html );
	}

	/**
	 * The edit link is omitted for obsolete rows, but the URL stays reachable,
	 * so the page itself has to refuse.
	 */
	public function testEditFormIsRefusedForAnObsoleteSlug() {
		$this->insertOverride( self::RETIRED_SLUG, 'text left behind by a rename' );
		$request = new FauxRequest( [ 'edit' => self::RETIRED_SLUG ], false, [] );

		[ $html, $response ] = $this->executeAsAdmin( $request );

		$this->assertNotSame( '', (string)$response->getHeader( 'Location' ) );
		$this->assertStringNotContainsString( 'KZChatbotSlugForm', $html );
		$this->assertStringContainsString(
			'kzchatbot-slugs-error-obsolete',
			(string)$request->getSession()->get( 'kzSlugError' )
		);
	}

	public function testEditFormStillOpensForACurrentDefault() {
		$request = new FauxRequest( [ 'edit' => self::LIVE_SLUG ], false, [] );

		[ $html ] = $this->executeAsAdmin( $request );

		$this->assertStringContainsString( 'KZChatbotSlugForm', $html );
	}

	/**
	 * A submission aimed at an obsolete slug must not reach the database.
	 *
	 * Note this invariant does not depend on the page's own guard:
	 * Slugs::saveSlug() refuses a name that is not a current default, and the
	 * kzcSlug field's validation-callback refuses it before that. The guard
	 * adds the explanation, not the protection — see the test below.
	 */
	public function testEditSubmissionNeverReachesTheDatabaseForAnObsoleteSlug() {
		$this->insertOverride( self::RETIRED_SLUG, 'original text' );

		$this->executeAsAdmin( $this->obsoleteEditSubmission() );

		$this->assertSame( 'original text', $this->storedText( self::RETIRED_SLUG ) );
	}

	/**
	 * Without the page's guard a submission aimed at an obsolete slug fails
	 * silently: field validation rejects it before the submit callback runs, so
	 * nothing is stored in the session and the admin is returned to an
	 * unchanged table with no explanation at all.
	 */
	public function testEditSubmissionForAnObsoleteSlugExplainsItself() {
		$this->insertOverride( self::RETIRED_SLUG, 'original text' );
		$request = $this->obsoleteEditSubmission();

		$this->executeAsAdmin( $request );

		$this->assertStringContainsString(
			'kzchatbot-slugs-error-obsolete',
			(string)$request->getSession()->get( 'kzSlugError' )
		);
	}

	/**
	 * A submission carrying no slug name is a missing required field, which the
	 * form reports for itself. It must not be explained as an obsolete slug —
	 * the two sources an edit can arrive from have to normalise the same way.
	 */
	public function testBlankSlugIsNotReportedAsObsolete() {
		$request = new FauxRequest( [
			'wpkzcAction' => 'edit',
			'wpkzcSlug' => '',
			'wpkzcText' => 'whatever',
			'wpFormIdentifier' => 'KZChatbotSlugForm',
		], true, [] );

		$this->executeAsAdmin( $request );

		$this->assertNull( $request->getSession()->get( 'kzSlugError' ) );
	}

	private function obsoleteEditSubmission(): FauxRequest {
		return new FauxRequest( [
			'wpkzcAction' => 'edit',
			'wpkzcSlug' => self::RETIRED_SLUG,
			'wpkzcText' => 'text from a hand-made submission',
			'wpFormIdentifier' => 'KZChatbotSlugForm',
		], true, [] );
	}

	/**
	 * The save stores its message in the session and redirects. If the same
	 * request goes on to read that message it clears it, and the page the
	 * browser actually lands on has nothing left to show.
	 */
	public function testSaveLeavesItsConfirmationForTheRedirectedPage() {
		$request = new FauxRequest( [
			'wpkzcAction' => 'edit',
			'wpkzcSlug' => self::LIVE_SLUG,
			'wpkzcText' => 'a customised label',
			'wpFormIdentifier' => 'KZChatbotSlugForm',
		], true, [] );

		$this->executeAsAdmin( $request );

		$this->assertSame(
			[ 'kzchatbot-slugs-status-save-success', self::LIVE_SLUG ],
			$request->getSession()->get( 'kzSlugStatus' ),
			'The confirmation must still be in the session for the redirected request'
		);
		$this->assertSame( 'a customised label', $this->storedText( self::LIVE_SLUG ) );
	}

	/**
	 * Deleting used to be a fatal for exactly the rows that needed deleting.
	 */
	public function testDeletingAnObsoleteRowSucceedsAndReportsItself() {
		$this->insertOverride( self::RETIRED_SLUG, 'text left behind by a rename' );
		$request = $this->deleteRequest( self::RETIRED_SLUG );

		$this->executeAsAdmin( $request );

		$this->assertNull( $this->storedText( self::RETIRED_SLUG ) );
		$this->assertSame(
			self::RETIRED_SLUG,
			$request->getSession()->get( 'kzSlugDeleted' )
		);
	}

	/**
	 * Deleting is a state change reached by following a link, so it arrives as a
	 * GET. Without a token, any page an admin visits could delete their slugs by
	 * embedding the URL.
	 */
	public function testDeletingWithoutATokenIsRefused() {
		$this->insertOverride( self::RETIRED_SLUG, 'text left behind by a rename' );
		$request = new FauxRequest( [ 'delete' => self::RETIRED_SLUG ], false, [] );

		$this->expectException( ErrorPageError::class );
		try {
			$this->executeAsAdmin( $request );
		} finally {
			$this->assertSame(
				'text left behind by a rename',
				$this->storedText( self::RETIRED_SLUG ),
				'An untokened delete must not reach the database'
			);
		}
	}

	public function testDeletingWithSomeoneElsesTokenIsRefused() {
		$this->insertOverride( self::RETIRED_SLUG, 'text left behind by a rename' );
		$request = new FauxRequest(
			[ 'delete' => self::RETIRED_SLUG, 'token' => 'not-the-right-token+\\' ],
			false,
			[]
		);

		$this->expectException( ErrorPageError::class );
		try {
			$this->executeAsAdmin( $request );
		} finally {
			$this->assertSame(
				'text left behind by a rename',
				$this->storedText( self::RETIRED_SLUG )
			);
		}
	}

	public function testDeleteLinksCarryAToken() {
		$this->insertOverride( self::RETIRED_SLUG, 'text left behind by a rename' );

		[ $html ] = $this->executeAsAdmin( new FauxRequest() );

		$this->assertMatchesRegularExpression(
			'/delete=' . preg_quote( self::RETIRED_SLUG, '/' ) . '&(amp;)?token=/',
			$html
		);
	}

	/**
	 * A delete request carrying the token the page would have put in the link.
	 * The token comes from the session, so it has to be minted before the
	 * request that will present it.
	 */
	private function deleteRequest( string $slug ): FauxRequest {
		$request = new FauxRequest( [ 'delete' => $slug ], false, [] );

		// The token has to be minted the way the page will verify it.
		// CsrfTokenSet short-circuits to the constant logged-out token unless the
		// *session's* user is registered — setting the performer on the context is
		// not enough — so the session gets a real user before the token is taken.
		$request->getSession()->setUser( $this->admin() );
		$request->setVal(
			'token',
			( new CsrfTokenSet( $request ) )->getToken()->toString()
		);
		return $request;
	}
}
