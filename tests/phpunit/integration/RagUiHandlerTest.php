<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Integration;

use Closure;
use MediaWiki\Extension\KZChatbot\Rest\RagUiHandler;
use MediaWiki\Rest\RequestData;
use MediaWiki\Rest\ResponseInterface;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;

/**
 * The page-ID lookup the single tester and the batch runner make in the wiki's
 * action API is relayed through the embedding page, because the sandboxed frame
 * cannot make it itself (kolzchut/kz-mediawiki-main#148). Only those two
 * interfaces may have it relayed: the frame element says so to the relay, and
 * KZ_PROXY.wikiApi tells the shim which URL to relay.
 *
 * The frame cannot load the backend's JS, CSS or fonts as files either, so the
 * handler inlines them. The page fixtures under tests/phpunit/fixtures/ragui/ are
 * the real backend pages: master/ from kolzchut/chatbot master after PR
 * kolzchut/chatbot#19 (commit 4f363cd), which load prebuilt bundles from build/;
 * pre-pr19/ from its parent (eaed52a), which loaded CDN scripts compiled by
 * Babel standalone, and which the response's CSP now refuses.
 * fontawesome-6.7.2-all.min.css is the stylesheet that build vendors, from the
 * package @fortawesome/fontawesome-free 6.7.2 (MIT, see its header). The JS
 * bundles and fonts are stubs, since only their names and bytes matter here.
 *
 * @covers \MediaWiki\Extension\KZChatbot\Rest\RagUiHandler
 */
class RagUiHandlerTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	/** What the backend HTML hard-codes; the handler rewrites it to the local API. */
	private const PROD_API = 'https://www.kolzchut.org.il/w/he/api.php';

	private const FIXTURES = __DIR__ . '/../fixtures/ragui';

	/** The stylesheet path Font Awesome is vendored at, which the handler allowlists. */
	private const FA_CSS = 'build/vendor/fontawesome/css/all.min.css';

	/** @var string Directory standing in for the RAG backend's static root */
	private string $backendDir;

	/** @var string A directory just outside it, holding a file nothing may read */
	private string $outsideDir;

	/** @var string[] Backend paths the handler fetched, in order */
	private array $fetched = [];

	protected function setUp(): void {
		parent::setUp();
		$this->outsideDir = $this->getNewTempDirectory();
		$this->backendDir = $this->outsideDir . '/static';
		mkdir( $this->backendDir );
		file_put_contents( $this->outsideDir . '/secret.woff2', 'wOF2 OUTSIDE-THE-STATIC-ROOT' );
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

	// ---------------------------------------------------------------------
	// Inlining the backend's assets
	// ---------------------------------------------------------------------

	/**
	 * Write files into the stand-in backend, creating directories as needed.
	 *
	 * @param array<string,string> $files Path below the static root => contents
	 */
	private function serve( array $files ): void {
		foreach ( $files as $path => $contents ) {
			$full = $this->backendDir . '/' . $path;
			if ( !is_dir( dirname( $full ) ) ) {
				mkdir( dirname( $full ), 0777, true );
			}
			file_put_contents( $full, $contents );
		}
	}

	/** A stub for each file the post-PR #19 build puts under build/. */
	private function serveMasterBuild(): void {
		$this->serve( [
			'build/vendor/react.production.min.js' => '/* STUB react */',
			'build/vendor/react-dom.production.min.js' => '/* STUB react-dom */',
			'build/vendor/papaparse.min.js' => '/* STUB papaparse */',
			self::FA_CSS => file_get_contents( self::FIXTURES . '/fontawesome-6.7.2-all.min.css' ),
			'build/js/prompt-view.js' => '/* STUB build prompt-view */',
			'build/js/admin.js' => '/* STUB admin bundle */',
			// The bundled testers carry WikiAPI.baseUrl, as frontend/src/{index,batch-runner}.jsx do.
			'build/js/index.js' => "/* STUB index bundle */const WikiAPI={baseUrl:'" . self::PROD_API . "'};",
			'build/js/batch-runner.js' => "/* STUB batch bundle */const WikiAPI={baseUrl:'"
				. self::PROD_API . "'};",
			'prompt-parser.js' => '/* STUB prompt-parser */',
			'prompt-view.css' => '/* STUB prompt-view css */',
		] );
		foreach ( [ 'fa-brands-400', 'fa-regular-400', 'fa-solid-900', 'fa-v4compatibility' ] as $font ) {
			$this->serve( [
				"build/vendor/fontawesome/webfonts/$font.woff2" => "wOF2 STUB $font woff2",
				"build/vendor/fontawesome/webfonts/$font.ttf" => "\x00\x01\x00\x00 STUB $font ttf",
			] );
		}
	}

	/**
	 * Run an interface's handler with a fetcher that records each path asked for
	 * and then fetches it the real way.
	 *
	 * @param string $iface
	 * @return ResponseInterface
	 */
	private function respond( string $iface ): ResponseInterface {
		$right = $iface === 'admin' ? 'kzchatbot-view-rag-settings' : 'kzchatbot-testing';
		$this->setGroupPermissions( '*', $right, true );
		$real = self::realFetch();
		$fetch = function ( string $api, string $path, int $max ) use ( $real ) {
			$this->fetched[] = $path;
			return $real( $api, $path, $max );
		};
		$response = $this->executeHandler(
			new RagUiHandler( $fetch ),
			new RequestData(),
			[ 'interface' => $iface, 'viewRight' => $right ]
		);
		$this->assertSame( 200, $response->getStatusCode() );
		return $response;
	}

	/**
	 * @param string $iface
	 * @return string The response body
	 */
	private function render( string $iface ): string {
		return (string)$this->respond( $iface )->getBody();
	}

	/**
	 * The response's Content-Security-Policy, as directive name => sources.
	 *
	 * @param ResponseInterface $response
	 * @return string[][]
	 */
	private static function policy( ResponseInterface $response ): array {
		$policy = [];
		foreach ( explode( ';', $response->getHeaderLine( 'Content-Security-Policy' ) ) as $directive ) {
			$parts = preg_split( '/\s+/', trim( $directive ), -1, PREG_SPLIT_NO_EMPTY );
			if ( $parts ) {
				$policy[array_shift( $parts )] = $parts;
			}
		}
		return $policy;
	}

	/** @return Closure The handler's own backend fetcher */
	private static function realFetch(): Closure {
		return Closure::bind(
			static fn ( string $api, string $path, int $max ) => RagUiHandler::fetchBackendFile( $api, $path, $max ),
			null,
			RagUiHandler::class
		);
	}

	/**
	 * Every <script src> / <link rel=stylesheet href> in $html whose URL is
	 * relative to the backend (no scheme, not protocol-relative).
	 *
	 * @param string $html
	 * @return string[]
	 */
	private static function backendRelativeAssets( string $html ): array {
		preg_match_all( '/<script\b[^>]*\ssrc=(["\']?)([^"\'\s>]*)\1/i', $html, $scripts );
		preg_match_all( '/<link\b[^>]*>/i', $html, $links );
		$urls = $scripts[2];
		foreach ( $links[0] as $link ) {
			if ( preg_match( '/\srel=(["\']?)stylesheet\1/i', $link )
				&& preg_match( '/\shref=(["\']?)([^"\'\s>]*)\1/i', $link, $href )
			) {
				$urls[] = $href[2];
			}
		}
		return array_values( array_filter(
			$urls,
			static fn ( $u ) => !preg_match( '/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i', $u )
		) );
	}

	public static function providePages(): array {
		return [
			'admin' => [ 'admin', 'admin.html' ],
			'single tester' => [ 'index', 'index.html' ],
			'batch runner' => [ 'batch', 'batch-runner.html' ],
		];
	}

	/**
	 * The defect: since kolzchut/chatbot PR #19 the pages load build/... files,
	 * which were not allowlisted and so resolved to rest.php paths with no route.
	 *
	 * @dataProvider providePages
	 */
	public function testMasterPagesLeaveNoBackendRelativeAsset( string $iface, string $file ) {
		$this->serveMasterBuild();
		$page = file_get_contents( self::FIXTURES . "/master/$file" );
		$this->serve( [ $file => $page ] );
		$referenced = self::backendRelativeAssets( $page );
		$this->assertNotEmpty( $referenced, 'the fixture loads backend files' );

		$body = $this->render( $iface );

		$this->assertSame( [], self::backendRelativeAssets( $body ) );
		$this->assertStringNotContainsString( 'could not load', $body );
		foreach ( $referenced as $asset ) {
			$this->assertContains( $asset, $this->fetched );
			$stub = file_get_contents( $this->backendDir . '/' . $asset );
			if ( str_starts_with( $stub, '/* STUB' ) ) {
				$this->assertStringContainsString( substr( $stub, 0, 30 ), $body, $asset );
			}
		}
		// No absolute URL to the production wiki is left, in the HTML or a bundle.
		$this->assertStringNotContainsString( 'kolzchut.org.il', $body );
		if ( $iface !== 'admin' ) {
			$this->assertStringContainsString( "baseUrl:'/w/he/api.php'", $body );
			$this->assertStringContainsString( '"wikiApi":"/w/he/api.php"', $body );
		}
	}

	/**
	 * Font Awesome's webfonts come in as data: URIs: exactly its four woff2
	 * files are fetched, never a ttf, and no relative font URL is left.
	 *
	 * @dataProvider providePages
	 */
	public function testFontAwesomeFontsAreInlinedAsWoff2( string $iface, string $file ) {
		$this->serveMasterBuild();
		$this->serve( [ $file => file_get_contents( self::FIXTURES . "/master/$file" ) ] );

		$body = $this->render( $iface );

		$fonts = array_values( array_filter( $this->fetched, static fn ( $p ) => str_contains( $p, 'webfonts' ) ) );
		sort( $fonts );
		$this->assertSame( [
			'build/vendor/fontawesome/webfonts/fa-brands-400.woff2',
			'build/vendor/fontawesome/webfonts/fa-regular-400.woff2',
			'build/vendor/fontawesome/webfonts/fa-solid-900.woff2',
			'build/vendor/fontawesome/webfonts/fa-v4compatibility.woff2',
		], array_values( array_unique( $fonts ) ) );
		$this->assertStringNotContainsString( 'webfonts/', $body );
		$this->assertStringNotContainsString( '.ttf', $body );
		$this->assertStringContainsString(
			'url("data:font/woff2;base64,' . base64_encode( 'wOF2 STUB fa-solid-900 woff2' ) . '") format("woff2")',
			$body
		);
		// Each font file is fetched once, however many rules name it.
		$this->assertSameSize( $fonts, array_unique( $fonts ) );
	}

	/**
	 * The frame may load nothing from a third-party origin and may not eval:
	 * since kolzchut/chatbot PR #19 the pages need neither (kolzchut/chatbot#16).
	 * It keeps 'unsafe-inline', because the handler inlines every asset and
	 * injects the shim, and data: fonts (inlineCssFonts()). The sandbox stays.
	 *
	 * @dataProvider providePages
	 */
	public function testPolicyAllowsNoThirdPartyOriginOrEval( string $iface, string $file ) {
		$this->serveMasterBuild();
		$this->serve( [ $file => file_get_contents( self::FIXTURES . "/master/$file" ) ] );

		$policy = self::policy( $this->respond( $iface ) );

		$this->assertSame( [ "'self'", "'unsafe-inline'" ], $policy['script-src'] );
		$this->assertSame( [ "'self'", "'unsafe-inline'" ], $policy['style-src'] );
		$this->assertSame( [ "'self'", 'data:' ], $policy['font-src'] );
		$this->assertSame( explode( ' ', RagUiHandler::SANDBOX_FLAGS ), $policy['sandbox'] );
		$this->assertNotContains( 'allow-same-origin', $policy['sandbox'] );
		foreach ( $policy as $name => $sources ) {
			foreach ( $sources as $source ) {
				$this->assertStringNotContainsString( '://', $source, $name );
				$this->assertNotSame( "'unsafe-eval'", $source, $name );
			}
		}
	}

	/**
	 * Negative control for the policy: a backend still on the build before
	 * kolzchut/chatbot PR #19 cannot run. Every script and stylesheet it loads
	 * from a CDN has an origin the policy does not list, its Babel scripts need
	 * the eval the policy withholds, and its root prompt-view.js (the JSX that
	 * Babel compiled) is no longer inlined.
	 *
	 * @dataProvider providePages
	 */
	public function testPrePr19PagesAreRefused( string $iface, string $file ) {
		$page = file_get_contents( self::FIXTURES . "/pre-pr19/$file" );
		$this->serve( [
			$file => $page,
			'prompt-parser.js' => '/* STUB old prompt-parser */',
			'prompt-view.js' => '/* STUB old prompt-view jsx */',
			'prompt-view.css' => '/* STUB old prompt-view css */',
		] );

		$response = $this->respond( $iface );
		$body = (string)$response->getBody();
		$policy = self::policy( $response );

		preg_match_all( '/<(?:script|link)\b[^>]*\s(?:src|href)=["\'](https:\/\/[^\/"\']+)/i', $body, $m );
		$cdnOrigins = array_unique( $m[1] );
		$this->assertContains( 'https://unpkg.com', $cdnOrigins, 'the fixture loads CDN scripts' );
		$this->assertContains( 'https://cdnjs.cloudflare.com', $cdnOrigins, 'the fixture loads a CDN stylesheet' );
		foreach ( $cdnOrigins as $origin ) {
			$this->assertNotContains( $origin, $policy['script-src'] );
			$this->assertNotContains( $origin, $policy['style-src'] );
		}
		$this->assertStringContainsString( 'type="text/babel"', $body );
		$this->assertNotContains( "'unsafe-eval'", $policy['script-src'] );
		$this->assertNotContains( 'prompt-view.js', $this->fetched );
		if ( $iface !== 'admin' ) {
			$this->assertStringContainsString( '<!-- KZChatbot: could not load prompt-view.js -->', $body );
		}
	}

	/**
	 * Negative control: a backend-relative asset that is not allowlisted is not
	 * fetched, even when the backend has it, and is replaced by a comment. An
	 * absolute URL, an inline script's body and an HTML comment are left as
	 * they are.
	 */
	public function testUnknownRelativeAssetIsNotFetched() {
		$this->serve( [
			'index.html' => '<html><head>'
				. '<script src="build/js/unknown.js"></script>'
				. '<link rel="stylesheet" href="/build/unknown.css">'
				. '<script src="https://cdn.example/lib.js"></script>'
				. '<!-- <script src="commented.js"></script> -->'
				. '<script>var s = \'<link rel="stylesheet" href="inline.css">\';</script>'
				. '</head><body></body></html>',
			'build/js/unknown.js' => 'SECRET-UNKNOWN-JS',
			'build/unknown.css' => 'SECRET-UNKNOWN-CSS',
		] );

		$body = $this->render( 'index' );

		$this->assertSame( [ 'index.html' ], $this->fetched );
		$this->assertStringNotContainsString( 'SECRET-UNKNOWN', $body );
		$this->assertStringContainsString( '<!-- KZChatbot: could not load build/js/unknown.js -->', $body );
		$this->assertStringContainsString( '<!-- KZChatbot: could not load build/unknown.css -->', $body );
		$this->assertStringContainsString( '<script src="https://cdn.example/lib.js"></script>', $body );
		$this->assertStringContainsString( '<!-- <script src="commented.js"></script> -->', $body );
		$this->assertStringContainsString( '\'<link rel="stylesheet" href="inline.css">\'', $body );
	}

	public static function provideSlashedNames(): array {
		return [
			'relative' => [ 'build/js/index.js' ],
			'root-relative' => [ '/build/js/index.js' ],
			'dot-relative' => [ './build/vendor/papaparse.min.js' ],
		];
	}

	/**
	 * Allowlisted names containing '/' are matched. Before, the names were put
	 * into a /.../ regex with preg_quote() given no delimiter, so such a name
	 * broke the pattern and nothing was inlined at all.
	 *
	 * @dataProvider provideSlashedNames
	 */
	public function testAllowlistedNameWithSlashIsInlined( string $src ) {
		$file = ltrim( $src, './' );
		$this->serve( [
			'index.html' => '<html><head><script defer src="' . $src . '"></script></head><body></body></html>',
			$file => 'INLINED-' . $file,
		] );

		$body = $this->render( 'index' );

		$this->assertStringContainsString( "<script defer>\nINLINED-$file\n</script>", $body );
		$this->assertSame( [ 'index.html', $file ], $this->fetched );
	}

	/**
	 * Serve an allowlisted stylesheet with the given @font-face src list, and
	 * return what the handler made of it.
	 *
	 * @param string $src
	 * @param array<string,string> $files More backend files
	 * @return string
	 */
	private function renderFontFace( string $src, array $files = [] ): string {
		$this->serve( $files + [
			'index.html' => '<html><head><link rel="stylesheet" href="' . self::FA_CSS . '"></head></html>',
			self::FA_CSS => '@font-face{font-family:"T";src:' . $src . '}.x{color:red}',
		] );
		return $this->render( 'index' );
	}

	public function testFontUrlEscapingTheStaticRootIsRefused() {
		// The control: asked for it, the stand-in backend does hand out the file
		// outside its root, so only the handler's refusal keeps it out.
		$this->assertSame(
			'wOF2 OUTSIDE-THE-STATIC-ROOT',
			self::realFetch()( 'file://' . $this->backendDir . '/', 'build/../../secret.woff2', 0 )
		);

		// build/vendor/fontawesome/css/ is four levels down: the fifth '..' leaves the root.
		$body = $this->renderFontFace( 'url(../../../../../secret.woff2) format("woff2")' );

		$this->assertSame( [ 'index.html', self::FA_CSS ], $this->fetched );
		$this->assertStringNotContainsString( base64_encode( 'wOF2 OUTSIDE-THE-STATIC-ROOT' ), $body );
		// Dropped, so the browser does not try it either.
		$this->assertStringNotContainsString( '../', $body );
		$this->assertStringNotContainsString( 'secret', $body );
		$this->assertStringContainsString(
			"/* KZChatbot: refused a font URL that is not a plain path inside the backend's static files */",
			$body
		);
		$this->assertStringContainsString( '.x{color:red}', $body );
	}

	public static function provideRefusedFontUrls(): array {
		return [
			'root-relative' => [ 'url(/build/f.woff2)' ],
			'percent-encoded dots' => [ 'url(%2e%2e/%2e%2e/%2e%2e/%2e%2e/%2e%2e/secret.woff2)' ],
			'backslash' => [ 'url(..\\..\\..\\..\\..\\secret.woff2)' ],
		];
	}

	/**
	 * @dataProvider provideRefusedFontUrls
	 */
	public function testOddFontUrlsAreRefused( string $src ) {
		$body = $this->renderFontFace( $src, [ 'build/f.woff2' => 'wOF2 ROOT-RELATIVE' ] );

		$this->assertSame( [ 'index.html', self::FA_CSS ], $this->fetched );
		$this->assertStringNotContainsString( 'data:font', $body );
		$this->assertStringContainsString( 'refused a font URL', $body );
	}

	public function testAbsoluteAndLocalFontSourcesAreLeftAlone() {
		$src = 'local("T"),url(https://cdnjs.cloudflare.com/x.woff2) format("woff2"),url(data:font/woff2;base64,AAAA)';
		$body = $this->renderFontFace( $src );

		$this->assertSame( [ 'index.html', self::FA_CSS ], $this->fetched );
		$this->assertStringContainsString( 'src:' . $src . '}', $body );
	}

	public function testWoffOrTtfOnlyWithoutAWoff2() {
		$files = [
			'build/vendor/fontawesome/webfonts/a.woff' => 'wOFF A',
			'build/vendor/fontawesome/webfonts/a.ttf' => "\x00\x01\x00\x00 A",
			'build/vendor/fontawesome/webfonts/b.ttf' => "\x00\x01\x00\x00 B",
		];
		$body = $this->renderFontFace(
			'url(../webfonts/a.woff) format("woff"),url(../webfonts/a.ttf) format("truetype")',
			$files
		);
		$this->assertStringContainsString(
			'src:url("data:font/woff;base64,' . base64_encode( 'wOFF A' ) . '") format("woff")}',
			$body
		);
		$this->assertNotContains( 'build/vendor/fontawesome/webfonts/a.ttf', $this->fetched );

		$this->fetched = [];
		$body = $this->renderFontFace( 'url("../webfonts/b.ttf?v=1#x") format("truetype")', $files );
		$this->assertStringContainsString( 'data:font/ttf;base64,' . base64_encode( "\x00\x01\x00\x00 B" ), $body );
	}

	public function testFontThatIsNotAFontOrTooLargeIsLeftOut() {
		$big = 'wOF2' . str_repeat( 'x', 512 * 1024 );
		$body = $this->renderFontFace(
			'url(../webfonts/html.woff2) format("woff2"),url(../webfonts/big.woff2) format("woff2"),'
				. 'url(../webfonts/c.ttf) format("truetype")',
			[
				'build/vendor/fontawesome/webfonts/html.woff2' => '<!DOCTYPE html>not a font',
				'build/vendor/fontawesome/webfonts/big.woff2' => $big,
				'build/vendor/fontawesome/webfonts/c.ttf' => "\x00\x01\x00\x00 C",
			]
		);

		$this->assertStringNotContainsString( 'data:font', $body );
		$this->assertStringNotContainsString( 'not a font', $body );
		// A ttf is never inlined beside a woff2 entry.
		$this->assertNotContains( 'build/vendor/fontawesome/webfonts/c.ttf', $this->fetched );
		$this->assertStringContainsString(
			'/* KZChatbot: could not load build/vendor/fontawesome/webfonts/html.woff2; '
				. 'could not load build/vendor/fontawesome/webfonts/big.woff2 */}',
			$body
		);
	}

	public function testFontBudgetBoundsTheDocument() {
		$font = 'wOF2' . str_repeat( 'f', 500 * 1024 );
		$css = '';
		$files = [];
		foreach ( range( 1, 5 ) as $i ) {
			$css .= "@font-face{font-family:\"F$i\";src:url(../webfonts/f$i.woff2) format(\"woff2\")}";
			$files["build/vendor/fontawesome/webfonts/f$i.woff2"] = $font;
		}
		$this->serve( $files + [
			'index.html' => '<html><head><link rel="stylesheet" href="' . self::FA_CSS . '"></head></html>',
			self::FA_CSS => $css,
		] );

		$body = $this->render( 'index' );

		// 2 MiB fits four of the five.
		$this->assertSame( 4, substr_count( $body, 'data:font/woff2' ) );
		$this->assertStringContainsString(
			'font budget exhausted, left out build/vendor/fontawesome/webfonts/f5.woff2',
			$body
		);
	}
}
