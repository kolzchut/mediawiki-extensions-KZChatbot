<?php

namespace MediaWiki\Extension\KZChatbot;

use MediaWiki\Extension\ChatbotRagContent\ChatbotRagContent;
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
	 * @var string The question to be submitted to the RAG backend.
	 */
	private $question;

	/**
	 * @var string currently the referring page
	 */
	private $referrer;

	/**
	 * Pass user question to RAG backend, checking first that user hasn't exceeded daily limit.
	 * Return answer from RAG backend.
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
			KZChatbot::getLogger()->error( 'RAG backend returned null. Question: ' . $this->question . "\nAnswer: " . print_r( $answer, true ) );
			throw new HttpException( Slugs::getSlug( 'general_error' ), 500 );
		}
		return $answer;
	}

	/**
	 * @throws HttpException
	 * @throws \MWException
	 */
	private function generateAnswer() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'KZChatbot' );
		$question = $this->question;
		$uuid = $this->uuid;
		KZChatbot::useQuestion( $uuid );
		$apiUrl = $config->get( 'KZChatbotLlmApiUrl' ) . '/search';
		$client = new \GuzzleHttp\Client();
		$params = [
			'query' => $question,
			'asked_from' => strval( $this->referrer )
		];

		$sendPageId = $config->get( 'KZChatbotSendPageId' );
		if ( $sendPageId ) {
			$relevantPageId = $this->getRelevantPageId();
			if ( $relevantPageId !== null ) {
				// Add page ID as additional context for RAG to improve answer relevance
				$params['page_id'] = strval( $relevantPageId );
			}
		}

		try {
			$result = $client->post( $apiUrl, [
				'headers' => [
					'X-FORWARDED-FOR' => RequestContext::getMain()->getRequest()->getIP(),
				],
				'json' => $params
			] );
		} catch ( \GuzzleHttp\Exception\GuzzleException $e ) {
			KZChatbot::getLogger()->error( 'RAG backend request failed (' . $e->getCode() . '): ' . $e->getMessage() );
			throw new HttpException( Slugs::getSlug( 'general_error' ), 500 );
		}
		$response = json_decode( $result->getBody()->getContents() );
		$docs = array_map( static function ( $doc ) {
			return [
				'title' => $doc->title,
				'url' => $doc->url,
			];
		}, $response->docs );

		$answer = empty( $docs ) ? Slugs::getSlug( 'returning_links_empty' ) : $response->gpt_result;
		return [
			'llmResult' => $answer,
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
			'referrer' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true
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

	/**
	 * Check if referrer contains a page ID and if it's relevant for RAG content
	 * @return int|null Page ID if relevant, null otherwise
	 */
	private function getRelevantPageId(): ?int {
		if ( !\ExtensionRegistry::getInstance()->isLoaded( 'ChatbotRagContent' ) ) {
			return null;
		}

		$pageId = null;
		$title = null;

		// Check if referrer is a page ID
		if ( is_numeric( $this->referrer ) && (int)$this->referrer > 0 ) {
			$pageId = (int)$this->referrer;
			$title = \Title::newFromID( $pageId );
		}

		if ( $title && ChatbotRagContent::isRelevantTitle( $title ) ) {
			return $pageId;
		}

		return null;
	}
}
