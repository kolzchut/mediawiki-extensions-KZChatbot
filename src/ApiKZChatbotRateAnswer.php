<?php

namespace MediaWiki\Extension\KZChatbot;

use MediaWiki\MediaWikiServices;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\Rest\Validator\Validator;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class ApiKZChatbotRateAnswer extends Handler {

	/**
	 * @param string $contentType
	 * @return JsonBodyValidator
	 */
	public function getBodyValidator( $contentType ) {
		if ( $contentType !== 'application/json' ) {
			throw new HttpException(
				"Unsupported Content-Type",
				415,
				[ 'content_type' => $contentType ]
			);
		}

		return new JsonBodyValidator( [
			'text' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_TYPE => 'string',
			],
			'answerId' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
			'like' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_TYPE => 'boolean',
			],
		] );
	}

	/** @inheritDoc */
	public function validate( Validator $restValidator ) {
		$feedbackCharacterLimit = KZChatbot::getGeneralSettings()['feedback_character_limit'];
		parent::validate( $restValidator );
		$validatedBody = $this->getValidatedBody();
		if ( $validatedBody && mb_strlen( $validatedBody['text'] ) > $feedbackCharacterLimit ) {
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

	public function needsWriteAccess() {
		return false;
	}

	private function rateAnswer() {
		$body = $this->getValidatedBody();
		$answerClassification = $body['answerClassification'];
		$text = $body['text'];
		$answerId = $body['answerId'];
		$like = $body['like'];
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'KZChatbot' );
		$apiUrl = $config->get( 'KZChatbotLlmApiUrl' ) . '/rating';
		$client = new \GuzzleHttp\Client();
		$result = $client->post( $apiUrl, [
			'headers' => [
				'X-FORWARDED-FOR' => $_SERVER['REMOTE_ADDR'],
			],
			'json' => [
				'free_text' => $text,
				'conversation_id' => $answerId,
				'like' => $like,
			]
		] );
		return $result->getStatusCode();
	}

}
