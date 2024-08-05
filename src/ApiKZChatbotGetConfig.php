<?php

namespace MediaWiki\Extension\KZChatbot;

use ApiBase;

class ApiKZChatbotGetConfig extends ApiBase {

	/**
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
			'uuid' => '',
		];
	}

  /**
   * Compile configuration settings for the chatbot app launcher.
   */
  public function execute() {
		$result = $this->getResult();
		$fieldNames = array_flip( KZChatbot::mappingDbToJson() );

		// Look up UUID if given.
		$uuid = $this->getParameter( 'uuid' );
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
		$cookieExpiry = date( 'D, d M Y H:i:s \G\M\T', time() + $cookieExpiryDays * 60 * 60 * 24 );

		// Prepare output
		$config = [
			'uuid' => $uuid,
			'chatbotIsShown' => $userData[ $fieldNames['chatbotIsShown'] ],
			'chatbotProminence' => $settings[ $fieldNames['chatbotProminence'] ],
			'cookieExpiry' => $cookieExpiry,
		];
		$result->addValue( null, 'config', $config );
  }

}
