<?php

namespace MediaWiki\Extension\KZChatbot\Rest;

use MediaWiki\MediaWikiServices;
use MediaWiki\Rest\Handler;
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

		return $this->forward( $backendPath, $isWrite );
	}

	/**
	 * Forward the request to the backend and relay its response verbatim.
	 *
	 * Streaming endpoints are relayed buffered (the full body is read, then sent);
	 * the batch runner's stream reader still parses it correctly, only progressive
	 * rendering is lost. Streaming is opt-in and off by default in that UI.
	 *
	 * @param string $backendPath
	 * @param bool $isWrite
	 * @return Response
	 */
	private function forward( string $backendPath, bool $isWrite ): Response {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$apiUrl = rtrim( $config->get( 'KZChatbotLlmApiUrl' ), '/' ) . '/' . ltrim( $backendPath, '/' );

		$method = strtoupper( $this->getRequest()->getMethod() );
		$headers = [ 'Accept: application/json' ];

		$ch = curl_init( $apiUrl );
		$curlOptions = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 180,
			CURLOPT_CUSTOMREQUEST => $method,
		];

		if ( $method !== 'GET' && $method !== 'HEAD' ) {
			$body = $this->getRequest()->getBody()->getContents();
			$curlOptions[CURLOPT_POSTFIELDS] = $body;
			$headers[] = 'Content-Type: application/json';
		}
		$curlOptions[CURLOPT_HTTPHEADER] = $headers;
		curl_setopt_array( $ch, $curlOptions );

		$responseBody = curl_exec( $ch );
		if ( curl_errno( $ch ) ) {
			$err = curl_error( $ch );
			curl_close( $ch );
			wfLogWarning( 'KZChatbot RAG proxy error for ' . $backendPath . ': ' . $err );
			return $this->errorResponse( 502, 'apiunreachable', 'The RAG backend is unreachable.' );
		}

		$httpCode = (int)curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$contentType = curl_getinfo( $ch, CURLINFO_CONTENT_TYPE ) ?: 'application/json';
		curl_close( $ch );

		$response = $this->getResponseFactory()->create();
		$response->setStatus( $httpCode ?: 200 );
		$response->setHeader( 'Content-Type', $contentType );
		$this->addNoStoreHeaders( $response );
		$response->setBody( new StringStream( $responseBody === false ? '' : $responseBody ) );
		return $response;
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
