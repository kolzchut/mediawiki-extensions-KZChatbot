<?php

namespace MediaWiki\Extension\KZChatbot\Rest;

use MediaWiki\MediaWikiServices;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\RequestInterface;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\StringStream;
use RequestContext;

/**
 * Authenticating reverse proxy for the RAG backend's own admin/testing endpoints.
 *
 * The backend has no authentication of its own, so every request is gated here:
 *  - the route's required user right is enforced against the current MediaWiki user;
 *  - for state-changing methods, a CSRF edit token is required unless the session
 *    provider is already safe against CSRF (e.g. OAuth) — mirroring core's EditHandler.
 *
 * Each route in extension.json supplies its own config:
 *   "backendPath" - path appended to $wgKZChatbotLlmApiUrl (e.g. "set_config")
 *   "right"       - the user right required to call it
 *   "write"       - true for state-changing endpoints (require CSRF token)
 *
 * Request bodies are JSON, parsed and validated by core like any other REST body,
 * and relayed to the backend as sent.
 */
class RagProxyHandler extends Handler {

	/** @inheritDoc */
	public function execute() {
		$routeConfig = $this->getConfig();
		$backendPath = $routeConfig['backendPath'] ?? '';
		$right = $routeConfig['right'] ?? '';
		$isWrite = !empty( $routeConfig['write'] );

		$context = RequestContext::getMain();
		$user = $context->getUser();
		$services = MediaWikiServices::getInstance();

		// Permission gate — the real access control, independent of what the iframe shows.
		if ( !$right || !$services->getPermissionManager()->userHasRight( $user, $right ) ) {
			return $this->errorResponse( 403, 'permissiondenied', 'You are not allowed to use this endpoint.' );
		}

		// CSRF protection for state-changing requests. Cookie sessions report
		// safeAgainstCsrf() === false, so we require a valid edit token there.
		if ( $isWrite ) {
			$session = $context->getRequest()->getSession();
			if ( !$session->getProvider()->safeAgainstCsrf() ) {
				$token = $this->getRequest()->getHeaderLine( 'X-Csrf-Token' );
				if ( !$user->matchEditToken( $token ) ) {
					return $this->errorResponse( 403, 'badtoken', 'Invalid or missing CSRF token.' );
				}
			}
		}

		// The raw body rather than the parsed array, which would turn an empty JSON
		// object into an empty list.
		$body = $this->getRequest()->getParsedBody() === null
			? null
			: (string)$this->getRequest()->getBody();

		return $this->forward( $backendPath, $body );
	}

	/**
	 * Forward the request to the backend and relay its response verbatim.
	 *
	 * Streaming endpoints are relayed buffered (the full body is read, then sent);
	 * the batch runner's stream reader still parses it correctly, only progressive
	 * rendering is lost. Streaming is opt-in and off by default in that UI.
	 *
	 * @param string $backendPath
	 * @param string|null $body JSON to send, or null if the request had no body
	 * @return Response
	 */
	private function forward( string $backendPath, ?string $body ): Response {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$apiUrl = rtrim( $config->get( 'KZChatbotLlmApiUrl' ), '/' ) . '/' . ltrim( $backendPath, '/' );

		// Preserve the query string (e.g. /rating?thread_id=…&score=…).
		$query = $this->getRequest()->getUri()->getQuery();
		if ( $query !== '' ) {
			$apiUrl .= '?' . $query;
		}

		$method = strtoupper( $this->getRequest()->getMethod() );
		$headers = [ 'Accept: application/json' ];

		$ch = curl_init( $apiUrl );
		$curlOptions = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 180,
			CURLOPT_CUSTOMREQUEST => $method,
		];

		if ( $method !== 'GET' && $method !== 'HEAD' ) {
			$curlOptions[CURLOPT_POSTFIELDS] = $body ?? '';
			$headers[] = 'Content-Type: application/json';
		}
		$curlOptions[CURLOPT_HTTPHEADER] = $headers;
		curl_setopt_array( $ch, $curlOptions );

		$responseBody = curl_exec( $ch );
		if ( curl_errno( $ch ) ) {
			$err = curl_error( $ch );
			wfLogWarning( 'KZChatbot RAG proxy error for ' . $backendPath . ': ' . $err );
			return $this->errorResponse( 502, 'apiunreachable', 'The RAG backend is unreachable.' );
		}

		$httpCode = (int)curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$contentType = curl_getinfo( $ch, CURLINFO_CONTENT_TYPE ) ?: 'application/json';

		$response = $this->getResponseFactory()->create();
		$response->setStatus( $httpCode ?: 200 );
		$response->setHeader( 'Content-Type', $this->safeContentType( $contentType ) );
		$response->setHeader( 'X-Content-Type-Options', 'nosniff' );
		$this->addNoStoreHeaders( $response );
		$response->setBody( new StringStream( $responseBody === false ? '' : $responseBody ) );
		return $response;
	}

	/**
	 * The backend's Content-Type, if it is one the proxied endpoints legitimately
	 * return; text/plain otherwise.
	 *
	 * This response is served from the wiki's origin to users holding chatbot admin
	 * rights, so relaying an arbitrary type would let backend output that came back
	 * as text/html (an echoed query, a stored config value, an error page from
	 * anything in between) run as a wiki page with the admin's session. Every
	 * proxied route returns JSON, except search/stream, which is an event stream.
	 * The UIs read bodies without checking the type, so relabelling is harmless.
	 *
	 * @param string $contentType
	 * @return string
	 */
	private function safeContentType( string $contentType ): string {
		$mimeType = strtolower( trim( explode( ';', $contentType, 2 )[0] ) );
		if ( $mimeType === 'application/json' || $mimeType === 'text/event-stream' ) {
			return $contentType;
		}
		return 'text/plain; charset=utf-8';
	}

	/**
	 * @param int $status
	 * @param string $code
	 * @param string $message
	 * @return Response
	 */
	private function errorResponse( int $status, string $code, string $message ): Response {
		$response = $this->getResponseFactory()->create();
		$response->setStatus( $status );
		$response->setHeader( 'Content-Type', 'application/json' );
		$this->addNoStoreHeaders( $response );
		$response->setBody( new StringStream( json_encode( [
			'error' => $code,
			'errorKey' => 'rest-' . $code,
			'message' => $message,
		] ) ) );
		return $response;
	}

	/**
	 * Responses carry sensitive, permission-scoped data and must never be cached.
	 * @param Response $response
	 */
	private function addNoStoreHeaders( Response $response ): void {
		$response->setHeader( 'Cache-Control', 'no-store, max-age=0, must-revalidate' );
		$response->setHeader( 'Pragma', 'no-cache' );
	}

	/**
	 * rating and clean_redis_history POST with no body, but browsers still send
	 * Content-Length: 0, which core counts as a body and its JSON parser rejects.
	 *
	 * @inheritDoc
	 */
	public function parseBodyData( RequestInterface $request ): ?array {
		if ( $request->getHeaderLine( 'Content-Length' ) === '0' ) {
			return null;
		}
		return parent::parseBodyData( $request );
	}

	/** @inheritDoc */
	public function needsWriteAccess() {
		// We do our own CSRF + permission gating; the proxied calls don't write to
		// the MediaWiki DB, so don't trip the read-only-mode guard for GET pings.
		return false;
	}

	/** @inheritDoc */
	public function needsReadAccess() {
		return false;
	}
}
