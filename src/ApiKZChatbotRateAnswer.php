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
			'rating' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'integer',
			],
			'text' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
			'answerId' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
			'like' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'boolean',
			],
		] );
	}

	/**
	 * Pass user rating on specified chatbot answer to the ChatGPT API.
	 * @return \MediaWiki\Rest\Response
	 */
  public function execute() {
		// return $this->rateAnswer();
		$response = $this->getResponseFactory()->create();
		$response->setStatus( 200 );
		return $response;
  }

	public function needsWriteAccess() {
		return false;
	}

	private function rateAnswer() {
		$body = $this->getValidatedBody();
		$rating = $body['rating'];
		$text = $body['text'];
		$answerId = $body['answerId'];
		$like = $body['like'];
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'KZChatbot' );
		$apiUrl = $config->get( 'KZChatbotLlmApiUrl' ) . '/rate';
		$client = new \GuzzleHttp\Client();
		$result = $client->post( $apiUrl, [
			'headers' => [
				'X-FORWARDED-FOR' => $_SERVER['REMOTE_ADDR'],
			],
			'json' => [
				'rating' => $rating,
				'free_text' => $text,
				'conversation_id' => $answerId,
				'like' => $like,
			]
		] );
		$response = json_decode( $result->getBody()->getContents() );
		return $response;
	}

}
