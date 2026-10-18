<?php
namespace MediaWiki\Extension\KZChatbot;

use MediaWiki\MediaWikiServices;
use RequestContext;

class UserLimitBypass {
	private const BYPASS_PARAM = 'kzchatbot_access';

	/**
	 * Check if the current request should bypass the user limit
	 *
	 * Only a non-empty string token enables the bypass. Anything else (false,
	 * null, an empty string, a non-string) disables it. The empty string must
	 * not count as a token: a site that fills the setting from an unset
	 * environment variable gets '', and a bare `?kzchatbot_access=` would
	 * otherwise match it.
	 *
	 * @return bool
	 */
	public static function shouldBypass() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'KZChatbot' );
		$bypassToken = $config->get( 'KZChatbotLimitBypassToken' );

		if ( !is_string( $bypassToken ) || $bypassToken === '' ) {
			return false;
		}

		$requestToken = RequestContext::getMain()->getRequest()->getVal( self::BYPASS_PARAM );
		if ( !is_string( $requestToken ) ) {
			return false;
		}

		return hash_equals( $bypassToken, $requestToken );
	}
}
