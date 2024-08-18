<?php

namespace MediaWiki\Extension\KZChatbot;

use MediaWiki\Rest\Handler;
use Wikimedia\ParamValidator\ParamValidator;

class ApiKZChatbotGetConfig extends Handler {

	/**
	 * @return array
	 */
	public function getParamSettings() {
		return [
			'uuid' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_SOURCE => 'query',
			]
		];
	}

  /**
   * Compile configuration settings for the chatbot app launcher.
   * @return array
   */
	public function execute() {
		$fieldNames = array_flip( KZChatbot::mappingDbToJson() );
		$uuid = $this->getValidatedParams()['uuid'];
		if ( !empty( $uuid ) ) {
			$userData = KZChatbot::getUserData( $uuid );
			$uuid = $userData[ $fieldNames['uuid'] ];
		}

		// Assign new UUID if none is in current effect for the browser.
		if ( empty( $userData ) ) {
			$userData = KZChatbot::newUser();
		}
		$uuid = $userData[ $fieldNames['uuid'] ];

		// Provide updated cookie expiry.
		$settings = KZChatbot::getGeneralSettings();
		$cookieExpiryDays = $settings['cookie_expiry_days'] ?? 365;
		$cookieExpiry = date( DATE_RFC3339, time() + $cookieExpiryDays * 60 * 60 * 24 );

		return [
			'uuid' => $uuid,
			'chatbotIsShown' => $userData[ $fieldNames['chatbotIsShown'] ],
			'cookieExpiry' => $cookieExpiry,
		];
	}

	public function needsWriteAccess() {
		return false;
	}

}
