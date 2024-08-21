<?php

namespace MediaWiki\Extension\KZChatbot;

use MediaWiki\Rest\Handler;
use Wikimedia\ParamValidator\ParamValidator;

class ApiKZChatbotGetStatus extends Handler {

	/**
	 * Compile chatbot status info for the React app.
	 * @return array
	 */
	public function execute() {
		$fieldNames = array_flip( KZChatbot::mappingDbToJson() );
		$uuid = $this->getValidatedParams()[ 'uuid' ];
		if ( !empty( $uuid ) ) {
			$userData = KZChatbot::getUserData( $uuid );
			$uuid = $userData[ $fieldNames['uuid'] ];
		}
		if ( empty( $userData ) ) {
			$userData = KZChatbot::newUser();
		}
		$uuid = $userData[ $fieldNames['uuid'] ];
		$settings = KZChatbot::getGeneralSettings();
		$cookieExpiryDays = $settings['cookie_expiry_days'] ?? 365;
		$cookieExpiry = date( DATE_RFC3339, time() + $cookieExpiryDays * 60 * 60 * 24 );
		return [
			'uuid' => $uuid,
			'chatbotIsShown' => $userData[$fieldNames['chatbotIsShown']],
			'questionsPermitted' => KZChatbot::getQuestionsPermitted( $uuid ),
			'cookieExpiry' => $cookieExpiry,
		];
	}

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

	public function needsWriteAccess() {
		return false;
	}
}
