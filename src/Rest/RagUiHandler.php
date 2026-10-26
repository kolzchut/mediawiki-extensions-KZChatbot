<?php

namespace MediaWiki\Extension\KZChatbot\Rest;

use MediaWiki\Extension\KZChatbot\BackendTimeouts;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\StringStream;
use RequestContext;

/**
 * Serves the RAG backend's own HTML interfaces (admin / single tester / batch
 * runner) for the KZChatbot special pages to embed in an iframe.
 *
 * The backend HTML is third-party-controlled code: it renders model output, and a
 * backend built before kolzchut/chatbot PR #19 also loads unpinned scripts from
 * public CDNs and compiles JSX in the browser. It therefore
 * runs SANDBOXED - both the iframe's sandbox attribute and this response's CSP
 * `sandbox` directive withhold allow-same-origin - so the document gets an opaque
 * origin. Whatever script runs in it cannot read the wiki's cookies or DOM, cannot
 * make a credentialed request to the wiki, and is never handed the user's CSRF
 * token (kolzchut/kz-mediawiki-main#148).
 *
 * The UI still needs its backend endpoints. An injected fetch() shim turns the
 * backend's root-relative calls for this interface's allowlisted endpoints into
 * postMessage requests to the embedding special page. There, the trusted
 * ext.KZChatbot.ragui module re-checks the endpoint against the same allowlist,
 * calls RagProxyHandler with the user's session (attaching the CSRF token itself)
 * and posts the response back. RagProxyHandler stays the real permission gate.
 * So the worst a hostile script in the frame can do is call this interface's own
 * allowlisted RAG endpoints with the rights the viewer already has there.
 *
 * The single tester and the batch runner also resolve a source page given by title
 * to its page ID with the wiki's action API. From the opaque origin that request
 * would be cross-origin with `Origin: null`, which the wiki does not answer with
 * CORS headers, so the shim relays it too, as a separate "pageLookup" message. The
 * embedding page performs it only for interfaces flagged 'pageLookup', and only
 * as a single-title action=query read.
 *
 * Route config (extension.json):
 *   "interface"  - key into self::INTERFACES
 *   "viewRight"  - right required to load this interface at all
 *   "editRight"  - (admin) right that unlocks the config editing controls
 */
class RagUiHandler extends Handler {

	/**
	 * Per interface: the backend file it is, the backend endpoints (under
	 * /kzchatbot/v0/ragproxy/) the embedding page may call on its behalf, and
	 * whether it may have the embedding page look up a page ID by title in the
	 * wiki's action API (its WikiAPI.getPageId()). Keep both to what each page's
	 * code actually calls: they bound what a compromised page can do.
	 */
	public const INTERFACES = [
		'admin' => [
			'file' => 'admin.html',
			'proxyPaths' => [
				'get_config', 'set_config', 'clean_redis_history',
				'ping/redis', 'ping/llm_manager', 'ping/es_docker',
			],
			'pageLookup' => false,
		],
		'index' => [
			'file' => 'index.html',
			'proxyPaths' => [ 'get_config', 'search', 'search/stream', 'rating' ],
			'pageLookup' => true,
		],
		'batch' => [
			'file' => 'batch-runner.html',
			'proxyPaths' => [ 'get_config', 'search', 'search/stream', 'clean_redis_history' ],
			'pageLookup' => true,
		],
	];

	/**
	 * The backend's own JS/CSS files the interfaces load, inlined into the HTML.
	 *
	 * A sandboxed document cannot load them as separate files from the wiki: a
	 * relative URL resolves under rest.php/kzchatbot/v0/ragui/, which has no route
	 * for them, and from the opaque origin any such request is cross-origin anyway
	 * (Babel standalone even fetches a `type="text/babel" src=` script with XHR).
	 * Only files named here are ever fetched, plus the fonts their stylesheets
	 * reference (see inlineCssFonts()). Any other backend-relative script or
	 * stylesheet is dropped, unfetched, for a "could not load" comment.
	 * Value: the element the file is inlined as.
	 */
	private const INLINE_ASSETS = [
		// The page shape before kolzchut/chatbot PR #19. Kept so a backend still
		// on that build keeps working.
		'prompt-parser.js' => 'script',
		'prompt-view.js' => 'script',
		'prompt-view.css' => 'style',
		// Since kolzchut/chatbot PR #19 the pages load prebuilt bundles and
		// vendored libraries instead of CDN scripts compiled by Babel standalone.
		'build/vendor/react.production.min.js' => 'script',
		'build/vendor/react-dom.production.min.js' => 'script',
		'build/vendor/papaparse.min.js' => 'script',
		'build/vendor/fontawesome/css/all.min.css' => 'style',
		'build/js/prompt-view.js' => 'script',
		'build/js/index.js' => 'script',
		'build/js/admin.js' => 'script',
		'build/js/batch-runner.js' => 'script',
	];

	/**
	 * Font files an inlined stylesheet may have inlined as data: URIs, by
	 * extension: the MIME type and the leading bytes the file must start with.
	 * In order of preference; see inlineCssFonts().
	 */
	private const FONT_TYPES = [
		'woff2' => [ 'font/woff2', [ 'wOF2' ] ],
		'woff' => [ 'font/woff', [ 'wOFF' ] ],
		'ttf' => [ 'font/ttf', [ "\x00\x01\x00\x00", 'true', 'OTTO' ] ],
	];

	/** Largest font file inlined, in bytes (Font Awesome 6's largest woff2 is ~155 KiB). */
	private const FONT_MAX_BYTES = 512 * 1024;

	/**
	 * Most font bytes inlined into one document, counting a font once per url()
	 * it replaces: CSS cannot share one data: URI between @font-face rules, and
	 * Font Awesome 6 names each of its woff2 files up to three times (~0.9 MiB).
	 */
	private const FONT_BUDGET_BYTES = 2 * 1024 * 1024;

	/**
	 * Sandbox flags for the iframe attribute and the CSP `sandbox` directive.
	 * Never add allow-same-origin: it would give the document the wiki's origin
	 * back, and together with allow-scripts it could remove its own sandbox.
	 * - allow-forms: the config form and testers use <form> elements;
	 * - allow-modals: the UIs use alert() and confirm();
	 * - allow-downloads: CSV / JSON exports via blob links;
	 * - allow-popups(-to-escape-sandbox): source links open wiki pages in new tabs,
	 *   which should be ordinary, unsandboxed pages.
	 */
	public const SANDBOX_FLAGS = 'allow-scripts allow-forms allow-modals allow-downloads '
		. 'allow-popups allow-popups-to-escape-sandbox';

	/** @var callable Fetches a backend file; see the constructor */
	private $fetchFile;

	/**
	 * @param callable|null $fetchFile Fetches a backend file: ( apiUrl, path, maxBytes )
	 *  to contents or null. Defaults to an HTTP GET; tests pass a recording one.
	 */
	public function __construct( ?callable $fetchFile = null ) {
		$this->fetchFile = $fetchFile ?? self::fetchBackendFile( ... );
	}

	/**
	 * The iframe a special page embeds an interface with.
	 *
	 * @param string $iface Key of self::INTERFACES
	 * @param string $restPath $wgRestPath
	 * @param string $title Accessible title of the frame
	 * @return string HTML
	 */
	public static function buildIframe( string $iface, string $restPath, string $title ): string {
		return Html::element( 'iframe', [
			'src' => $restPath . '/kzchatbot/v0/ragui/' . $iface,
			'class' => 'kzchatbot-rag-iframe',
			'title' => $title,
			'sandbox' => self::SANDBOX_FLAGS,
			'data-kzchatbot-proxy-base' => $restPath . '/kzchatbot/v0/ragproxy/',
			'data-kzchatbot-proxy-paths' => json_encode( self::INTERFACES[$iface]['proxyPaths'] ),
			// Read by ext.KZChatbot.ragui; absent (null) means page lookups are refused.
			'data-kzchatbot-page-lookup' => self::INTERFACES[$iface]['pageLookup'] ? '1' : null,
		] );
	}

	/** @inheritDoc */
	public function execute() {
		$routeConfig = $this->getConfig();
		$iface = self::INTERFACES[$routeConfig['interface'] ?? ''] ?? null;
		$viewRight = $routeConfig['viewRight'] ?? '';
		$editRight = $routeConfig['editRight'] ?? '';

		$context = RequestContext::getMain();
		$user = $context->getUser();
		$services = MediaWikiServices::getInstance();
		$pm = $services->getPermissionManager();
		$mainConfig = $services->getMainConfig();

		if ( !$viewRight || !$pm->userHasRight( $user, $viewRight ) ) {
			return $this->errorResponse( 403, 'You are not allowed to view this interface.' );
		}
		if ( !$iface ) {
			return $this->errorResponse( 500, 'Misconfigured interface route.' );
		}

		$apiUrl = $mainConfig->get( 'KZChatbotLlmApiUrl' );
		$html = ( $this->fetchFile )( $apiUrl, $iface['file'], 0 );
		if ( $html === null ) {
			return $this->errorResponse( 502, 'The RAG backend is unreachable.' );
		}

		// "Edit" only applies to the admin interface (the one with a config form
		// and a Danger Zone). The batch runner reuses class names like
		// .main-content, so its controls must never be hidden by the edit logic.
		$hasEditConcept = (bool)$editRight;
		$canEdit = $hasEditConcept && $pm->userHasRight( $user, $editRight );
		$canCleanRedis = $hasEditConcept && $pm->userHasRight( $user, 'kzchatbot-clean-redis-history' );

		// Point the testers' title-to-page-ID lookup (WikiAPI.getPageId) at the
		// local wiki API instead of the backend's hard-coded production URL. It
		// cannot be called directly from the sandboxed frame: from an opaque origin
		// it is a CORS request with `Origin: null`, which the wiki does not answer
		// with Access-Control-Allow-Origin, whatever `origin=*` says. The shim
		// therefore relays calls to this URL to the embedding page, which performs
		// them same-origin (see ext.KZChatbot.ragui).
		// The rewrite runs after inlining: since kolzchut/chatbot PR #19 the URL is
		// in the bundled build/js/*.js files, not in the HTML itself.
		$localApi = $mainConfig->get( 'ScriptPath' ) . '/api.php';
		$html = $this->inlineAssets( $html, $apiUrl );
		$html = str_replace( 'https://www.kolzchut.org.il/w/he/api.php', $localApi, $html );

		$kzProxy = [
			'paths' => $iface['proxyPaths'],
			// Path of the wiki API whose calls the shim relays as page lookups;
			// null for an interface that has none to make.
			'wikiApi' => $iface['pageLookup'] ? $localApi : null,
			'canEdit' => $canEdit,
			'canCleanRedis' => $canCleanRedis,
		];

		$injection = "\n<script>window.KZ_PROXY = " . json_encode( $kzProxy, JSON_UNESCAPED_SLASHES )
			. ";</script>\n"
			. "<script>" . $this->buildShim() . "</script>\n"
			. "<style>" . $this->buildHideCss( $hasEditConcept, $canEdit, $canCleanRedis ) . "</style>\n";

		// Inject before </head> so the shim is installed before the app's scripts
		// (which run at end of <body>) make any request or touch localStorage.
		$html = self::insertBefore( '</head>', $injection, $html );

		$response = $this->getResponseFactory()->create();
		$response->setStatus( 200 );
		$response->setHeader( 'Content-Type', 'text/html; charset=utf-8' );
		$response->setHeader( 'Cache-Control', 'no-store, max-age=0, must-revalidate' );
		$response->setHeader( 'Pragma', 'no-cache' );
		$response->setHeader( 'X-Content-Type-Options', 'nosniff' );
		// The `sandbox` directive is what contains this document: it also applies
		// when the URL is opened directly rather than through the special page's
		// iframe. The rest of the policy allows inline scripts and styles (the
		// inlined assets), data: fonts (see inlineCssFonts()), and, for a backend
		// still on the pre-PR #19 build, its CDN deps and eval (Babel standalone);
		// inside the sandbox none of them run with the wiki's origin.
		// Nothing in the frame reaches the wiki directly:
		// RAG endpoint calls and page lookups both go through the relay, because
		// from the opaque origin a direct call is cross-origin, which CSP's 'self'
		// (matched against the document's URL) does not change.
		$response->setHeader( 'Content-Security-Policy',
			'sandbox ' . self::SANDBOX_FLAGS . '; '
			. "default-src 'self'; "
			. "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://unpkg.com https://cdnjs.cloudflare.com; "
			. "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
			. "font-src 'self' data: https://cdnjs.cloudflare.com; "
			. "img-src 'self' data:; "
			. "connect-src 'self'; "
			. "form-action 'none'; "
			. "base-uri 'none'; "
			. "frame-ancestors 'self';"
		);
		$response->setBody( new StringStream( $html ) );
		return $response;
	}

	/**
	 * Replace each <script src> / <link rel=stylesheet href> that names one of
	 * self::INLINE_ASSETS (relative, `./`-relative or root-absolute) with the
	 * file's contents, inline. A script keeps its other attributes, so
	 * `type="text/babel"` survives. Fonts an inlined stylesheet names are
	 * inlined too (inlineCssFonts()).
	 *
	 * Any other script or stylesheet whose URL is relative to the backend is
	 * replaced, without being fetched, by a "could not load" comment: in the
	 * sandbox it could only fail, as a request for a rest.php path with no route.
	 * Absolute URLs (an old build's CDN scripts) are left to the CSP.
	 *
	 * It is a single pass, so nothing it inlines is scanned again, and it steps
	 * over inline <script> bodies and HTML comments without changing them.
	 *
	 * @param string $html
	 * @param string $apiUrl
	 * @return string
	 */
	private function inlineAssets( string $html, string $apiUrl ): string {
		$cache = [];
		$fetch = $this->fetchFile;
		$load = static function ( string $file, int $maxBytes = 0 ) use ( &$cache, $apiUrl, $fetch ): ?string {
			if ( !array_key_exists( $file, $cache ) ) {
				$cache[$file] = $fetch( $apiUrl, $file, $maxBytes );
			}
			return $cache[$file];
		};
		$fontBudget = self::FONT_BUDGET_BYTES;

		return preg_replace_callback(
			'/<!--.*?-->'
			. '|<script\b(?<pre>[^>]*?)\ssrc=(?<sq>["\'])(?<src>[^"\']*)\k<sq>(?<post>[^>]*)>\s*<\/script\s*>'
			. '|<script\b[^>]*>.*?<\/script\s*>'
			. '|<link\b[^>]*>/is',
			function ( array $m ) use ( $load, &$fontBudget ) {
				$tag = $m[0];
				if ( isset( $m['src'] ) && $m['src'] !== '' ) {
					return $this->inlineScript( $m['src'], $m['pre'], $m['post'], $tag, $load );
				}
				if ( !preg_match( '/^<link\b/i', $tag )
					|| !preg_match( '/\srel=(["\']?)stylesheet\1/i', $tag )
					|| !preg_match( '/\shref=(["\'])([^"\']*)\1/i', $tag, $href )
				) {
					return $tag;
				}
				$file = self::assetPath( $href[2] );
				if ( $file === null ) {
					return $tag;
				}
				$body = ( self::INLINE_ASSETS[$file] ?? null ) === 'style' ? $load( $file ) : null;
				if ( $body === null ) {
					return self::couldNotLoad( $file );
				}
				$body = self::inlineCssFonts( $body, $file, $load, $fontBudget );
				$body = preg_replace( '/<\/style/i', '<\\\\/style', $body );
				return "<style>\n" . $body . "\n</style>";
			},
			$html
		);
	}

	/**
	 * @param string $src The script's src attribute
	 * @param string $pre Attributes before src
	 * @param string $post Attributes after src
	 * @param string $tag The whole element
	 * @param callable $load
	 * @return string
	 */
	private function inlineScript( string $src, string $pre, string $post, string $tag, callable $load ): string {
		$file = self::assetPath( $src );
		if ( $file === null ) {
			return $tag;
		}
		$body = ( self::INLINE_ASSETS[$file] ?? null ) === 'script' ? $load( $file ) : null;
		if ( $body === null ) {
			return self::couldNotLoad( $file );
		}
		// Nothing in the file may close the element early.
		$body = preg_replace( '/<\/script/i', '<\\\\/script', $body );
		return '<script' . $pre . $post . ">\n" . $body . "\n</script>";
	}

	/**
	 * The backend path an asset URL in the backend HTML names, or null for one
	 * that is not relative to the backend (has a scheme, or is protocol-relative).
	 *
	 * @param string $url
	 * @return string|null
	 */
	private static function assetPath( string $url ): ?string {
		$url = trim( $url );
		if ( preg_match( '/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i', $url ) ) {
			return null;
		}
		return preg_replace( '/^(?:\.\/|\/)/', '', $url );
	}

	/**
	 * @param string $file
	 * @return string
	 */
	private static function couldNotLoad( string $file ): string {
		return '<!-- KZChatbot: could not load ' . str_replace( '--', '-&#45;', htmlspecialchars( $file ) ) . ' -->';
	}

	/**
	 * Rewrite the fonts an inlined stylesheet names into data: URIs.
	 *
	 * Font Awesome's all.min.css names its webfonts as `url(../webfonts/…)`. In
	 * the sandbox that resolves under rest.php/kzchatbot/v0/, and from the opaque
	 * origin a font request is a CORS request the wiki does not answer, so no
	 * icon would render. The CSP allows data: fonts.
	 *
	 * For each `src:` list in an @font-face rule, at most one entry is inlined:
	 * the first woff2 that loads; a woff, then a ttf, only when the list has no
	 * woff2 entry at all (the browser stops at the first src it can use, and a
	 * ttf is two to three times the size). The other relative entries are
	 * dropped, since in the sandbox they could only fail. local() entries and
	 * absolute URLs are kept as they are. A rule left with nothing is left
	 * without its src, which the browser ignores, plus a comment saying why.
	 *
	 * What may be fetched is derived from the allowlisted stylesheet only:
	 * - the url() must be relative to the stylesheet: no scheme, not root-
	 *   absolute or protocol-relative, only [A-Za-z0-9._~/-] (no %-escapes,
	 *   backslashes or spaces), with any ?query / #fragment ignored;
	 * - it is resolved against the stylesheet's own path, and refused if a `..`
	 *   climbs above the backend's static root;
	 * - it must end in .woff2, .woff or .ttf, and the file must start with that
	 *   format's signature;
	 * - each file is at most FONT_MAX_BYTES, and one document gets at most
	 *   FONT_BUDGET_BYTES of inlined fonts, counting a font once per rule it is
	 *   inlined into. A font past either limit is left out, and its icons with it.
	 *
	 * Limits: only url()s inside @font-face src descriptors are handled, not CSS
	 * imports or images; the parser is a set of regexes over the CSS, written for
	 * well-formed stylesheets like Font Awesome's, and a rule whose src it cannot
	 * read is left as it is.
	 *
	 * @param string $css
	 * @param string $cssPath The stylesheet's path below the backend's static root
	 * @param callable $load ( path, maxBytes ) to contents or null
	 * @param int &$budget Font bytes this document may still inline
	 * @return string
	 */
	private static function inlineCssFonts( string $css, string $cssPath, callable $load, int &$budget ): string {
		// One CSS token: a quoted string, a function call (url(), format(), local())
		// whose argument holds no unquoted ')', or any other character in $other.
		$token = static fn ( string $other ) => '(?:[a-z-]+\((?:"[^"]*"|\'[^\']*\'|[^)"\'])*\)'
			. '|"[^"]*"|\'[^\']*\'|[^' . $other . '"\'(])';

		return preg_replace_callback( '/@font-face\s*\{[^}]*\}/i', static function ( array $rule ) use (
			$token, $cssPath, $load, &$budget
		) {
			return preg_replace_callback(
				'/(?<![\w-])src\s*:\s*(' . $token( ';}' ) . '+)/i',
				static function ( array $decl ) use ( $token, $cssPath, $load, &$budget ) {
					preg_match_all( '/' . $token( ',' ) . '+/i', $decl[1], $parts );
					return self::rewriteFontSrc( array_map( 'trim', $parts[0] ), $cssPath, $load, $budget );
				},
				$rule[0]
			);
		}, $css );
	}

	/**
	 * @param string[] $entries The entries of one @font-face src list
	 * @param string $cssPath
	 * @param callable $load
	 * @param int &$budget
	 * @return string The src declaration to use, or a comment if none is left
	 */
	private static function rewriteFontSrc( array $entries, string $cssPath, callable $load, int &$budget ): string {
		$kept = [];
		// Per relative entry: [ index in $kept, resolved path or null, extension, format hint ]
		$candidates = [];
		foreach ( $entries as $entry ) {
			if ( !preg_match( '/^url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)\s]*))\s*\)\s*(.*)$/is', $entry, $m ) ) {
				// local(), or something this does not understand: leave it.
				$kept[] = $entry;
				continue;
			}
			$url = $m[1] . ( $m[2] ?? '' ) . ( $m[3] ?? '' );
			if ( preg_match( '/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i', $url ) ) {
				$kept[] = $entry;
				continue;
			}
			$path = self::resolveFontPath( $cssPath, $url );
			$ext = $path === null ? null : strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			$candidates[] = [ count( $kept ), $path, $ext, $m[4] ];
			$kept[] = null;
		}
		if ( !$candidates ) {
			return 'src:' . implode( ',', $entries );
		}

		$hasType = static fn ( string $t ) => (bool)array_filter( $candidates, static fn ( $c ) => $c[2] === $t );
		$chosen = $hasType( 'woff2' ) ? [ 'woff2' ] : [ 'woff', 'ttf' ];
		$notes = [];
		$done = false;
		foreach ( $chosen as $type ) {
			foreach ( $candidates as [ $slot, $path, $ext, $format ] ) {
				if ( $done || $ext !== $type ) {
					continue;
				}
				[ $mime, $signatures ] = self::FONT_TYPES[$type];
				$font = (string)$load( $path, self::FONT_MAX_BYTES );
				$valid = $font !== '' && strlen( $font ) <= self::FONT_MAX_BYTES
					&& (bool)array_filter( $signatures, static fn ( $sig ) => str_starts_with( $font, $sig ) );
				if ( !$valid ) {
					$notes[] = "could not load $path";
					continue;
				}
				if ( strlen( $font ) > $budget ) {
					$notes[] = "font budget exhausted, left out $path";
					continue;
				}
				$budget -= strlen( $font );
				$kept[$slot] = 'url("data:' . $mime . ';base64,' . base64_encode( $font ) . '")'
					. ( $format !== '' ? ' ' . $format : '' );
				$done = true;
			}
		}
		foreach ( $candidates as [ , $path, $ext ] ) {
			if ( $path === null ) {
				$notes[] = 'refused a font URL that is not a plain path inside the backend\'s static files';
			} elseif ( !isset( self::FONT_TYPES[$ext] ) ) {
				$notes[] = "left out $path, not woff2, woff or ttf";
			}
		}

		$kept = array_filter( $kept, static fn ( $e ) => $e !== null );
		$note = $notes ? '/* KZChatbot: ' . implode( '; ', array_unique( $notes ) ) . ' */' : '';
		return $kept ? 'src:' . implode( ',', $kept ) . $note : $note;
	}

	/**
	 * Resolve a font url() against the stylesheet that names it.
	 *
	 * @param string $cssPath The stylesheet's path below the backend's static root
	 * @param string $url A relative url() from that stylesheet
	 * @return string|null The font's path below the static root, or null if the
	 *  URL is refused for its shape or for leaving the root (see inlineCssFonts())
	 */
	private static function resolveFontPath( string $cssPath, string $url ): ?string {
		$url = preg_replace( '/[?#].*$/s', '', trim( $url ) );
		if ( $url === '' || $url[0] === '/' || !preg_match( '/^[A-Za-z0-9._~\/-]+$/', $url ) ) {
			return null;
		}
		$segments = explode( '/', $cssPath );
		array_pop( $segments );
		foreach ( explode( '/', $url ) as $segment ) {
			if ( $segment === '' || $segment === '.' ) {
				continue;
			}
			if ( $segment === '..' ) {
				if ( !$segments ) {
					return null;
				}
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}
		return implode( '/', $segments );
	}

	/**
	 * Insert $insert before the first $marker, or prepend it if there is none.
	 * Plain string functions, not a regex: the injected JSON may contain
	 * backslashes, which a regex replacement string would mangle.
	 *
	 * @param string $marker
	 * @param string $insert
	 * @param string $html
	 * @return string
	 */
	private static function insertBefore( string $marker, string $insert, string $html ): string {
		$pos = stripos( $html, $marker );
		if ( $pos === false ) {
			return $insert . $html;
		}
		return substr( $html, 0, $pos ) . $insert . substr( $html, $pos );
	}

	/**
	 * @param string $apiUrl
	 * @param string $file
	 * @param int $maxBytes Give up on a file larger than this; 0 for no limit
	 * @return string|null File contents, or null on failure
	 */
	private static function fetchBackendFile( string $apiUrl, string $file, int $maxBytes = 0 ): ?string {
		// One page load fetches the HTML and then each asset in turn, so each
		// fetch gets only what is left of the request's budget (BackendTimeouts),
		// and none is started once that is spent.
		if ( BackendTimeouts::remaining() < 1 ) {
			return null;
		}
		$timeout = BackendTimeouts::total( 30 );
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$url = rtrim( $apiUrl, '/' ) . '/' . ltrim( $file, '/' );
		$body = '';
		$ch = curl_init( $url );
		curl_setopt_array( $ch, [
			CURLOPT_CONNECTTIMEOUT => BackendTimeouts::connect(
				$config->get( 'KZChatbotLlmApiConnectTimeout' ), $timeout
			),
			CURLOPT_TIMEOUT => $timeout,
			// Stop reading once past the limit, rather than buffering all of it.
			CURLOPT_WRITEFUNCTION => static function ( $ch, string $chunk ) use ( &$body, $maxBytes ): int {
				if ( $maxBytes > 0 && strlen( $body ) + strlen( $chunk ) > $maxBytes ) {
					return 0;
				}
				$body .= $chunk;
				return strlen( $chunk );
			},
		] );
		$ok = curl_exec( $ch );
		$failed = !$ok || curl_errno( $ch ) || curl_getinfo( $ch, CURLINFO_HTTP_CODE ) >= 400;
		return $failed ? null : $body;
	}

	/**
	 * The shim, injected inline into the sandboxed document.
	 *
	 * - fetch(): the interface's allowlisted root-relative endpoint calls become
	 *   postMessage requests to the embedding page (ext.KZChatbot.ragui), which
	 *   performs them and answers with status, content type and body. Calls to
	 *   the wiki API URL (KZ_PROXY.wikiApi) are relayed the same way as a
	 *   separate "pageLookup" message, which the page validates on its own terms.
	 *   Requests wait for the page's "ready" message, since the iframe can start
	 *   before that module has loaded.
	 * - localStorage / sessionStorage: an opaque origin has none, and touching
	 *   them throws. The single tester keeps its chat history there, so give it an
	 *   in-memory store that lasts as long as the page.
	 *
	 * @return string
	 */
	private function buildShim(): string {
		return <<<'JS'
(function () {
	var P = window.KZ_PROXY || {};
	var PROXIED = P.paths || [];
	var WIKI_API = P.wikiApi || null;
	// This document's origin is opaque, but its URL is still the wiki's.
	var parentOrigin = new URL( window.location.href ).origin;
	var embedded = window.parent !== window;
	var pending = {};
	var queue = [];
	var ready = false;
	var nextId = 1;
	var NULL_BODY = { 101: 1, 204: 1, 205: 1, 304: 1 };

	function send( msg ) {
		window.parent.postMessage( msg, parentOrigin );
	}
	window.addEventListener( 'message', function ( e ) {
		var d = e.data;
		if ( e.source !== window.parent || !d || typeof d !== 'object' ) {
			return;
		}
		if ( d.kzRag === 'ready' && !ready ) {
			ready = true;
			queue.forEach( send );
			queue = [];
			return;
		}
		if ( d.kzRag !== 'response' || !pending[ d.id ] ) {
			return;
		}
		var p = pending[ d.id ];
		delete pending[ d.id ];
		if ( d.error ) {
			p.reject( new TypeError( 'KZChatbot relay: ' + d.error ) );
			return;
		}
		p.resolve( new Response( NULL_BODY[ d.status ] ? null : d.body, {
			status: d.status,
			statusText: d.statusText || '',
			headers: { 'Content-Type': d.contentType || 'text/plain' }
		} ) );
	} );
	if ( embedded ) {
		send( { kzRag: 'hello' } );
	}

	// The query string of a call to the wiki API URL, or null for any other URL.
	function wikiApiQuery( url ) {
		if ( !WIKI_API || !url ) {
			return null;
		}
		var u;
		try {
			u = new URL( url, window.location.href );
		} catch ( e ) {
			return null;
		}
		if ( u.origin !== parentOrigin || u.pathname !== WIKI_API ) {
			return null;
		}
		return u.search.replace( /^\?/, '' );
	}

	var orig = window.fetch.bind( window );
	window.fetch = function ( input, init ) {
		var url = ( typeof input === 'string' ) ? input : '';
		var lookupQuery = wikiApiQuery( url );
		var m = /^\/([a-z_]+(?:\/[a-z_]+)?)(?:\?([^#]*))?$/i.exec( url );
		if ( lookupQuery === null && ( !m || PROXIED.indexOf( m[ 1 ] ) === -1 ) ) {
			return orig( input, init );
		}
		init = init || {};
		var body = ( init.body === undefined || init.body === null ) ? null : init.body;
		if ( !embedded ) {
			return Promise.reject( new TypeError( 'KZChatbot: open this interface from its special page.' ) );
		}
		if ( body !== null && typeof body !== 'string' ) {
			return Promise.reject( new TypeError( 'KZChatbot relay: only string bodies are supported.' ) );
		}
		return new Promise( function ( resolve, reject ) {
			var id = nextId++;
			var method = String( init.method || 'GET' ).toUpperCase();
			// What a lookup may be is decided by the embedding page, not here.
			var msg = lookupQuery !== null ?
				{ kzRag: 'pageLookup', id: id, query: lookupQuery, method: method, body: body } :
				{ kzRag: 'request', id: id, path: m[ 1 ], query: m[ 2 ] || '', method: method, body: body };
			pending[ id ] = { resolve: resolve, reject: reject };
			if ( ready ) {
				send( msg );
			} else {
				queue.push( msg );
			}
		} );
	};

	function memoryStorage() {
		var data = {};
		return {
			getItem: function ( k ) {
				k = String( k );
				return Object.prototype.hasOwnProperty.call( data, k ) ? data[ k ] : null;
			},
			setItem: function ( k, v ) { data[ String( k ) ] = String( v ); },
			removeItem: function ( k ) { delete data[ String( k ) ]; },
			clear: function () { data = {}; },
			key: function ( i ) { var keys = Object.keys( data ); return i < keys.length ? keys[ i ] : null; },
			get length() { return Object.keys( data ).length; }
		};
	}
	[ 'localStorage', 'sessionStorage' ].forEach( function ( name ) {
		try {
			window[ name ].getItem( 'kz' );
		} catch ( e ) {
			try {
				Object.defineProperty( window, name, { value: memoryStorage(), configurable: true } );
			} catch ( e2 ) {}
		}
	} );
})();
JS;
	}

	/**
	 * CSS that hides edit / destructive controls from users without the right.
	 * Only applied to interfaces that actually have such controls (the admin UI);
	 * the batch runner reuses some of the same class names, so it must be skipped.
	 * @param bool $hasEditConcept
	 * @param bool $canEdit
	 * @param bool $canCleanRedis
	 * @return string
	 */
	private function buildHideCss( bool $hasEditConcept, bool $canEdit, bool $canCleanRedis ): string {
		$css = '';
		if ( !$hasEditConcept ) {
			return $css;
		}
		if ( !$canEdit ) {
			// Hide save/preset buttons and visually lock the form inputs. The proxy
			// is the real guard (set_config requires the edit right); this is UX.
			$css .= '.save-btn{display:none !important;}'
				. '.main-content input,.main-content select,.main-content textarea'
				. '{pointer-events:none !important;background:#f1f1f1 !important;opacity:.7;}';
		}
		if ( !$canCleanRedis ) {
			$css .= '.danger-zone{display:none !important;}';
		}
		return $css;
	}

	/**
	 * @param int $status
	 * @param string $message
	 * @return Response
	 */
	private function errorResponse( int $status, string $message ): Response {
		$response = $this->getResponseFactory()->create();
		$response->setStatus( $status );
		$response->setHeader( 'Content-Type', 'text/html; charset=utf-8' );
		$response->setHeader( 'Cache-Control', 'no-store, max-age=0, must-revalidate' );
		$response->setBody( new StringStream(
			'<!DOCTYPE html><meta charset="utf-8"><body style="font-family:sans-serif;padding:1em">'
			. htmlspecialchars( $message ) . '</body>'
		) );
		return $response;
	}

	/** @inheritDoc */
	public function needsWriteAccess() {
		return false;
	}

	/** @inheritDoc */
	public function needsReadAccess() {
		return false;
	}
}
