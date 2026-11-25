<?php

namespace MediaWiki\Extension\KZChatbot\Tests\Unit;

use MediaWiki\Extension\KZChatbot\ApiKZChatbotSubmitQuestion;
use MediaWiki\Extension\KZChatbot\KZChatbot;
use MediaWikiUnitTestCase;

/**
 * Settings URLs and the answer's source URLs are rendered as link targets,
 * so only absolute http(s) URLs may pass.
 *
 * @covers \MediaWiki\Extension\KZChatbot\KZChatbot::isHttpUrl
 * @covers \MediaWiki\Extension\KZChatbot\ApiKZChatbotSubmitQuestion::normaliseDocs
 */
class HttpUrlTest extends MediaWikiUnitTestCase {

	public static function provideAccepted(): iterable {
		yield 'https' => [ 'https://www.kolzchut.org.il/he/דמי_אבטלה' ];
		yield 'http' => [ 'http://example.org/page?x=1&y=2#z' ];
		yield 'upper-case scheme' => [ 'HTTPS://EXAMPLE.ORG/' ];
		yield 'port and user info' => [ 'https://user@example.org:8443/' ];
		yield 'surrounding whitespace' => [ "  https://example.org/\n" ];
	}

	/**
	 * @dataProvider provideAccepted
	 */
	public function testAccepted( string $url ) {
		$this->assertTrue( KZChatbot::isHttpUrl( $url ) );
	}

	public static function provideRefused(): iterable {
		yield 'javascript' => [ 'javascript:alert(1)' ];
		yield 'javascript, mixed case and leading space' => [ ' JavaScript:alert(1)' ];
		yield 'javascript behind a leading control character' => [ "\x01javascript:alert(1)" ];
		yield 'data' => [ 'data:text/html;base64,PGI+eDwvYj4=' ];
		yield 'vbscript' => [ 'vbscript:msgbox(1)' ];
		yield 'mailto' => [ 'mailto:someone@example.org' ];
		yield 'ftp' => [ 'ftp://example.org/file' ];
		yield 'scheme split by a tab' => [ "java\tscript:alert(1)" ];
		yield 'http scheme split by a tab' => [ "ht\ttps://example.org/" ];
		yield 'protocol-relative' => [ '//example.org/page' ];
		yield 'relative path' => [ '/w/he/Page' ];
		yield 'no host' => [ 'https:///path' ];
		yield 'scheme only' => [ 'https://' ];
		yield 'single slash' => [ 'https:/example.org' ];
		yield 'empty' => [ '' ];
		yield 'whitespace' => [ "  \t" ];
		yield 'null' => [ null ];
		yield 'integer' => [ 42 ];
		yield 'array' => [ [ 'https://example.org/' ] ];
	}

	/**
	 * @dataProvider provideRefused
	 * @param mixed $url
	 */
	public function testRefused( $url ) {
		$this->assertFalse( KZChatbot::isHttpUrl( $url ) );
	}

	public function testNormaliseDocsKeepsOnlyHttpLinks() {
		$rawDocs = json_decode( json_encode( [
			[ 'title' => 'Web page', 'url' => 'https://www.kolzchut.org.il/he/Page' ],
			[ 'title' => 'Script', 'url' => 'javascript:alert(1)' ],
			[ 'title' => 'Data', 'url' => 'data:text/html,x' ],
			[ 'title' => 'Relative', 'url' => '/he/Page' ],
			[ 'title' => 'No url' ],
			[ 'title' => 'Null url', 'url' => null ],
			[ 'title' => 'Object url', 'url' => [ 'x' => 'https://example.org/' ] ],
			[ 'url' => " http://example.org/untitled " ],
		] ) );
		$rawDocs[] = 'not an object';

		$this->assertSame(
			[
				[ 'title' => 'Web page', 'url' => 'https://www.kolzchut.org.il/he/Page' ],
				[ 'title' => '', 'url' => 'http://example.org/untitled' ],
			],
			ApiKZChatbotSubmitQuestion::normaliseDocs( $rawDocs )
		);
	}

	public function testNormaliseDocsOfNothingIsEmpty() {
		$this->assertSame( [], ApiKZChatbotSubmitQuestion::normaliseDocs( [] ) );
	}
}
