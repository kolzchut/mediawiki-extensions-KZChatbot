<?php

namespace MediaWiki\Extension\KZChatbot;

use MediaWiki\Extension\ChatbotRagContent\ChatbotRagContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MWException;
use RequestContext;
use Throwable;
use Title;
use Wikimedia\ParamValidator\ParamValidator;

class ApiKZChatbotSubmitQuestion extends Handler {

	/**
	 * @var string The UUID associated with the user.
	 */
	private string $uuid;

	/**
	 * @var string The question to be submitted to the RAG backend.
	 */
	private string $question;

	/**
	 * @var string currently the referring page
	 */
	private string $referrer;

	/**
	 * Pass user question to RAG backend, checking first that user hasn't exceeded daily limit.
	 * Return answer from RAG backend.
	 *
	 * Acts as this endpoint's error boundary. An uncaught Throwable here would be
	 * turned by MediaWiki's REST layer into a 500 whose body reads
	 * `Error: exception of type <Class>` (core's wording when
	 * $wgShowExceptionDetails is false), and the React client renders the response
	 * message verbatim into the chat window — so a PHP bug reaches the reader as
	 * an English class name. Everything unexpected is therefore logged on our own
	 * channel and re-thrown as the operator-authored `general_error` slug.
	 *
	 * @return array
	 * @throws HttpException
	 * @throws MWException
	 */
	public function execute(): array {
		try {
			return $this->answerQuestion();
		} catch ( HttpException $e ) {
			// Deliberate: the message is an operator-authored slug, meant for the
			// reader (daily limit, banned word, character limit, general error).
			throw $e;
		} catch ( Throwable $e ) {
			KZChatbot::getLogger()->error(
				'Unhandled error answering a chatbot question: {exception_class}: {exception_message}',
				[
					'exception' => $e,
					'exception_class' => get_class( $e ),
					'exception_message' => $e->getMessage(),
				]
			);
			throw new HttpException( $this->generalErrorMessage(), 500 );
		}
	}

	/**
	 * The reader-facing text for an error we did not plan for.
	 *
	 * Deliberately does not trust the database. `Slugs::getSlug()` reads
	 * `kzchatbot_text` and the general settings, so on the one fault most likely
	 * to reach the catch-all above — a database failure — looking the slug up
	 * would throw a second time, escape execute(), and hand the reader the raw
	 * `Error: exception of type DBQueryError` this boundary exists to prevent.
	 * `Slugs::getDefaultSlugs()` is a compiled-in array, so it always answers.
	 *
	 * @return string
	 */
	private function generalErrorMessage(): string {
		try {
			$slug = Slugs::getSlug( 'general_error' );
			if ( is_string( $slug ) && $slug !== '' ) {
				return $slug;
			}
		} catch ( Throwable $e ) {
			// Fall through to the compiled-in default.
		}

		return Slugs::getDefaultSlugs()['general_error'];
	}

	/**
	 * @return array
	 * @throws HttpException
	 * @throws MWException
	 */
	private function answerQuestion(): array {
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
			if ( str_contains( $this->question, $word->getPattern() ) ||
				preg_match( $word->getPattern() . 'u', $this->question )
			) {
				$message = $word->getReplyMessage() ?: Slugs::getSlug( 'banned_word_found' );
				throw new HttpException( $message, 403 );
			}
		}
		$answer = $this->generateAnswer();
		if ( $answer['llmResult'] === null ) {
			$logMsg = 'RAG backend returned null. Question: ' . $this->question
				. "\nAnswer: " . print_r( $answer, true );
			KZChatbot::getLogger()->error( $logMsg );
			throw new HttpException( $this->generalErrorMessage(), 500 );
		}
		return $answer;
	}

	/**
	 * @throws HttpException
	 * @throws MWException
	 */
	private function generateAnswer(): array {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'KZChatbot' );
		$question = $this->question;
		$uuid = $this->uuid;
		KZChatbot::useQuestion( $uuid );
		$apiUrl = $config->get( 'KZChatbotLlmApiUrl' ) . '/search';
		$params = [
			'query' => $question,
			'asked_from' => $this->referrer
		];

		$sendPageId = $config->get( 'KZChatbotSendPageId' );
		if ( $sendPageId ) {
			$relevantPageId = $this->getRelevantPageId();
			if ( $relevantPageId !== null ) {
				$params['page_id'] = strval( $relevantPageId );
			}
		}

		$httpRequestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
		$req = $httpRequestFactory->create( $apiUrl, [
			'method' => 'POST',
			'postData' => json_encode( $params ),
			'originalRequest' => RequestContext::getMain()->getRequest(),
		], __METHOD__ );
		$req->setHeader( 'Content-Type', 'application/json' );
		$req->setHeader( 'X-Forwarded-For', RequestContext::getMain()->getRequest()->getIP() );

		$status = $req->execute();
		if ( !$status->isOK() ) {
			KZChatbot::getLogger()->error(
				'RAG backend request failed: ' . $status->getWikiText( false, false, 'en' )
			);
			throw new HttpException( $this->generalErrorMessage(), 500 );
		}
		$response = json_decode( $req->getContent() );
		$docs = array_map( static function ( $doc ) {
			return [
				'title' => $doc->title,
				'url' => $doc->url,
			];
		}, $response->docs );

		// Check config to determine behavior when no links are found
		$replaceAnswerWhenNoLinks = $config->get( 'KZChatbotReplaceAnswerWhenNoLinks' );
		$answer = ( $replaceAnswerWhenNoLinks && empty( $docs ) )
			? Slugs::getSlug( 'returning_links_empty' )
			: $response->gpt_result;

		return [
			'llmResult' => $answer,
			'docs' => $docs,
			'conversationId' => $response->conversation_id,
		];
	}

	/**
	 * @param string $contentType MIME Type
	 * @return JsonBodyValidator
	 * @throws HttpException
	 */
	public function getBodyValidator( $contentType ): JsonBodyValidator {
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
	private function validateUser(): void {
		$uuid = $this->uuid;
		$userData = KZChatbot::getUserData( $uuid );
		if ( $userData === false ) {
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
		$services = MediaWikiServices::getInstance();
		if ( !$services->getExtensionRegistry()->isLoaded( 'ChatbotRagContent' ) ) {
			return null;
		}

		// Check if referrer is a page ID
		if ( !is_numeric( $this->referrer ) || (int)$this->referrer <= 0 ) {
			return null;
		}
		$pageId = (int)$this->referrer;
		$title = Title::newFromID( $pageId );
		if ( !$title ) {
			return null;
		}

		// Page context is an optional enrichment for the RAG backend, and
		// ChatbotRagContent is a separate extension whose signature we do not
		// control. Never let it take the answer down with it: log and ask the
		// question without page context.
		try {
			$isRelevant = ChatbotRagContent::isRelevantTitle(
				$title,
				$services->getPageProps(),
				$services->getMainConfig(),
				$services->getContentLanguage()
			);
		} catch ( Throwable $e ) {
			KZChatbot::getLogger()->error(
				'ChatbotRagContent::isRelevantTitle() failed for page {page_id}; '
					. 'asking without page context',
				[ 'exception' => $e, 'page_id' => $pageId ]
			);
			return null;
		}

		return $isRelevant ? $pageId : null;
	}
}
