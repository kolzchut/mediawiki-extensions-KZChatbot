<?php

namespace MediaWiki\Extension\KZChatbot;

use Exception;
use MediaWiki\MediaWikiServices;
use SpecialPage;
use TemplateParser;

class SpecialKZChatbotTesting extends SpecialPage {
	/** @var TemplateParser */
	private TemplateParser $templateParser;

	public function __construct() {
		parent::__construct( 'KZChatbotTesting', 'kzchatbot-testing' );
		$this->templateParser = new TemplateParser( __DIR__ . '/../templates' );
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		parent::execute( $subPage );

		$this->getOutput()->addModuleStyles( 'ext.KZChatbot.testing.styles' );
		$this->getOutput()->addModules( 'ext.KZChatbot.testing.batch' );

		// Get the current links_identifier from RAG settings
		$ragConfig = $this->getCurrentRagConfig();
		$linksIdentifier = $ragConfig['links_identifier'] ?? '';
		$this->getOutput()->addJsConfigVars( [
			'wgKZChatbotLinksIdentifier' => $linksIdentifier
		] );

		$templateData = [
			'batchTitle' => $this->msg( 'kzchatbot-testing-batch-title' )->text(),
			'inputLabel' => $this->msg( 'kzchatbot-testing-batch-input-label' )->text(),
			'inputPlaceholder' => $this->msg( 'kzchatbot-testing-batch-placeholder' )->text(),
			'processButtonText' => $this->msg( 'kzchatbot-testing-batch-process' )->text(),
			'cancelButtonText' => $this->msg( 'kzchatbot-testing-batch-cancel' )->text(),
			'downloadButtonText' => $this->msg( 'kzchatbot-testing-batch-download' )->text(),
			'outputLabel' => $this->msg( 'kzchatbot-testing-batch-output-label' )->text(),
			'totalQueriesLabel' => $this->msg( 'kzchatbot-testing-batch-total-queries' )->text(),
			'numberColumnHeader' => $this->msg( 'kzchatbot-testing-batch-header-number' )->text(),
			'queryColumnHeader' => $this->msg( 'kzchatbot-testing-batch-header-query' )->text(),
			'responseColumnHeader' => $this->msg( 'kzchatbot-testing-batch-header-response' )->text(),
			'documentsColumnHeader' => $this->msg( 'kzchatbot-testing-batch-header-documents' )->text(),
			'linksIdentifier' => $linksIdentifier,
			'modelColumnHeader' => $this->msg( 'kzchatbot-testing-batch-header-model' )->text(),
			'timeColumnHeader' => $this->msg( 'kzchatbot-testing-batch-header-time' )->text(),
			'tokensColumnHeader' => $this->msg( 'kzchatbot-testing-batch-header-tokens' )->text(),
			'inputHint' => $this->msg( 'kzchatbot-testing-batch-input-hint' )->text(),
			'deleteQueryLabel' => $this->msg( 'kzchatbot-testing-batch-delete-query' )->text(),
			'addQueryLabel' => $this->msg( 'kzchatbot-testing-batch-add-query' )->text(),
			'initialQuery' => $this->msg( 'kzchatbot-testing-batch-initial-query' )->text(),
		];

		$this->getOutput()->addHTML(
			$this->templateParser->processTemplate( 'KZChatbotTestingBatch', $templateData )
		);
	}

	/**
	 * Fetch the current RAG configuration
	 * @return array
	 *
	 * @todo this should probably be centralized, as SpecialKZChatbotRagSettings already does it
	 */
	private function getCurrentRagConfig(): array {
		try {
			$apiUrl = rtrim( $this->getConfig()->get( 'KZChatbotLlmApiUrl' ), '/' );
			$response = MediaWikiServices::getInstance()
				->getHttpRequestFactory()
				->get(
					"$apiUrl/get_config",
					[ 'timeout' => 30 ]
				);

			if ( $response !== null ) {
				$data = json_decode( $response, true );
				if ( is_array( $data ) ) {
					return $data;
				}
			}
		} catch ( Exception $e ) {
			wfLogWarning( 'Failed to fetch RAG config: ' . $e->getMessage() );
		}

		return [];
	}

	/** @inheritDoc */
	public function getDescription(): string {
		return $this->msg( 'kzchatbot-testing-title' )->text();
	}
}
