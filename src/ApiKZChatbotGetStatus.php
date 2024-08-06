<?php

namespace MediaWiki\Extension\KZChatbot;

use ApiBase;

class ApiKZChatbotGetStatus extends ApiBase {

	/**
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
			'uuid' => '',
		];
	}

	/**
	 * Compile chatbot status info for the React app.
	 */
	public function execute() {
		$uuid = $this->getParameter( 'uuid' );
		if ( empty( $uuid ) ) {
			$this->dieWithError( 'param_uuid_missing', 'param_uuid_missing' );
		}
		$userData = KZChatbot::getUserData( $uuid );
		$fieldNames = array_flip( KZChatbot::mappingDbToJson() );
		$status = [
			'chatbotIsShown' => $userData[$fieldNames['chatbotIsShown']],
			'questionsPermitted' => $userData[$fieldNames['questionsLastActiveDay']],
		];
		$result = $this->getResult();
		$result->addValue( null, 'config', $status );
	}
}
