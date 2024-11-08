<?php

namespace MediaWiki\Extension\KZChatbot;

use Config;
use Exception;
use FormSpecialPage;
use HTMLForm;
use MediaWiki\MediaWikiServices;
use Status;
use TemplateParser;

class SpecialKZChatbotTesting extends FormSpecialPage {
	/** @var Config */
	private Config $config;

	/** @var TemplateParser */
	private TemplateParser $templateParser;

	public function __construct() {
		parent::__construct( 'KZChatbotTesting', 'kzchatbot-testing' );
		$this->config = MediaWikiServices::getInstance()->getMainConfig();
		$this->templateParser = new TemplateParser( __DIR__ . '/../templates' );
	}

	/** @inheritDoc */
	public function getDescription(): string {
		return $this->msg( 'kzchatbot-testing-title' )->text();
	}

	/** @inheritDoc */
	protected function getFormFields(): array {
		return [
			'query' => [
				'type' => 'text',
				'label-message' => 'kzchatbot-testing-query-label',
				'placeholder-message' => 'kzchatbot-testing-query-placeholder',
				'required' => true,
			],
		];
	}

	/** @inheritDoc */
	protected function alterForm( HTMLForm $form ) {
		$form->setWrapperLegendMsg( 'kzchatbot-testing-legend' );
		$form->addHeaderText( $this->msg( 'kzchatbot-testing-header' )->text() );

		// Add the ResourceLoader modules
		$this->getOutput()->addModuleStyles( 'ext.KZChatbot.testing.styles' );
		$this->getOutput()->addModules( 'ext.KZChatbot.testing.batch' );

		$templateData = [
			'batchTitle' => $this->msg( 'kzchatbot-testing-batch-title' )->text(),
			'batchDescription' => $this->msg( 'kzchatbot-testing-batch-description' )->text(),
			'inputPlaceholder' => $this->msg( 'kzchatbot-testing-batch-placeholder' )->text(),
			'processButtonText' => $this->msg( 'kzchatbot-testing-batch-process' )->text(),
			'cancelButtonText' => $this->msg( 'kzchatbot-testing-batch-cancel' )->text(),
			'downloadButtonText' => $this->msg( 'kzchatbot-testing-batch-download' )->text(),
		];

		$this->getOutput()->addHTML(
			$this->templateParser->processTemplate( 'KZChatbotTestingBatch', $templateData )
		);
	}

	/**
	 * Handle the search request using CURL
	 * @param array $data
	 * @return Status
	 */
	public function onSubmit( array $data ): Status {
		try {
			$apiUrl = rtrim( $this->config->get( 'KZChatbotLlmApiUrl' ), '/' );
			$ch = curl_init( "$apiUrl/search" );

			$postData = json_encode( [
				'query' => $data['query']
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
				wfLogWarning( 'Curl error: ' . curl_error( $ch ) );
				curl_close( $ch );
				return Status::newFatal( 'kzchatbot-testing-error-api-unreachable' );
			}

			curl_close( $ch );

			if ( $response === false ) {
				return Status::newFatal( 'kzchatbot-testing-error-api-unreachable' );
			}

			$result = json_decode( $response, true );
			if ( $result === null && json_last_error() !== JSON_ERROR_NONE ) {
				return Status::newFatal( 'kzchatbot-testing-error-invalid-response' );
			}

			// Store results in session to display after redirect
			$this->getRequest()->getSession()->set( 'kzchatbot-testing-results', $result );
			return Status::newGood();

		} catch ( Exception $e ) {
			wfLogWarning( 'Failed to perform chatbot search: ' . $e->getMessage() );
			return Status::newFatal( 'kzchatbot-testing-error-search' );
		}
	}

	/**
	 * Display results after successful submission
	 */
	public function onSuccess() {
		$results = $this->getRequest()->getSession()->get( 'kzchatbot-testing-results' );
		if ( !$results ) {
			return;
		}

		// Format results using template
		$templateData = $this->formatResultsForTemplate( $results );
		$this->getOutput()->addHTML(
			$this->templateParser->processTemplate( 'KZChatbotTestingResults', $templateData )
		);

		// Clear results from session
		$this->getRequest()->getSession()->remove( 'kzchatbot-testing-results' );
	}

	/**
	 * Format results data for template
	 * @param array $results
	 * @return array
	 */
	private function formatResultsForTemplate( array $results ): array {
		if ( !isset( $results['metadata'] ) ) {
			return [];
		}

		return [
			'modelInfo' => sprintf(
				'%s (%s seconds)',
				strtoupper( $results['metadata']['gpt_model'] ),
				$results['metadata']['gpt_time']
			),
			'gptResult' => $results['gpt_result'],
			'documentIdsTitle' => $this->msg( 'kzchatbot-testing-document-ids' )->text(),
			'documents' => $results['docs'],
			'retrievalTimeTitle' => $this->msg( 'kzchatbot-testing-retrieval-time' )->text(),
			'retrievalTime' => $results['metadata']['retrieval_time'] . ' seconds',
			'tokensTitle' => $this->msg( 'kzchatbot-testing-tokens' )->text(),
			'tokens' => $results['metadata']['tokens']
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}
}
