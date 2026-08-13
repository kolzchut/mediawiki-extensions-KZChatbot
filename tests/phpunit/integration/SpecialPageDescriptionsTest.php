<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Integration;

use MediaWiki\Extension\KZChatbot\SpecialKZChatbotBannedWords;
use MediaWiki\Extension\KZChatbot\SpecialKZChatbotRagSettings;
use MediaWiki\Extension\KZChatbot\SpecialKZChatbotSettings;
use MediaWiki\Extension\KZChatbot\SpecialKZChatbotSlugs;
use MediaWiki\Extension\KZChatbot\SpecialKZChatbotTesting;
use MediaWiki\Message\Message;
use MediaWikiIntegrationTestCase;

/**
 * Returning a string from SpecialPage::getDescription() has been deprecated
 * since MediaWiki 1.41, and the deprecation is loud enough to abort any test
 * that executes the page. Covering every one of the extension's special pages
 * keeps a new page from reintroducing it.
 *
 * @covers \MediaWiki\Extension\KZChatbot\SpecialKZChatbotBannedWords
 * @covers \MediaWiki\Extension\KZChatbot\SpecialKZChatbotRagSettings
 * @covers \MediaWiki\Extension\KZChatbot\SpecialKZChatbotSettings
 * @covers \MediaWiki\Extension\KZChatbot\SpecialKZChatbotSlugs
 * @covers \MediaWiki\Extension\KZChatbot\SpecialKZChatbotTesting
 */
class SpecialPageDescriptionsTest extends MediaWikiIntegrationTestCase {

	public static function provideSpecialPages(): array {
		return [
			'banned words' => [ SpecialKZChatbotBannedWords::class ],
			'rag settings' => [ SpecialKZChatbotRagSettings::class ],
			'settings' => [ SpecialKZChatbotSettings::class ],
			'slugs' => [ SpecialKZChatbotSlugs::class ],
			'testing' => [ SpecialKZChatbotTesting::class ],
		];
	}

	/**
	 * @dataProvider provideSpecialPages
	 */
	public function testGetDescriptionReturnsAMessage( string $class ) {
		$page = new $class();

		$description = $page->getDescription();

		$this->assertInstanceOf( Message::class, $description );
		$this->assertNotSame( '', $description->text() );
	}
}
