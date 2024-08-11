<?php

namespace MediaWiki\Extension\KZChatbot;

use MediaWiki\Rest\Handler;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use Wikimedia\ParamValidator\ParamValidator;

class ApiKZChatbotSubmitQuestion extends Handler {

	/**
	 * @var string $uuid The UUID associated with the user.
	 */
	private $uuid;

	/**
	 * @var string $question The question to be submitted to the ChatGPT API.
	 */
	private $question;

	private function callChatGPTAPI() {
		$question = $this->question;
		$uuid = $this->uuid;
		KZChatbot::useQusetion($uuid);
		$apiUrl = 'http://20.15.205.25/search';
		$client = new \GuzzleHttp\Client();
		$result = $client->post($apiUrl, [
			'headers' => [
				'X-FORWARDED-FOR' => $_SERVER['REMOTE_ADDR'],
			],
			'json' => [
				'query' => $question,
			]
		]);
		$response = json_decode($result->getBody()->getContents());
		$docs = array_map(function ($doc) {
			return [
				'title' => $doc->title,
				'url' => $doc->url,
			];
		}, $response[0]);
		return [
			'llmResult' => $response[1],
			'docs' => $docs,
			'conversationId' => $response->conversation_id,
		];
	}

	/**
	 * Pass user question to ChatGPT API, checking first that user hasn't exceeded daily limit.
	 * Return answer from ChatGPT API.
	 */
	public function execute() {
		$body = $this->getValidatedBody();
		$this->uuid = $body['uuid'];
		$this->validateUser();
		$this->question = $body['text'];
		return $this->callChatGPTAPI();
	}

	public function getBodyValidator($contentType) {
		if ($contentType !== 'application/json') {
			throw new HttpException(
				"Unsupported Content-Type",
				415,
				['content_type' => $contentType]
			);
		}

		return new JsonBodyValidator([
			'text' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'uuid' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		]);
	}

	/**
	 * Validate the request parameters.
	 */
	private function validateUser() {
		$uuid = $this->uuid;
		$userData = KZChatbot::getUserData($uuid);
		if ($userData === null) {
			throw new HttpException('User not found', 404);
		}
		$questionsPermitted = KZChatbot::getQuestionsPermitted($uuid);
		if ($questionsPermitted <= 0) {
			throw new HttpException('Daily limit exceeded', 429);
		}
	}
}
