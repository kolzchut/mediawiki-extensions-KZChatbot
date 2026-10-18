<?php

namespace MediaWiki\Extension\KZChatbot\Rest;

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
 * The backend HTML is third-party-controlled code: it loads unpinned scripts from
 * public CDNs, compiles JSX in the browser, and renders model output. It therefore
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
	 * A sandboxed document cannot load them as separate files from the wiki: they
	 * would be cross-origin requests from an opaque origin, and Babel standalone
	 * fetches a `type="text/babel" src=` script with XHR, which MediaWiki's REST
	 * API does not allow cross-origin. Only files named here are ever fetched.
	 * Value: the element the file is inlined as.
	 */
	private const INLINE_ASSETS = [
		'prompt-parser.js' => 'script',
		'prompt-view.js' => 'script',
		'prompt-view.css' => 'style',
	];

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
		$html = self::fetchBackendFile( $apiUrl, $iface['file'] );
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
		$localApi = $mainConfig->get( 'ScriptPath' ) . '/api.php';
		$html = str_replace( 'https://www.kolzchut.org.il/w/he/api.php', $localApi, $html );

		$html = $this->inlineAssets( $html, $apiUrl );

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
		// iframe. The rest of the policy allows the backend HTML's CDN deps and
		// inline/eval (Babel standalone); inside the sandbox they no longer run
		// with the wiki's origin. Nothing in the frame reaches the wiki directly:
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
	 * self::INLINE_ASSETS (relative or root-absolute) with the file's contents,
	 * inline. A script keeps its other attributes, so `type="text/babel"` survives.
	 *
	 * @param string $html
	 * @param string $apiUrl
	 * @return string
	 */
	private function inlineAssets( string $html, string $apiUrl ): string {
		$cache = [];
		$load = static function ( string $file ) use ( &$cache, $apiUrl ): ?string {
			if ( !array_key_exists( $file, $cache ) ) {
				$cache[$file] = self::fetchBackendFile( $apiUrl, $file );
			}
			return $cache[$file];
		};
		$names = implode( '|', array_map( 'preg_quote', array_keys( self::INLINE_ASSETS ) ) );

		$html = preg_replace_callback(
			'/<script\b([^>]*?)\ssrc=(["\'])\/?(' . $names . ')\2([^>]*)>\s*<\/script>/i',
			static function ( array $m ) use ( $load ) {
				$body = $load( $m[3] );
				if ( $body === null || self::INLINE_ASSETS[$m[3]] !== 'script' ) {
					return '<!-- KZChatbot: could not load ' . htmlspecialchars( $m[3] ) . ' -->';
				}
				// Nothing in the file may close the element early.
				$body = preg_replace( '/<\/script/i', '<\\\\/script', $body );
				return '<script' . $m[1] . $m[4] . ">\n" . $body . "\n</script>";
			},
			$html
		);

		return preg_replace_callback(
			'/<link\b[^>]*\shref=(["\'])\/?(' . $names . ')\1[^>]*>/i',
			static function ( array $m ) use ( $load ) {
				if ( !preg_match( '/\srel=(["\']?)stylesheet\1/i', $m[0] ) ) {
					return $m[0];
				}
				$body = $load( $m[2] );
				if ( $body === null || self::INLINE_ASSETS[$m[2]] !== 'style' ) {
					return '<!-- KZChatbot: could not load ' . htmlspecialchars( $m[2] ) . ' -->';
				}
				$body = preg_replace( '/<\/style/i', '<\\\\/style', $body );
				return "<style>\n" . $body . "\n</style>";
			},
			$html
		);
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
	 * @return string|null File contents, or null on failure
	 */
	private static function fetchBackendFile( string $apiUrl, string $file ): ?string {
		$url = rtrim( $apiUrl, '/' ) . '/' . ltrim( $file, '/' );
		$ch = curl_init( $url );
		curl_setopt_array( $ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 30,
		] );
		$body = curl_exec( $ch );
		$failed = curl_errno( $ch ) || curl_getinfo( $ch, CURLINFO_HTTP_CODE ) >= 400;
		if ( $body === false || $failed ) {
			return null;
		}
		return $body;
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
