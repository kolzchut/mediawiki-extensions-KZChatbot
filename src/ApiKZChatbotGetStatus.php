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
		$uuid = $this->getValidatedParams()[ 'uuid' ];
		$userData = KZChatbot::getUserData( $uuid );
		$fieldNames = array_flip( KZChatbot::mappingDbToJson() );
		return [
			'chatbotIsShown' => $userData[$fieldNames['chatbotIsShown']],
			'questionsPermitted' => KZChatbot::getQuestionsPermitted( $uuid ),
		];
	}

	/**
	 * @return array
	 */
	public function getParamSettings() {
		return [
			'uuid' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				self::PARAM_SOURCE => 'query',
			]
		];
	}

	public function needsWriteAccess() {
		return false;
	}
}
