<?php

namespace MediaWiki\Extension\KZChatbot;

use ApiBase;

class ApiKZChatbotGetStatus extends ApiBase {

	/**
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
		];
	}

  /**
   * Compile chatbot status info for the React app.
   */
  public function execute() {
		$result = $this->getResult();
		$result->addValue( null, 'name', 'value' );
  }

}
