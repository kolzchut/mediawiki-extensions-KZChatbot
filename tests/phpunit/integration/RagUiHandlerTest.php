<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Integration;

use MediaWiki\Extension\KZChatbot\Rest\RagUiHandler;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;

/**
 * The page-ID lookup the single tester and the batch runner make in the wiki's
 * action API is relayed through the embedding page, because the sandboxed frame
 * cannot make it itself (kolzchut/kz-mediawiki-main#148). Only those two
 * interfaces may have it relayed: the frame element says so to the relay, and
 * KZ_PROXY.wikiApi tells the shim which URL to relay.
 *
 * @covers \MediaWiki\Extension\KZChatbot\Rest\RagUiHandler
 */
class RagUiHandlerTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	/** What the backend HTML hard-codes; the handler rewrites it to the local API. */
	private const PROD_API = 'https://www.kolzchut.org.il/w/he/api.php';

	/** @var string Directory standing in for the RAG backend */
	private string $backendDir;

	protected function setUp(): void {
		parent::setUp();
		$this->backendDir = $this->getNewTempDirectory();
		$html = '<!DOCTYPE html><html><head><title>stub</title></head><body><script>'
			. "const WikiAPI = { baseUrl: '" . self::PROD_API . "' };"
			. '</script></body></html>';
		foreach ( [ 'admin.html', 'index.html', 'batch-runner.html' ] as $file ) {
			file_put_contents( $this->backendDir . '/' . $file, $html );
		}
		$this->overrideConfigValues( [
			'KZChatbotLlmApiUrl' => 'file://' . $this->backendDir . '/',
			'ScriptPath' => '/w/he',
		] );
	}

	public static function provideInterfaces(): array {
		return [
			'admin' => [ 'admin', 'kzchatbot-view-rag-settings', false ],
			'single tester' => [ 'index', 'kzchatbot-testing', true ],
			'batch runner' => [ 'batch', 'kzchatbot-testing', true ],
		];
	}

	/**
	 * @dataProvider provideInterfaces
	 */
	public function testIframeFlagsPageLookupOnlyWhereUsed( string $iface, string $right, bool $lookup ) {
		$html = RagUiHandler::buildIframe( $iface, '/w/he/rest.php', 'title' );
		$this->assertSame(
			$lookup,
			str_contains( $html, 'data-kzchatbot-page-lookup="1"' ),
			$html
		);
		$this->assertSame( $lookup, RagUiHandler::INTERFACES[$iface]['pageLookup'] );
	}

	/**
	 * @dataProvider provideInterfaces
	 */
	public function testShimIsToldWhichWikiApiUrlToRelay( string $iface, string $right, bool $lookup ) {
		$this->setGroupPermissions( '*', $right, true );

		$response = $this->executeHandler(
			new RagUiHandler(),
			new RequestData(),
			[ 'interface' => $iface, 'viewRight' => $right ]
		);
		$this->assertSame( 200, $response->getStatusCode() );
		$body = (string)$response->getBody();

		$this->assertStringNotContainsString( self::PROD_API, $body );
		$this->assertStringContainsString( "baseUrl: '/w/he/api.php'", $body );
		$this->assertStringContainsString(
			$lookup ? '"wikiApi":"/w/he/api.php"' : '"wikiApi":null',
			$body
		);
		// The shim relays that URL as its own message kind, never as a RAG path.
		$this->assertStringContainsString( "kzRag: 'pageLookup'", $body );
	}
}
