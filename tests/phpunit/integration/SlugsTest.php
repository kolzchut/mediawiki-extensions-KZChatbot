<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Integration;

use MediaWiki\Extension\KZChatbot\Slugs;
use MediaWikiIntegrationTestCase;
use ReflectionProperty;

/**
 * @covers \MediaWiki\Extension\KZChatbot\Slugs
 * @group Database
 */
class SlugsTest extends MediaWikiIntegrationTestCase {

	/**
	 * A slug that is deliberately not one of the extension's defaults, standing
	 * in for a key that was renamed or retired while its override row stayed
	 * behind. Guarded by assertions below so it cannot silently become real.
	 */
	private const RETIRED_SLUG = 'retired_slug_for_testing';

	/** A key that is a current default, for the ordinary override case. */
	private const LIVE_SLUG = 'send_button';

	protected function setUp(): void {
		parent::setUp();
		$this->clearSlugCache();
	}

	protected function tearDown(): void {
		$this->clearSlugCache();
		parent::tearDown();
	}

	/**
	 * Slugs memoises the merged slug list in a static property, which survives
	 * between tests in the same PHP process and would otherwise let one test
	 * see another's slugs.
	 */
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

	private function storedSlugs(): array {
		return array_keys( Slugs::getSlugsFromDB() );
	}

	public function testRetiredSlugIsNotADefault() {
		$this->assertFalse(
			Slugs::isValidSlugName( self::RETIRED_SLUG ),
			'The fixture slug must not be one of the defaults, or these tests prove nothing'
		);
		$this->assertTrue( Slugs::isValidSlugName( self::LIVE_SLUG ) );
	}

	/**
	 * The production failure: an override row whose key is no longer a default
	 * used to throw before reaching the delete, leaving the row in place.
	 */
	public function testDeleteRemovesRowWhoseSlugIsNoLongerADefault() {
		$this->insertOverride( self::RETIRED_SLUG, 'text left behind by a rename' );
		$this->assertContains( self::RETIRED_SLUG, $this->storedSlugs() );

		$status = Slugs::deleteSlug( self::RETIRED_SLUG );

		$this->assertStatusGood( $status );
		$this->assertNotContains( self::RETIRED_SLUG, $this->storedSlugs() );
	}

	/**
	 * A slug that is neither a default nor stored is refused — but as a Status,
	 * not an exception, so the caller can report it instead of fatalling.
	 */
	public function testDeleteRefusesSlugThatIsNeitherDefaultNorStored() {
		$status = Slugs::deleteSlug( 'no_such_slug_anywhere' );

		$this->assertStatusNotOK( $status );
		$this->assertSame( 'invalid slug name', $status->getMessage()->plain() );
	}

	public function testDeleteRemovesOverrideOfACurrentDefault() {
		$this->insertOverride( self::LIVE_SLUG, 'a customised label' );

		$status = Slugs::deleteSlug( self::LIVE_SLUG );

		$this->assertStatusGood( $status );
		$this->assertNotContains( self::LIVE_SLUG, $this->storedSlugs() );
	}

	public function testDeleteReportsTheSlugItRemoved() {
		$this->insertOverride( self::RETIRED_SLUG, 'text left behind by a rename' );

		$status = Slugs::deleteSlug( self::RETIRED_SLUG );

		$this->assertSame(
			[ 'kzchatbot-slugs-status-delete-success', self::RETIRED_SLUG ],
			$status->getValue()
		);
	}

	public function testSaveStoresAnOverrideForACurrentDefault() {
		$status = Slugs::saveSlug( self::LIVE_SLUG, 'a customised label' );

		$this->assertStatusGood( $status );
		$this->assertContains( self::LIVE_SLUG, $this->storedSlugs() );
		$this->assertSame( 'a customised label', Slugs::getSlugRaw( self::LIVE_SLUG ) );
	}

	/**
	 * Saving text identical to the default drops the row rather than storing a
	 * redundant copy, and says so with its own message.
	 */
	public function testSaveWithDefaultTextRemovesTheOverrideInstead() {
		$default = Slugs::getDefaultSlugs()[self::LIVE_SLUG];
		$this->insertOverride( self::LIVE_SLUG, 'a customised label' );

		$status = Slugs::saveSlug( self::LIVE_SLUG, $default );

		$this->assertStatusGood( $status );
		$this->assertSame(
			[ 'kzchatbot-slugs-status-reset-success', self::LIVE_SLUG ],
			$status->getValue()
		);
		$this->assertNotContains( self::LIVE_SLUG, $this->storedSlugs() );
	}

	public function testSaveRefusesASlugThatIsNotADefault() {
		$status = Slugs::saveSlug( self::RETIRED_SLUG, 'should not be stored' );

		$this->assertStatusNotOK( $status );
		$this->assertNotContains( self::RETIRED_SLUG, $this->storedSlugs() );
	}

	/**
	 * The memo cache must not outlive the row it was built from, or the admin
	 * would keep being served text they just deleted.
	 */
	public function testDeleteInvalidatesTheMemoisedSlugs() {
		$default = Slugs::getDefaultSlugs()[self::LIVE_SLUG];
		$this->insertOverride( self::LIVE_SLUG, 'a customised label' );

		// Populate the cache through the memoised path.
		$this->assertSame( 'a customised label', Slugs::getSlugRaw( self::LIVE_SLUG ) );

		Slugs::deleteSlug( self::LIVE_SLUG );

		$this->assertSame( $default, Slugs::getSlugRaw( self::LIVE_SLUG ) );
	}

	public function testSaveRefreshesTheMemoisedSlugs() {
		// Populate the cache before the write, so a stale entry would show up.
		Slugs::getSlugRaw( self::LIVE_SLUG );

		Slugs::saveSlug( self::LIVE_SLUG, 'a customised label' );

		$this->assertSame( 'a customised label', Slugs::getSlugRaw( self::LIVE_SLUG ) );
	}

	/**
	 * Overrides win over defaults, and defaults fill every gap.
	 */
	public function testRawSlugsMergeDefaultsWithOverrides() {
		$this->insertOverride( self::LIVE_SLUG, 'a customised label' );

		$raw = Slugs::getSlugsRaw();

		$this->assertSame( 'a customised label', $raw[self::LIVE_SLUG] );
		foreach ( array_keys( Slugs::getDefaultSlugs() ) as $name ) {
			$this->assertArrayHasKey( $name, $raw );
		}
	}
}
