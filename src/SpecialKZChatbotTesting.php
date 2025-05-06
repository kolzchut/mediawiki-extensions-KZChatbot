<?php

namespace MediaWiki\Extension\KZChatbot;

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

		// Fetch model information from the RAG backend
		$modelsVersionStatus = KZChatbot::getModelsVersion();
		$modelsVersion = '';
		$modelsError = '';

		if ( $modelsVersionStatus->isOK() ) {
			$modelsVersion = $modelsVersionStatus->getValue();
		} else {
			$errors = $modelsVersionStatus->getErrors();
			$modelsError = $errors ?
				$this->msg( $errors[0] )->text() :
				$this->msg( 'kzchatbot-testing-models-error-unknown' )->text();
		}

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
			'filteredDocsColumnHeader' => $this->msg( 'kzchatbot-testing-batch-header-filtered-documents' )->text(),
			'inputHint' => $this->msg( 'kzchatbot-testing-batch-input-hint' )->text(),
			'deleteQueryLabel' => $this->msg( 'kzchatbot-testing-batch-delete-query' )->text(),
			'addQueryLabel' => $this->msg( 'kzchatbot-testing-batch-add-query' )->text(),
			'initialQuery' => $this->msg( 'kzchatbot-testing-batch-initial-query' )->text(),
			'modelsVersionLabel' => $this->msg( 'kzchatbot-testing-models-version-label' )->text(),
			'modelsVersion' => $modelsVersion,
			'modelsError' => $modelsError,
			'hasModelsError' => !empty( $modelsError ),
		];

		$this->getOutput()->addHTML(
			$this->templateParser->processTemplate( 'KZChatbotTestingBatch', $templateData )
		);
	}

	/** @inheritDoc */
	public function getDescription(): string {
		return $this->msg( 'kzchatbot-testing-title' )->text();
	}
}
