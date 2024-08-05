<?php

namespace MediaWiki\Extension\KZChatbot;

use ApiBase;

class ApiKZChatbotSubmitQuestion extends ApiBase {

	/**
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
		];
	}

  /**
   * Pass user question to ChatGPT API, checking first that user hasn't exceeded daily limit.
   * Return answer from ChatGPT API.
   */
  public function execute() {
		$result = $this->getResult();
		$result->addValue( null, 'name', 'value' );
  }

}
