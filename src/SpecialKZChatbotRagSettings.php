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

	/** Available LLM models */
	private const AVAILABLE_MODELS = [
		'gpt-3.5-turbo' => 'gpt-3.5-turbo',
		'gpt-4o-mini' => 'gpt-4o-mini',
		'gpt-4o' => 'gpt-4o'
	];

	/** Available temperature values */
	private const AVAILABLE_TEMPERATURES = [
		'0.1' => 0.1,
		'0.2' => 0.2,
		'0.3' => 0.3,
		'0.4' => 0.4,
		'0.5' => 0.5,
		'0.6' => 0.6,
		'0.7' => 0.7,
		'0.8' => 0.8,
		'0.9' => 0.9
	];

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

		// Fetch current config before displaying the form
		$configStatus = KZChatbot::getRagConfig();
		if ( !$configStatus->isOK() ) {
			$errors = $configStatus->getErrors();
			throw new ErrorPageError( 'kzchatbot-rag-settings-error', $errors ? $errors[0] : '' );
		}
		$this->currentConfig = $configStatus->getValue();

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
	 * @return array Form fields configuration
	 */
	protected function getFormFields(): array {
		$fields = [
			'version' => [
				'type' => 'text',
				'readonly' => true,
				'label-message' => 'kzchatbot-rag-settings-label-version',
				'default' => $this->currentConfig['version'],
			],
			'model' => [
				'type' => 'select',
				'label-message' => 'kzchatbot-rag-settings-label-model',
				'help-message' => 'kzchatbot-rag-settings-help-model',
				'options' => self::AVAILABLE_MODELS,
				'default' => $this->currentConfig['model'],
				'required' => true,
			],
			'num_of_pages' => [
				'type' => 'int',
				'label-message' => 'kzchatbot-rag-settings-label-num-of-pages',
				'help-message' => 'kzchatbot-rag-settings-help-num-of-pages',
				'min' => 1,
				'max' => 5,
				'default' => $this->currentConfig['num_of_pages'],
				'required' => true,
			],
			'temperature' => [
				'type' => 'select',
				'label-message' => 'kzchatbot-rag-settings-label-temperature',
				'help-message' => 'kzchatbot-rag-settings-help-temperature',
				'options' => array_combine(
					array_map( static function ( $val ) {
						return (string)$val;
					}, self::AVAILABLE_TEMPERATURES ),
					self::AVAILABLE_TEMPERATURES
				),
				'default' => $this->currentConfig['temperature'],
				'required' => true
			],
			'system_prompt' => [
				'type' => 'textarea',
				'rows' => 3,
				'label-message' => 'kzchatbot-rag-settings-label-system-prompt',
				'help-message' => 'kzchatbot-rag-settings-help-system-prompt',
				'default' => $this->currentConfig['system_prompt'],
				'required' => true,
			],
			'user_prompt' => [
				'type' => 'textarea',
				'rows' => 3,
				'label-message' => 'kzchatbot-rag-settings-label-user-prompt',
				'help-message' => 'kzchatbot-rag-settings-help-user-prompt',
				'default' => $this->currentConfig['user_prompt'],
				'required' => true,
			],
			'banned_fields' => [
				'type' => 'text',
				'label-message' => 'kzchatbot-rag-settings-label-banned-fields',
				'help-message' => 'kzchatbot-rag-settings-help-banned-fields',
				'default' => $this->currentConfig['banned_fields'] ?? '',
				'validation-callback' => [ $this, 'validateBannedFields' ],
			],
		];

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
			'temperature' => (float)( $config['temperature'] ?? 0 ),
			'system_prompt' => trim( $config['system_prompt'] ?? '' ),
			'user_prompt' => trim( $config['user_prompt'] ?? '' ),
			'banned_fields' => trim( $config['banned_fields'] ?? '' ),
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
