<?php

namespace MediaWiki\Extension\KZChatbot\Rest;

use MediaWiki\MediaWikiServices;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\StringStream;
use RequestContext;

/**
 * Serves the RAG backend's own HTML interfaces (admin / batch runner) from the
 * MediaWiki origin, inside an iframe.
 *
 * Serving from the MW origin (rather than iframing the backend directly) is what
 * makes the permission model real: the page's same-origin fetches all land on
 * RagProxyHandler, carry the MW session cookie, and are re-checked there. The
 * backend itself never needs to be reachable by the user's browser.
 *
 * Injected into the fetched HTML:
 *  - window.KZ_PROXY: proxy base URL, the user's CSRF token, and capability flags;
 *  - a fetch() shim that rewrites the backend's root-relative endpoint calls to the
 *    MW proxy and attaches the CSRF token on writes;
 *  - CSS/JS that hides edit / destructive controls from users who lack the right.
 *
 * Route config (extension.json):
 *   "backendFile" - file to fetch from the backend (e.g. "admin.html")
 *   "viewRight"   - right required to load this interface at all
 *   "editRight"   - (admin) right that unlocks the config editing controls
 */
class RagUiHandler extends Handler {

	/** Backend endpoints the shim rewrites to the MW proxy. */
	private const PROXIED_PATHS = [
		'get_config', 'set_config', 'clean_redis_history',
		'ping/redis', 'ping/llm_manager', 'ping/es_docker',
		'search/stream', 'search', 'rating',
	];

	/** @inheritDoc */
	public function execute() {
		$routeConfig = $this->getConfig();
		$backendFile = $routeConfig['backendFile'] ?? '';
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

		$html = $this->fetchBackendFile( $mainConfig->get( 'KZChatbotLlmApiUrl' ), $backendFile );
		if ( $html === null ) {
			return $this->errorResponse( 502, 'The RAG backend is unreachable.' );
		}

		// "Edit" only applies to the admin interface (the one with a config form
		// and a Danger Zone). The batch runner reuses class names like
		// .main-content, so its controls must never be hidden by the edit logic.
		$hasEditConcept = (bool)$editRight;
		$canEdit = $hasEditConcept && $pm->userHasRight( $user, $editRight );
		$canCleanRedis = $hasEditConcept && $pm->userHasRight( $user, 'kzchatbot-clean-redis-history' );

		$restPath = $mainConfig->get( 'RestPath' );
		$proxyBase = $restPath . '/kzchatbot/v0/ragproxy/';

		// Point the batch runner's page-title autocomplete at the local wiki API
		// instead of the backend's hard-coded production URL.
		$localApi = $mainConfig->get( 'ScriptPath' ) . '/api.php';
		$html = str_replace( 'https://www.kolzchut.org.il/w/he/api.php', $localApi, $html );

		$kzProxy = [
			'base' => $proxyBase,
			'csrf' => $user->getEditToken(),
			'canEdit' => $canEdit,
			'canCleanRedis' => $canCleanRedis,
		];

		$injection = "\n<script>window.KZ_PROXY = " . json_encode( $kzProxy, JSON_UNESCAPED_SLASHES )
			. ";</script>\n"
			. "<script>" . $this->buildShim() . "</script>\n"
			. "<style>" . $this->buildHideCss( $hasEditConcept, $canEdit, $canCleanRedis ) . "</style>\n";

		// Inject before </head> so the fetch shim is installed before the app's
		// scripts (which run at end of <body>) make any request. Use str_replace,
		// not preg_replace: the injected JSON contains backslash escapes (the CSRF
		// edit token ends in "+\"), which a regex replacement string would mangle.
		$html = str_replace( '</head>', $injection . '</head>', $html );

		$response = $this->getResponseFactory()->create();
		$response->setStatus( 200 );
		$response->setHeader( 'Content-Type', 'text/html; charset=utf-8' );
		$response->setHeader( 'Cache-Control', 'no-store, max-age=0, must-revalidate' );
		$response->setHeader( 'Pragma', 'no-cache' );
		// CSP scoped to this iframe response: allow the backend HTML's CDN deps and
		// inline/eval (Babel standalone) without touching the wiki-wide CSP.
		$response->setHeader( 'Content-Security-Policy',
			"default-src 'self'; "
			. "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://unpkg.com https://cdnjs.cloudflare.com; "
			. "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
			. "font-src 'self' data: https://cdnjs.cloudflare.com; "
			. "img-src 'self' data:; "
			. "connect-src 'self'; "
			. "frame-ancestors 'self';"
		);
		$response->setBody( new StringStream( $html ) );
		return $response;
	}

	/**
	 * @param string $apiUrl
	 * @param string $file
	 * @return string|null HTML, or null on failure
	 */
	private function fetchBackendFile( string $apiUrl, string $file ): ?string {
		$url = rtrim( $apiUrl, '/' ) . '/' . ltrim( $file, '/' );
		$ch = curl_init( $url );
		curl_setopt_array( $ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 30,
		] );
		$body = curl_exec( $ch );
		$failed = curl_errno( $ch ) || curl_getinfo( $ch, CURLINFO_HTTP_CODE ) >= 400;
		curl_close( $ch );
		if ( $body === false || $failed ) {
			return null;
		}
		return $body;
	}

	/**
	 * The fetch() shim, injected inline into the iframe document.
	 * @return string
	 */
	private function buildShim(): string {
		$paths = json_encode( self::PROXIED_PATHS, JSON_UNESCAPED_SLASHES );
		return <<<JS
(function () {
	var P = window.KZ_PROXY || {};
	var PROXIED = $paths;
	var WRITES = { set_config: 1, clean_redis_history: 1, search: 1, 'search/stream': 1, rating: 1 };
	var orig = window.fetch.bind( window );
	window.fetch = function ( input, init ) {
		init = init || {};
		var url = ( typeof input === 'string' ) ? input : ( input && input.url ) || '';
		var m = /^\/([a-z_]+(?:\/[a-z_]+)?)(\?.*)?$/i.exec( url );
		if ( m && PROXIED.indexOf( m[1] ) !== -1 ) {
			var path = m[1];
			var target = P.base + path + ( m[2] || '' );
			init.credentials = 'same-origin';
			if ( WRITES[path] ) {
				var h = init.headers || {};
				if ( typeof Headers !== 'undefined' && h instanceof Headers ) {
					h.set( 'X-Csrf-Token', P.csrf );
				} else {
					h = Object.assign( {}, h, { 'X-Csrf-Token': P.csrf } );
				}
				init.headers = h;
			}
			input = ( typeof input === 'string' ) ? target : new Request( target, input );
		}
		return orig( input, init );
	};
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
