<?php

namespace MediaWiki\Extension\KZChatbot;

use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Validator\Validator;
use RequestContext;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class ApiKZChatbotRateAnswer extends Handler {

	/** HTTP status when the RAG backend gave no HTTP answer. */
	private const UNREACHABLE_STATUS = 503;

	/** Seconds the client is told to wait before rating again. */
	private const UNREACHABLE_RETRY_AFTER = 30;

	/**
	 * Body schema. JSON-only, which is the core default for
	 * getSupportedRequestTypes(), so core answers any other Content-Type with 415.
	 * MediaWiki 1.43 rejects undeclared body fields, so every field the client
	 * sends must be listed here.
	 *
	 * @return array[]
	 */
	public function getBodyParamSettings(): array {
		return [
			'text' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_TYPE => 'string',
			],
			'thread_id' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_TYPE => 'string',
			],
			'conversation_id' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_TYPE => 'string',
			],
			// Legacy alias for conversation_id; the client still sends both.
			'answerId' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_TYPE => 'string',
			],
			'like' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_TYPE => 'boolean',
			],
		];
	}

	/** @inheritDoc */
	public function validate( Validator $restValidator ) {
		$feedbackCharacterLimit = KZChatbot::getGeneralSettings()['feedback_character_limit'];
		parent::validate( $restValidator );
		$validatedBody = $this->getValidatedBody();
		if ( $validatedBody && mb_strlen( $validatedBody['text'] ?? '' ) > $feedbackCharacterLimit ) {
			throw new LocalizedHttpException(
				new MessageValue( 'apierror-maxchars', [ 'text', $feedbackCharacterLimit ] ),
				400
			);
		}
	}

	/**
	 * Pass user rating on specified chatbot answer to the RAG backend.
	 *
	 * The backend's own status is passed through. When the backend gave no HTTP
	 * answer at all (unreachable, timed out), MWHttpRequest reports status 0;
	 * sent on as-is, nginx rejects it and answers a bare 502 (#91). Answer 503
	 * instead, with the REST framework's localized error body, which the client
	 * reads. Not 502 or 504: Cloudflare replaces an origin 502 or 504 with its
	 * own page (#77).
	 *
	 * @return \MediaWiki\Rest\Response
	 */
	public function execute() {
		$responseCode = $this->rateAnswer();
		if ( $responseCode < 100 || $responseCode > 599 ) {
			$response = $this->getResponseFactory()->createLocalizedHttpError(
				self::UNREACHABLE_STATUS,
				new MessageValue( 'kzchatbot-rate-backend-unreachable' ),
				[ 'errorKey' => 'kzchatbot-rate-backend-unreachable' ]
			);
			$response->setHeader( 'Retry-After', (string)self::UNREACHABLE_RETRY_AFTER );
			return $response;
		}
		$response = $this->getResponseFactory()->create();
		$response->setStatus( $responseCode );
		return $response;
	}

	/** @inheritDoc */
	public function needsWriteAccess() {
		return false;
	}

	/**
	 * Send the user's rating for an answer to the RAG backend.
	 * @return int HTTP status code from the backend, or 0 when it gave no answer
	 */
	private function rateAnswer() {
		$body = $this->getValidatedBody();
		$text = $body['text'] ?? '';
		$threadId = $body['thread_id'] ?? '';
		// conversation_id is the per-turn id; answerId is the legacy alias.
		$conversationId = $body['conversation_id'] ?? $body['answerId'] ?? '';
		$like = $body['like'] ?? null;
		// The RAG /rating endpoint expects a string score: '1' = like, '0' = dislike.
		$score = $like === true ? '1' : ( $like === false ? '0' : '' );
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'KZChatbot' );

		// /rating takes its arguments as query parameters, not a JSON body.
		$apiUrl = $config->get( 'KZChatbotLlmApiUrl' ) . '/rating?' . http_build_query( [
			'thread_id' => $threadId,
			'conversation_id' => $conversationId,
			'score' => $score,
			'text' => $text,
		] );

		// A rating is quick: keep core's default total timeout ($wgHTTPTimeout),
		// but never past the edge's own limit, and fail fast when unreachable.
		$services = MediaWikiServices::getInstance();
		$timeout = BackendTimeouts::total( $services->getMainConfig()->get( MainConfigNames::HTTPTimeout ) );
		$httpRequestFactory = $services->getHttpRequestFactory();
		$req = $httpRequestFactory->create( $apiUrl, [
			'method' => 'POST',
			'timeout' => $timeout,
			'connectTimeout' => BackendTimeouts::connect(
				$config->get( 'KZChatbotLlmApiConnectTimeout' ), $timeout
			),
			'originalRequest' => RequestContext::getMain()->getRequest(),
		], __METHOD__ );
		$req->setHeader( 'X-Forwarded-For', RequestContext::getMain()->getRequest()->getIP() );

		$status = $req->execute();
		$code = $req->getStatus();
		if ( !$status->isOK() ) {
			// Message keys only: an HTTP error's text can quote the request URL,
			// whose query string carries the reader's feedback.
			KZChatbot::getLogger()->warning(
				'RAG backend rating request failed (HTTP {code}): {errors}',
				[
					'code' => $code,
					'errors' => array_map(
						static fn ( $message ) => $message->getKey(),
						$status->getMessages()
					),
				]
			);
		}
		return $code;
	}

}
