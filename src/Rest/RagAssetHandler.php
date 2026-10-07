<?php

namespace MediaWiki\Extension\KZChatbot\Rest;

use MediaWiki\MediaWikiServices;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\StringStream;
use RequestContext;

/**
 * Serves a static JS/CSS file that one of the RAG backend's HTML interfaces loads.
 *
 * Each file is its own literal route in extension.json, next to the interface
 * routes (/kzchatbot/v0/ragui/<file>), so that a relative <script src="x.js"> in
 * the backend HTML resolves here with no rewriting. The route table is therefore
 * the allowlist: only files declared there can be fetched from the backend.
 * (A single /ragui/{file} route is not possible - core's PathMatcher rejects it
 * as conflicting with the literal /ragui/admin, /ragui/index etc.)
 *
 * Route config (extension.json):
 *   "backendFile" - file to fetch from the backend (e.g. "prompt-view.js")
 *   "contentType" - Content-Type to serve it with
 *   "viewRight"   - right required to fetch it
 */
class RagAssetHandler extends Handler {

	/** @inheritDoc */
	public function execute() {
		$routeConfig = $this->getConfig();
		$backendFile = $routeConfig['backendFile'] ?? '';
		$contentType = $routeConfig['contentType'] ?? '';
		$viewRight = $routeConfig['viewRight'] ?? '';

		$user = RequestContext::getMain()->getUser();
		$services = MediaWikiServices::getInstance();

		if ( !$viewRight || !$services->getPermissionManager()->userHasRight( $user, $viewRight ) ) {
			return $this->plainResponse( 403, 'You are not allowed to fetch this file.' );
		}
		if ( !$backendFile || !$contentType ) {
			return $this->plainResponse( 500, 'Misconfigured asset route.' );
		}

		$body = RagUiHandler::fetchBackendFile(
			$services->getMainConfig()->get( 'KZChatbotLlmApiUrl' ),
			$backendFile
		);
		if ( $body === null ) {
			return $this->plainResponse( 502, 'The RAG backend is unreachable.' );
		}

		$response = $this->getResponseFactory()->create();
		$response->setStatus( 200 );
		$response->setHeader( 'Content-Type', $contentType );
		$response->setHeader( 'X-Content-Type-Options', 'nosniff' );
		// Same as the HTML that loads it: the backend's files are versionless, and a
		// cached stale script against fresh HTML would be a confusing mismatch.
		$response->setHeader( 'Cache-Control', 'no-store, max-age=0, must-revalidate' );
		$response->setBody( new StringStream( $body ) );
		return $response;
	}

	/**
	 * @param int $status
	 * @param string $message
	 * @return \MediaWiki\Rest\Response
	 */
	private function plainResponse( int $status, string $message ) {
		$response = $this->getResponseFactory()->create();
		$response->setStatus( $status );
		$response->setHeader( 'Content-Type', 'text/plain; charset=utf-8' );
		$response->setHeader( 'Cache-Control', 'no-store, max-age=0, must-revalidate' );
		$response->setBody( new StringStream( $message ) );
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
