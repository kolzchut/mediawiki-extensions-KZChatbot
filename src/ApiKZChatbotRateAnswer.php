<?php

namespace MediaWiki\Extension\KZChatbot;

use ApiBase;

class ApiKZChatbotRateAnswer extends ApiBase {

	/**
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
		];
	}

  /**
   * Pass user rating on specified chatbot answer to the ChatGPT API.
   */
  public function execute() {
		$result = $this->getResult();
		$result->addValue( null, 'name', 'value' );
  }

}
