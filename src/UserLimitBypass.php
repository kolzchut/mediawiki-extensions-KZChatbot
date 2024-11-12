<?php
namespace MediaWiki\Extension\KZChatbot;

use MediaWiki\MediaWikiServices;
use RequestContext;

class UserLimitBypass {
	private const BYPASS_PARAM = 'kzchatbot_access';

	/**
	 * Check if the current request should bypass the user limit
	 * @return bool
	 */
	public static function shouldBypass() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'KZChatbot' );
		$bypassToken = $config->get( 'KZChatbotLimitBypassToken' );

		// If bypass is disabled in configuration, return false
		if ( $bypassToken === false ) {
			return false;
		}

		$request = RequestContext::getMain()->getRequest();
		return $request->getVal( self::BYPASS_PARAM ) === $bypassToken;
	}
}
