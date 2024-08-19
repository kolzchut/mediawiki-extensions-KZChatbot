<?php

namespace MediaWiki\Extension\KZChatbot;

use MediaWiki\MediaWikiServices;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use Wikimedia\ParamValidator\ParamValidator;

class ApiKZChatbotRateAnswer extends Handler {

	/**
	 * @param string $contentType
	 * @return array
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
			'answerClassification' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_TYPE => 'string',
			],
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

	/**
	 * Pass user rating on specified chatbot answer to the ChatGPT API.
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
				'rating' => 1,
				'free_text' => $text,
				'answer_classification' => $answerClassification,
				'conversation_id' => $answerId,
				'like' => $like,
			]
		] );
		return $result->getStatusCode();
	}

}
