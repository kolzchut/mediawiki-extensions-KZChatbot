<?php

namespace MediaWiki\Extension\KZChatbot;

use MediaWiki\MediaWikiServices;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use RequestContext;
use Wikimedia\ParamValidator\ParamValidator;

class ApiKZChatbotSubmitQuestion extends Handler {

	/**
	 * @var string The UUID associated with the user.
	 */
	private $uuid;

	/**
	 * @var string The question to be submitted to the ChatGPT API.
	 */
	private $question;

	/**
	 * @var string currently the referring page
	 */
	private $referrer;

	/**
	 * Pass user question to ChatGPT API, checking first that user hasn't exceeded daily limit.
	 * Return answer from ChatGPT API.
	 * @return array
	 */
	public function execute() {
		$body = $this->getValidatedBody();
		$this->uuid = $body['uuid'];
		$this->validateUser();
		$this->question = $body['text'];
		$this->referrer = $body['referrer'];

		$questionCharacterLimit = KZChatbot::getGeneralSettings()['question_character_limit'];
		if ( mb_strlen( $this->question ) > $questionCharacterLimit ) {
			throw new HttpException( Slugs::getSlug( 'question_character_limit' ), 413 );
		}
		$bannedWords = BannedWord::getAll();
		foreach ( $bannedWords as $word ) {
			// Add the 'u' modifier when testing a regular expression
			if ( strpos( $this->question, $word->getPattern() ) !== false ||
				preg_match( $word->getPattern() . 'u', $this->question )
			) {
				$message = $word->getReplyMessage() ?: Slugs::getSlug( 'banned_word_found' );
				throw new HttpException( $message, 403 );
			}
		}
		$answer = $this->generateAnswer();
		if ( $answer['llmResult'] === null ) {
			wfDebugLog( 'KZChatbot', 'ChatGPT API returned null. Question: ' . $this->question . "\nAnswer: " . print_r( $answer, true ) );
			throw new HttpException( Slugs::getSlug( 'general_error' ), 500 );
		}
		return $answer;
	}

	private function generateAnswer() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'KZChatbot' );
		$question = $this->question;
		$uuid = $this->uuid;
		KZChatbot::useQuestion( $uuid );
		$apiUrl = $config->get( 'KZChatbotLlmApiUrl' ) . '/search';
		$client = new \GuzzleHttp\Client();
		try {
			$result = $client->post( $apiUrl, [
				'headers' => [
					'X-FORWARDED-FOR' => RequestContext::getMain()->getRequest()->getIP(),
				],
				'json' => [
					'query' => $question,
					'asked_from' => $this->referrer

				]
			] );
		} catch ( \GuzzleHttp\Exception\GuzzleException $e ) {
			wfDebugLog( 'KZChatbot', 'ChatGPT API request failed (' . $e->getCode() . '): ' . $e->getMessage() );
			throw new HttpException( Slugs::getSlug( 'general_error' ), 500 );
		}
		$response = json_decode( $result->getBody()->getContents() );
		$docs = array_map( static function ( $doc ) {
			return [
				'title' => $doc->title,
				'url' => $doc->url,
			];
		}, $response->docs );
		return [
			'llmResult' => $response->gpt_result,
			'docs' => $docs,
			'conversationId' => $response->conversation_id,
		];
	}

	/**
	 * @param string $contentType MIME Type
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
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'uuid' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		] );
	}

	/** @inheritDoc */
	public function needsWriteAccess(): bool {
		return true;
	}

	/**
	 * Validate the request parameters.
	 * @throws HttpException
	 */
	private function validateUser() {
		$uuid = $this->uuid;
		$userData = KZChatbot::getUserData( $uuid );
		if ( $userData === null ) {
			throw new HttpException( 'User not found', 404 );
		}

		if ( KZChatbot::getQuestionsPermitted( $uuid ) <= 0 ) {
			throw new HttpException( Slugs::getSlug( 'questions_daily_limit' ), 429 );
		}
	}
}
