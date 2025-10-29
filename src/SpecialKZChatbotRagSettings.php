<?php

namespace MediaWiki\Extension\KZChatbot;

use Config;
use ErrorPageError;
use Exception;
use FormSpecialPage;
use Html;
use HTMLForm;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MediaWikiServices;
use PermissionsError;
use Status;

class SpecialKZChatbotRagSettings extends FormSpecialPage {
	/** @var bool */
	private bool $isAllowedEdit;

	/** @var bool */
	private bool $isAllowedView;

	/** @var Config */
	private Config $config;

	/** @var HttpRequestFactory */
	private HttpRequestFactory $httpFactory;

	/** @var array|null */
	private ?array $currentConfig = null;

	/** @var array|null */
	private ?array $configMetadata = null;

	public function __construct() {
		parent::__construct( 'KZChatbotRagSettings' );
		$this->config = MediaWikiServices::getInstance()->getMainConfig();
		$this->httpFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
	}

	/**
	 * @inheritDoc
	 * @throws PermissionsError
	 * @throws ErrorPageError
	 */
	public function execute( $par ) {
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		$this->isAllowedView = $permissionManager->userHasRight( $this->getUser(), 'kzchatbot-view-rag-settings' );
		$this->isAllowedEdit = $permissionManager->userHasRight( $this->getUser(), 'kzchatbot-edit-rag-settings' );
		$out = $this->getOutput();

		// Check if user can at least view
		if ( !$this->isAllowedView && !$this->isAllowedEdit ) {
			throw new PermissionsError( 'kzchatbot-view-rag-settings' );
		}

		// Fetch current config and metadata before displaying the form
		$configStatus = KZChatbot::getRagConfig();
		if ( !$configStatus->isOK() ) {
			$errors = $configStatus->getErrors();
			$errorMsg = $errors ? $errors[0]['message'] : 'kzchatbot-rag-settings-error-api-unreachable';
			throw new ErrorPageError( 'kzchatbot-rag-settings-error', $errorMsg );
		}
		$this->currentConfig = $configStatus->getValue();

		$metadataStatus = KZChatbot::getConfigMetadata();
		if ( !$metadataStatus->isOK() ) {
			$errors = $metadataStatus->getErrors();
			$errorMsg = $errors ? $errors[0]['message'] : 'kzchatbot-rag-settings-error-metadata-failed';
			throw new ErrorPageError( 'kzchatbot-rag-settings-error', $errorMsg );
		}
		$this->configMetadata = $metadataStatus->getValue();
		if ( !$this->validateMetadataStructure() ) {
			throw new ErrorPageError( 'kzchatbot-rag-settings-error', 'kzchatbot-rag-settings-error-metadata-invalid' );
		}

		// Add navigation link to Testing Interface
		$testingTitle = self::getSafeTitleFor( 'KZChatbotTesting' );
		$linkRenderer = $this->getLinkRenderer();
		$testingLink = $linkRenderer->makeLink(
			$testingTitle,
			$this->msg( 'kzchatbot-rag-settings-nav-to-testing' )->text()
		);
		$this->getOutput()->addSubtitle( $testingLink );

		// Show view-only notice if user can't edit
		if ( !$this->isAllowedEdit ) {
			$this->getOutput()->addWikiMsg( 'kzchatbot-rag-settings-view-only' );
		}

		$session = $this->getRequest()->getSession();
		if ( $session->get( 'specialKZChatbotRagSettingsSaveSuccess' ) ) {
			// Remove session data for the success message
			$session->remove( 'specialKZChatbotRagSettingsSaveSuccess' );
			$out->addModuleStyles( 'mediawiki.notification.convertmessagebox.styles' );

			$out->addHTML(
				Html::rawElement(
					'div',
					[
						'class' => 'mw-notify-success successbox',
						'id' => 'mw-preferences-success',
						'data-mw-autohide' => 'false',
					],
					Html::element( 'p', [], $this->msg( 'kzchatbot-rag-settings-save-success' )->text() )
				)
			);
		}

		parent::execute( $par );
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string {
		return $this->msg( 'kzchatbot-rag-settings' )->text();
	}

	/**
	 * Validate banned fields format
	 * @param string $value
	 * @return bool|string True on success, string error message on failure
	 */
	public function validateBannedFields( string $value ) {
		if ( empty( $value ) ) {
			return true;
		}

		$fields = array_map( 'trim', explode( ',', $value ) );
		if ( count( $fields ) !== count( array_filter( $fields, 'strlen' ) ) ) {
			return $this->msg( 'kzchatbot-rag-settings-error-banned-fields-format' )->text();
		}
		return true;
	}

	/**
	 * Validate metadata structure
	 * @return bool
	 */
	private function validateMetadataStructure(): bool {
		if ( !$this->configMetadata || !is_array( $this->configMetadata ) ) {
			return false;
		}

		$required = [ 'available_models', 'temperature_options', 'num_of_pages_options', 'config_field_types' ];
		foreach ( $required as $field ) {
			if ( !isset( $this->configMetadata[$field] ) || !is_array( $this->configMetadata[$field] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build MediaWiki HTMLForm hide-if condition for models that don't support temperature
	 *
	 * Creates a conditional expression that will hide the temperature field when
	 * models that don't support temperature are selected. Uses MediaWiki's native
	 * hide-if functionality for real-time client-side field visibility.
	 *
	 * @return array|null MediaWiki hide-if condition array, or null if no models lack temperature support
	 */
	private function buildTemperatureHideCondition(): ?array {
		$modelsWithoutTemperature = [];

		// Collect models that don't support temperature (like o1, o3-mini models)
		foreach ( $this->configMetadata['available_models'] as $modelName => $modelInfo ) {
			if ( !( $modelInfo['supports_temperature'] ?? true ) ) {
				$modelsWithoutTemperature[] = $modelName;
			}
		}

		if ( empty( $modelsWithoutTemperature ) ) {
			// All models support temperature, no hiding needed
			return null;
		}

		if ( count( $modelsWithoutTemperature ) === 1 ) {
			// Single condition: hide if model === 'specific-model'
			return [ '===', 'model', $modelsWithoutTemperature[0] ];
		}

		// Multiple conditions: hide if model === 'model1' OR model === 'model2' OR ...
		// MediaWiki expects: [ 'OR', condition1, condition2, ... ]
		$hideConditions = [];
		foreach ( $modelsWithoutTemperature as $model ) {
			$hideConditions[] = [ '===', 'model', $model ];
		}

		return array_merge( [ 'OR' ], $hideConditions );
	}

	/**
	 * @return array Form fields configuration
	 */
	protected function getFormFields(): array {
		$availableModels = array_combine(
			array_keys( $this->configMetadata['available_models'] ),
			array_keys( $this->configMetadata['available_models'] )
		);

		$temperatureOptions = array_combine(
			array_map( static function ( $val ) {
				return (string)$val;
			}, $this->configMetadata['temperature_options'] ),
			$this->configMetadata['temperature_options']
		);

		$numPagesOptions = array_combine(
			array_map( static function ( $val ) {
				return (string)$val;
			}, $this->configMetadata['num_of_pages_options'] ),
			$this->configMetadata['num_of_pages_options']
		);

		// Get hide condition for temperature field based on model selection
		$temperatureHideCondition = $this->buildTemperatureHideCondition();

		$fields = [
			'version' => [
				'type' => 'text',
				'readonly' => true,
				'label-message' => 'kzchatbot-rag-settings-label-version',
				'default' => $this->currentConfig['version'] ?? '',
			],
			'model' => [
				'type' => 'select',
				'label-message' => 'kzchatbot-rag-settings-label-model',
				'help-message' => 'kzchatbot-rag-settings-help-model',
				'options' => $availableModels,
				'default' => $this->currentConfig['model'] ?? '',
				'required' => true,
			],
			'num_of_pages' => [
				'type' => 'select',
				'label-message' => 'kzchatbot-rag-settings-label-num-of-pages',
				'help-message' => 'kzchatbot-rag-settings-help-num-of-pages',
				'options' => $numPagesOptions,
				'default' => $this->currentConfig['num_of_pages'] ?? 1,
				'required' => true,
			],
			'add_current_page_to_search' => [
				'type' => 'check',
				'label-message' => 'kzchatbot-rag-settings-label-add-current-page',
				'help-message' => 'kzchatbot-rag-settings-help-add-current-page',
				'default' => $this->currentConfig['add_current_page_to_search'] ?? false,
			],
			'temperature' => [
				'type' => 'select',
				'label-message' => 'kzchatbot-rag-settings-label-temperature',
				'help-message' => 'kzchatbot-rag-settings-help-temperature',
				'options' => $temperatureOptions,
				'default' => $this->currentConfig['temperature'] ?? 0.7,
				'required' => false,
			],
			'system_prompt' => [
				'type' => 'textarea',
				'rows' => 3,
				'label-message' => 'kzchatbot-rag-settings-label-system-prompt',
				'help-message' => 'kzchatbot-rag-settings-help-system-prompt',
				'default' => $this->currentConfig['system_prompt'] ?? '',
				'required' => true,
			],
			'user_prompt' => [
				'type' => 'textarea',
				'rows' => 3,
				'label-message' => 'kzchatbot-rag-settings-label-user-prompt',
				'help-message' => 'kzchatbot-rag-settings-help-user-prompt',
				'default' => $this->currentConfig['user_prompt'] ?? '',
				'required' => true,
			],
			'banned_fields' => [
				'type' => 'text',
				'label-message' => 'kzchatbot-rag-settings-label-banned-fields',
				'help-message' => 'kzchatbot-rag-settings-help-banned-fields',
				'default' => $this->currentConfig['banned_fields'] ?? '',
				'validation-callback' => [ $this, 'validateBannedFields' ],
			],
			'rephrase_prompt' => [
				'type' => 'textarea',
				'rows' => 3,
				'label-message' => 'kzchatbot-rag-settings-label-rephrase-prompt',
				'help-message' => 'kzchatbot-rag-settings-help-rephrase-prompt',
				'default' => $this->currentConfig['rephrase_prompt'] ?? '',
				'required' => false,
			],
		];

		// Add conditional hiding for temperature field if some models don't support it
		if ( $temperatureHideCondition !== null ) {
			$fields['temperature']['hide-if'] = $temperatureHideCondition;
		}

		// If user only has view permission, make all fields disabled
		if ( !$this->isAllowedEdit ) {
			foreach ( $fields as &$field ) {
				$field['disabled'] = true;
			}
		}

		return $fields;
	}

	/**
	 * Normalize configuration data types
	 * @param array $config Configuration array to normalize
	 * @return array Normalized configuration
	 */
	private function normalizeConfig( array $config ): array {
		return [
			'model' => $config['model'] ?? '',
			'num_of_pages' => (int)( $config['num_of_pages'] ?? 1 ),
			'add_current_page_to_search' => (bool)( $config['add_current_page_to_search'] ?? false ),
			'temperature' => (float)( $config['temperature'] ?? 0 ),
			'system_prompt' => trim( $config['system_prompt'] ?? '' ),
			'user_prompt' => trim( $config['user_prompt'] ?? '' ),
			'banned_fields' => trim( $config['banned_fields'] ?? '' ),
			'rephrase_prompt' => trim( $config['rephrase_prompt'] ?? '' ),
			'version' => $config['version'] ?? '',
		];
	}

	/**
	 * Check if configuration is different from current
	 * @param array $new New configuration
	 * @return bool True if configuration is different
	 */
	private function hasConfigChanged( array $new ): bool {
		return $this->currentConfig != $new;
	}

	/** @inheritDoc */
	protected function alterForm( HTMLForm $form ) {
		if ( !$this->isAllowedEdit ) {
			// Disable everything we can
			$form->setAction( null )->suppressDefaultSubmit()->setSubmitCallback( static function () {
			} );
		}
	}

	/**
	 * @param array $data
	 * @return Status
	 * @throws PermissionsError
	 */
	public function onSubmit( array $data ): Status {
		// Safeguard - only process if user has edit permission
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		if ( !$permissionManager->userHasRight( $this->getUser(), 'kzchatbot-edit-rag-settings' ) ) {
			throw new PermissionsError( 'kzchatbot-edit-rag-settings' );
		}

		$normalizedData = $this->normalizeConfig( $data );
		if ( !$this->hasConfigChanged( $normalizedData ) ) {
			return Status::newFatal( 'kzchatbot-rag-settings-error-no-change' );
		}

		// Continue with saving only if there are changes
		// I tried to make the POST request with MW's HttpRequestFactory, but it didn't work, so I resorted to curl
		try {
			$apiUrl = rtrim( $this->config->get( 'KZChatbotLlmApiUrl' ), '/' );
			$ch = curl_init( "$apiUrl/set_config" );

			curl_setopt_array( $ch, [
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => json_encode( $normalizedData ),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT => 30,
				CURLOPT_HTTPHEADER => [
					'Content-Type: application/json',
					'Accept: application/json'
				]
			] );

			$response = curl_exec( $ch );

			if ( curl_errno( $ch ) ) {
				wfLogWarning( 'Curl error: ' . curl_error( $ch ) );
				curl_close( $ch );
				return Status::newFatal( 'kzchatbot-rag-settings-error-api-unreachable' );
			}

			curl_close( $ch );

			if ( $response !== false ) {
				$result = json_decode( $response, true );
				if ( $result === null && json_last_error() !== JSON_ERROR_NONE ) {
					return Status::newFatal( 'kzchatbot-rag-settings-error-invalid-response' );
				}
				return Status::newGood();
			}

			return Status::newFatal( 'kzchatbot-rag-settings-error-api-unreachable' );
		} catch ( Exception $e ) {
			wfLogWarning( 'Failed to save RAG config: ' . $e->getMessage() );
			return Status::newFatal( 'kzchatbot-rag-settings-error-save' );
		}
	}

	/**
	 * Show success message after saving
	 */
	public function onSuccess() {
		$this->getRequest()->getSession()->set( 'specialKZChatbotRagSettingsSaveSuccess', 1 );
		$this->getOutput()->redirect( $this->getPageTitle()->getFullURL() );
	}

	/**
	 * @inheritDoc
	 */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}
}
