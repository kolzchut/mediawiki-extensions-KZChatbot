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

		try {
			$apiUrl = rtrim( $this->getConfig()->get( 'KZChatbotLlmApiUrl' ), '/' );
			$ch = curl_init( "$apiUrl/search" );

			$postData = json_encode( [
				'query' => $query
			] );

			curl_setopt_array( $ch, [
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $postData,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT => 30,
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
