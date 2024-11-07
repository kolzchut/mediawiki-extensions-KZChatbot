<?php

namespace MediaWiki\Extension\KZChatbot;

use Config;
use ErrorPageError;
use Exception;
use FormSpecialPage;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MediaWikiServices;
use Status;

class SpecialKZChatbotRagSettings extends FormSpecialPage {
	/**
	 * @var Config
	 */
	private Config $config;

	/**
	 * @var HttpRequestFactory
	 */
	private HttpRequestFactory $httpFactory;

	/**
	 * Available LLM models
	 */
	private const AVAILABLE_MODELS = [
		'gpt-3.5-turbo' => 'gpt-3.5-turbo',
		'gpt-4o-mini' => 'gpt-4o-mini',
		'gpt-4o' => 'gpt-4o'
	];

	/**
	 * Available temperature values
	 */
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
		parent::__construct( 'KZChatbotRagSettings', 'kzchatbot-rag-admin' );
		$this->config = MediaWikiServices::getInstance()->getMainConfig();
		$this->httpFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription() {
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
		$currentConfig = $this->getCurrentConfig();

		return [
			'model' => [
				'type' => 'select',
				'label-message' => 'kzchatbot-rag-settings-label-model',
				'help-message' => 'kzchatbot-rag-settings-help-model',
				'options' => self::AVAILABLE_MODELS,
				'default' => $currentConfig['model'] ?? '',
				'required' => true,
			],
			'numOfPages' => [
				'type' => 'int',
				'label-message' => 'kzchatbot-rag-settings-label-num-of-pages',
				'help-message' => 'kzchatbot-rag-settings-help-num-of-pages',
				'min' => 1,
				'max' => 5,
				'default' => $currentConfig['num_of_pages'] ?? 1,
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
				'default' => $currentConfig['temperature'] ?? '0.7',
				'required' => true
			],
			'systemPrompt' => [
				'type' => 'textarea',
				'rows' => 3,
				'label-message' => 'kzchatbot-rag-settings-label-system-prompt',
				'help-message' => 'kzchatbot-rag-settings-help-system-prompt',
				'default' => $currentConfig['system_prompt'] ?? '',
				'required' => true,
			],
			'userPrompt' => [
				'type' => 'textarea',
				'rows' => 3,
				'label-message' => 'kzchatbot-rag-settings-label-user-prompt',
				'help-message' => 'kzchatbot-rag-settings-help-user-prompt',
				'default' => $currentConfig['user_prompt'] ?? '',
				'required' => true,
			],
			'bannedFields' => [
				'type' => 'text',
				'label-message' => 'kzchatbot-rag-settings-label-banned-fields',
				'help-message' => 'kzchatbot-rag-settings-help-banned-fields',
				'default' => $currentConfig['banned_fields'] ?? '',
				'validation-callback' => [ $this, 'validateBannedFields' ],
			],
		];
	}

	/**
	 * @return array Current configuration from API
	 * @throws ErrorPageError
	 */
	private function getCurrentConfig(): array {
		$apiUrl = rtrim( $this->config->get( 'KZChatbotLlmApiUrl' ), '/' );

		try {
			$response = $this->httpFactory->get(
				"$apiUrl/get_config",
				[
					'timeout' => 30,
				]
			);

			if ( $response === null ) {
				throw new ErrorPageError(
					'kzchatbot-rag-settings-error', 'kzchatbot-rag-settings-error-api-unreachable'
				);
			}

			if ( $response !== null ) {
				$data = json_decode( $response, true );
				if ( is_array( $data ) ) {
					return $data;
				}
			}
		} catch ( Exception $e ) {
			wfLogWarning( 'Failed to fetch RAG config: ' . $e->getMessage() );
			throw new ErrorPageError( 'kzchatbot-rag-settings-error', 'kzchatbot-rag-settings-error-api-unreachable' );
		}

		return [];
	}

	/**
	 * @param array $data
	 * @return Status
	 */
	public function onSubmit( array $data ): Status {
		$apiUrl = rtrim( $this->config->get( 'KZChatbotLlmApiUrl' ), '/' );

		try {
			$response = $this->httpFactory->post(
				"$apiUrl/set_config",
				[
					'postData' => json_encode( [
						'model' => $data['model'],
						'num_of_pages' => $data['numOfPages'],
						'temperature' => (float)$data['temperature'],
						'system_prompt' => $data['systemPrompt'],
						'user_prompt' => $data['userPrompt'],
						'banned_fields' => $data['bannedFields'] ?? '',
					] ),
					'timeout' => 30,
					'headers' => [
						'Content-Type' => 'application/json',
					],
				]
			);

			if ( $response !== null ) {
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
		$this->getOutput()->addWikiMsg( 'kzchatbot-rag-settings-save-success' );
	}

	/**
	 * @inheritDoc
	 */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}
}
