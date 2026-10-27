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
		}
		if ( empty( $userData ) ) {
			if ( KZChatbot::isReadOnly() ) {
				return self::notAvailableWhileReadOnly( $uuid );
			}
			$userData = KZChatbot::newUser();
		}
		if ( $userData === false ) {
			return [ 'uuid' => false ];
		}
		$uuid = $userData[ $fieldNames['uuid'] ];
		$settings = KZChatbot::getGeneralSettings();
		$cookieExpiryDays = $settings['cookie_expiry_days'] ?? 365;
		$cookieExpiry = date( DATE_RFC3339, time() + $cookieExpiryDays * 60 * 60 * 24 );

		return [
			'uuid' => $uuid,
			'chatbotIsShown' => true,
			'questionsPermitted' => KZChatbot::getQuestionsPermitted( $uuid ),
			'cookieExpiry' => $cookieExpiry,
		];
	}

	/**
	 * The answer for a visitor we would have to create a user for while the wiki
	 * is read-only. Inserting the user would throw, so we tell the launcher not to
	 * render the chatbot this time, without changing the visitor's cookie.
	 *
	 * The launcher (resources/ext.KZChatbot.launcher) acts on three things:
	 * - `uuid: false` would set the one-day exclusion cookie, so a visitor who
	 *   arrived during a maintenance window would miss the chatbot for a day.
	 * - Any other `uuid` that differs from the one it sent is saved as the new
	 *   cookie, which would overwrite a stale uuid with whatever we return.
	 * - The chatbot is rendered only when `chatbotIsShown` is true.
	 *
	 * So we echo back the uuid the launcher sent ('' for a first visit), and set
	 * `chatbotIsShown` to false. The cookie is left as it was, nothing is
	 * rendered, and the visitor goes through selection again on a later page view.
	 * `readOnly` is there for anyone reading the response; the launcher ignores it.
	 *
	 * @param string|null $requestedUuid The uuid query parameter, if any
	 * @return array
	 */
	private static function notAvailableWhileReadOnly( ?string $requestedUuid ): array {
		return [
			'uuid' => $requestedUuid ?? '',
			'chatbotIsShown' => false,
			'readOnly' => true,
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

	/** @inheritDoc */
	public function needsWriteAccess() {
		return true;
	}
}
