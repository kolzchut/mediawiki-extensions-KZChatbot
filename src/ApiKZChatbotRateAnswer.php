<?php

namespace MediaWiki\Extension\KZChatbot;

use MediaWiki\MediaWikiServices;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Validator\Validator;
use RequestContext;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class ApiKZChatbotRateAnswer extends Handler {

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
	 * @return \MediaWiki\Rest\Response
	 */
	public function execute() {
		$responseCode = $this->rateAnswer();
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
	 * @return int HTTP status code from the backend
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

		$httpRequestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
		$req = $httpRequestFactory->create( $apiUrl, [
			'method' => 'POST',
			'originalRequest' => RequestContext::getMain()->getRequest(),
		], __METHOD__ );
		$req->setHeader( 'X-Forwarded-For', RequestContext::getMain()->getRequest()->getIP() );

		$req->execute();
		return $req->getStatus();
	}

}
