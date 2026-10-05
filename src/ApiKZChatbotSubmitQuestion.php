<?php

namespace MediaWiki\Extension\KZChatbot;

use MediaWiki\Extension\ChatbotRagContent\ChatbotRagContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\HttpException;
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
	 * @var string The continuous-conversation thread id (uuid-prefixed).
	 */
	private $threadId;

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
			// Log rather than fall through in silence. This helper is reached on
			// paths that previously let the fault escape to the catch-all in
			// execute(), which logged it; swallowing it here would trade a
			// reader-facing bug for an invisible operator-facing one, and a
			// failing slug lookup means the database is in trouble.
			KZChatbot::getLogger()->warning(
				'general_error slug lookup failed; using the compiled-in default',
				[ 'exception' => $e ]
			);
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
		$this->question = $body['query'];
		$this->referrer = $body['referrer'];
		$this->threadId = $this->resolveThreadId( $body['thread_id'] ?? '' );

		$questionCharacterLimit = KZChatbot::getGeneralSettings()['question_character_limit'];
		if ( mb_strlen( $this->question ) > $questionCharacterLimit ) {
			throw new HttpException( Slugs::getSlug( 'question_character_limit' ), 413 );
		}
		$bannedWords = BannedWord::getAll();
		foreach ( $bannedWords as $word ) {
			$pattern = (string)$word->getPattern();
			if ( $pattern === '' ) {
				continue;
			}
			// A pattern starting with '/' is a delimited regex (validated on save).
			// Any other value is a literal word; quote it into a regex so a plain or
			// multibyte word isn't misread as a delimiter/modifier (which 500'd before).
			$regex = $pattern[0] === '/'
				? $pattern . 'u'
				: '/' . preg_quote( $pattern, '/' ) . '/u';
			if ( preg_match( $regex, $this->question ) === 1 ) {
				$message = $word->getReplyMessage() ?: Slugs::getSlug( 'banned_word_found' );
				throw new HttpException( $message, 403 );
			}
		}
		$answer = $this->generateAnswer();
		if ( $answer['llmResult'] === null ) {
			KZChatbot::getLogger()->error(
				'RAG backend returned null. Question: {question}; answer: {answer}',
				[ 'question' => $this->question, 'answer' => print_r( $answer, true ) ]
			);
			throw new HttpException( $this->generalErrorMessage(), 500 );
		}
		return $answer;
	}

	/**
	 * @return array
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
			'asked_from' => strval( $this->referrer ),
			// Continuous conversation: the RAG keys its Redis session on thread_id
			// and echoes it back for the client to carry on subsequent turns.
			'thread_id' => $this->threadId,
			// Mirror the React client: no debug payload, snippet-level context only.
			// execution_flags is omitted so the RAG applies its all-enabled default.
			'include_debug_data' => false,
			'send_complete_pages_to_llm' => false,
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
			// The LLM judge + answer can exceed the default HTTP timeout; allow more.
			'timeout' => $config->get( 'KZChatbotLlmApiTimeout' ),
			'originalRequest' => RequestContext::getMain()->getRequest(),
		], __METHOD__ );
		$req->setHeader( 'Content-Type', 'application/json' );
		$req->setHeader( 'X-Forwarded-For', RequestContext::getMain()->getRequest()->getIP() );

		$status = $req->execute();
		$rawBody = $req->getContent();
		if ( !$status->isOK() ) {
			KZChatbot::getLogger()->error(
				'RAG backend request failed (HTTP {code}): {status}; body: {body}',
				[
					'code' => $req->getStatus(),
					'status' => $status->getWikiText( false, false, 'en' ),
					'body' => mb_substr( (string)$rawBody, 0, 500 ),
				]
			);
			throw new HttpException( $this->generalErrorMessage(), 500 );
		}

		// Guard against an unparseable body so a malformed RAG response is logged
		// to the KZChatbot channel rather than escaping as an uncaught error.
		$response = json_decode( $rawBody );
		if ( !is_object( $response ) ) {
			KZChatbot::getLogger()->error(
				'RAG backend returned an unparseable response (HTTP {code}); body: {body}',
				[
					'code' => $req->getStatus(),
					'body' => mb_substr( (string)$rawBody, 0, 500 ),
				]
			);
			throw new HttpException( $this->generalErrorMessage(), 500 );
		}

		$rawDocs = is_array( $response->docs ?? null ) ? $response->docs : [];
		$docs = array_map( static function ( $doc ) {
			return [
				'title' => $doc->title ?? '',
				'url' => $doc->url ?? '',
			];
		}, $rawDocs );

		// Check config to determine behavior when no links are found
		$replaceAnswerWhenNoLinks = $config->get( 'KZChatbotReplaceAnswerWhenNoLinks' );
		$answer = ( $replaceAnswerWhenNoLinks && empty( $docs ) )
			? Slugs::getSlug( 'returning_links_empty' )
			: ( $response->llm_answer ?? null );

		return [
			'llmResult' => $answer,
			'docs' => $docs,
			'conversationId' => $response->conversation_id ?? null,
			// Echo the thread id so the client carries it on subsequent turns.
			'threadId' => $response->thread_id ?? $this->threadId,
		];
	}

	/**
	 * Body schema. JSON-only, which is the core default for
	 * getSupportedRequestTypes(), so core answers any other Content-Type with 415.
	 *
	 * MediaWiki 1.43 rejects a request whose body carries a field not declared
	 * here (rest-extraneous-body-fields, HTTP 400) — 1.35 ignored them. The React
	 * client sends three fields this handler does not act on, so they are declared
	 * as optional and then deliberately ignored: generateAnswer() sets
	 * include_debug_data and send_complete_pages_to_llm itself and omits
	 * execution_flags, so the server — not the reader's browser — decides whether
	 * the RAG returns debug data or full pages.
	 *
	 * @return array[]
	 */
	public function getBodyParamSettings(): array {
		return [
			'query' => [
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
				ParamValidator::PARAM_REQUIRED => true,
			],
			'thread_id' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			],
			// Accepted from the client and ignored; see the docblock.
			'include_debug_data' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_REQUIRED => false,
			],
			'send_complete_pages_to_llm' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_REQUIRED => false,
			],
			'execution_flags' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'array',
				ParamValidator::PARAM_REQUIRED => false,
			],
		];
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
	 * Resolve the continuous-conversation thread id.
	 *
	 * On the first turn the client sends an empty thread id and we mint one,
	 * namespaced with the user's uuid (`uuid:random`) so the unguessable random
	 * component can't be used to attach to another user's thread. On later turns
	 * the client echoes the thread id back; we verify its uuid prefix matches the
	 * requesting user before forwarding it to the RAG.
	 *
	 * @param string $clientThreadId Thread id sent by the client ('' on first turn)
	 * @return string
	 * @throws HttpException
	 */
	private function resolveThreadId( string $clientThreadId ): string {
		if ( $clientThreadId === '' ) {
			return $this->uuid . ':' . bin2hex( random_bytes( 16 ) );
		}
		$prefix = explode( ':', $clientThreadId, 2 )[0];
		if ( $prefix !== $this->uuid ) {
			// Localized + generic on purpose: this is effectively never reachable for
			// a legitimate user, and the client renders 4xx messages verbatim.
			throw new HttpException( $this->generalErrorMessage(), 403 );
		}
		return $clientThreadId;
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
