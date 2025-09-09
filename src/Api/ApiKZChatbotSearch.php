<?php

namespace MediaWiki\Extension\KZChatbot\Api;

use ApiBase;
use ApiUsageException;
use Wikimedia\ParamValidator\ParamValidator;

class ApiKZChatbotSearch extends ApiBase {
	/**
	 * @throws ApiUsageException
	 */
	public function execute() {
		// Check permissions
		$this->checkUserRightsAny( 'kzchatbot-testing' );

		$params = $this->extractRequestParams();
		$query = $params['query'];
		$rephrase = isset( $params['rephrase'] ) ? (bool)$params['rephrase'] : false;
		$includeDebugData = isset( $params['include_debug_data'] ) ? (bool)$params['include_debug_data'] : true;
		$sendCompletePagesToLlm = isset( $params['send_complete_pages_to_llm'] ) ? (bool)$params['send_complete_pages_to_llm'] : false;
		$contextPageTitle = isset( $params['context_page_title'] ) ? trim( $params['context_page_title'] ) : '';

		try {
			$apiUrl = rtrim( $this->getConfig()->get( 'KZChatbotLlmApiUrl' ), '/' );
			$ch = curl_init( "$apiUrl/search" );

			$postDataArr = [
				'query' => $query,
				// asked_from is now mandatory
				'asked_from' => 'testing interface'
			];
			if ( $rephrase ) {
				$postDataArr['rephrase'] = true;
			}
			$postDataArr['include_debug_data'] = $includeDebugData;
			$postDataArr['send_complete_pages_to_llm'] = $sendCompletePagesToLlm;
			
			// Convert context page title to page ID if provided
			if ( $contextPageTitle ) {
				$title = \Title::newFromText( $contextPageTitle );
				if ( $title && $title->exists() ) {
					// Resolve redirects to get the target page ID
					if ( $title->isRedirect() ) {
						$wikipage = \WikiPage::factory( $title );
						$redirectTarget = $wikipage->getRedirectTarget();
						if ( $redirectTarget ) {
							$title = $redirectTarget;
						}
					}
					$postDataArr['page_id'] = strval( $title->getArticleID() );
				}
			}
			$postData = json_encode( $postDataArr );

			curl_setopt_array( $ch, [
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $postData,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT => 60,
				CURLOPT_HTTPHEADER => [
					'Content-Type: application/json',
					'Accept: application/json'
				]
			] );

			$response = curl_exec( $ch );
			$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

			if ( curl_errno( $ch ) ) {
				$this->dieWithError( [
					'apierror-kzchatbot-api-unreachable',
					curl_error( $ch )
				] );
			}

			curl_close( $ch );

			if ( $response === false ) {
				$this->dieWithError( 'apierror-kzchatbot-api-unreachable' );
			}

			$result = json_decode( $response, true );
			if ( $result === null && json_last_error() !== JSON_ERROR_NONE ) {
				$this->dieWithError( 'apierror-kzchatbot-invalid-response' );
			}

			$this->getResult()->addValue( null, $this->getModuleName(), $result );
		} catch ( \Exception $e ) {
			wfLogWarning( 'Failed to perform chatbot search: ' . $e->getMessage() );
			$this->dieWithError( 'apierror-kzchatbot-search-failed' );
		}
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		return [
			'query' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'rephrase' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => false,
			],
			'include_debug_data' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => true,
			],
			'send_complete_pages_to_llm' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => false,
			],
			'context_page_title' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			],
		];
	}

	/** @inheritDoc */
	public function needsToken() {
		return 'csrf';
	}

	/** @inheritDoc */
	protected function getExamplesMessages(): array {
		return [
			'action=kzchatbotsearch&query=What is MediaWiki?' => 'apihelp-kzchatbotsearch-example-simple',
		];
	}

	/** @inheritDoc */
	public function isWriteMode(): bool {
		return false;
	}
}
