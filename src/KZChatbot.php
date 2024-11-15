<?php

namespace MediaWiki\Extension\KZChatbot;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;
use Random\RandomException;
use RequestContext;

/**
 * @TODO general class description
 */
class KZChatbot {

	/** @var LoggerInterface */
	private static LoggerInterface $logger;

	/**
	 * Utility to maintain static logger
	 * @return LoggerInterface
	 */
	public static function getLogger() {
		if ( empty( self::$logger ) ) {
			self::$logger = LoggerFactory::getInstance( 'KZChatbot' );
		}
		return self::$logger;
	}

	/**
	 * @return array
	 */
	public static function mappingDbToJson() {
		return [
			'kzcbu_uuid' => 'uuid',
			'kzcbu_ip_address' => 'ip',
			'kzcbu_is_shown' => 'chatbotIsShown',
			'kzcbu_cookie_expiry' => 'cookieExpiry',
			'kzcbu_last_active' => 'lastActive',
			'kzcbu_questions_last_active_day' => 'questionsLastActiveDay',
			'kzcbu_ranking_eligible_answer_id' => 'eligibleAnswerId'
		];
	}

	/**
	 * @return array|false The new user data, or false if the user should not be shown the chatbot
	 */
	public static function newUser() {
		$settings = self::getGeneralSettings();
		$cookieExpiry = time() + ( $settings['cookie_expiry_days'] ?? 356 ) * 24 * 60 * 60;

		// Quick bypass check before any DB operations
		$isShown = UserLimitBypass::shouldBypass();

		// Only do the complex checks if we're not bypassing
		if ( !$isShown ) {
			$newUsersChatbotRate = $settings['new_users_chatbot_rate'] ?? 0;
			$activeUsersLimit = $settings['active_users_limit'] ?? 0;
			$activeUsersLimitDays = $settings['active_users_limit_days'] ?? 30;

			try {
				$isShown = ( random_int( 1, 100 ) <= $newUsersChatbotRate );
			} catch ( RandomException $e ) {
				$isShown = rand( 1, 100 ) <= $newUsersChatbotRate;
			}

			// If the user isn't selected, or we're not showing the bot to anyone, don't create a new UUIDs
			// Consumers of this function should be aware of this and handle the false return value
			if ( !$isShown || $newUsersChatbotRate === 0 || $activeUsersLimit === 0 ) {
				return false;
			}

			// Now that the user was theoretically selected, check if we have available "seats" (max active users)
			$dbr = wfGetDB( DB_REPLICA );
			if ( !empty( $activeUsersLimit ) ) {
				$activeUsersCount = $dbr->select(
					[ 'kzchatbot_users' ],
					[ 'COUNT(*) as count' ],
					[
						'kzcbu_is_shown' => 1,
						'kzcbu_last_active <= ' . wfTimestamp(
							TS_MW, time() - ( $activeUsersLimitDays * 24 * 60 * 60 )
						)
					]
				)->fetchRow();
				$isShown = empty( $activeUsersCount['count'] ) ||
					(int)$activeUsersLimit > (int)$activeUsersCount['count'];
			}
		}

		// Build and insert new user record
		$userData = [
			'kzcbu_uuid' => uniqid(),
			'kzcbu_is_shown' => $isShown,
			'kzcbu_cookie_expiry' => wfTimestamp( TS_MW, $cookieExpiry ),
			'kzcbu_ip_address' => RequestContext::getMain()->getRequest()->getIP(),
			'kzcbu_last_active' => wfTimestamp( TS_MW ),
			'kzcbu_questions_last_active_day' => 0,
		];

		$dbw = wfGetDB( DB_PRIMARY );
		$dbw->insert( 'kzchatbot_users', $userData, __METHOD__ );
		return $userData;
	}

	/**
	 * @param string $uuid
	 * @return array|bool
	 */
	public static function getUserData( $uuid ) {
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			[ 'kzchatbot_users' ],
			[
				'kzcbu_uuid', 'kzcbu_ip_address', 'kzcbu_is_shown', 'kzcbu_cookie_expiry', 'kzcbu_last_active',
				'kzcbu_questions_last_active_day', 'kzcbu_ranking_eligible_answer_id'
			],
			[ 'kzcbu_uuid' => $uuid ],
			__METHOD__,
		);
		return $res->fetchRow();
	}

	/**
	 * @return array|bool
	 */
	public static function getGeneralSettings() {
		static $settings;
		if ( !isset( $settings ) ) {
			$settings = [];
			$dbr = wfGetDB( DB_REPLICA );
			$generalSettingsNames = self::getGeneralSettingsNames();
			$res = $dbr->select(
				[ 'settings' => 'kzchatbot_settings' ],
				[ 'kzcbs_name', 'kzcbs_value' ],
				[ 'settings.kzcbs_name' => $generalSettingsNames ],
				__METHOD__,
			);
			for ( $setting = $res->fetchRow(); !empty( $setting ); $setting = $res->fetchRow() ) {
				$settings[ $setting['kzcbs_name'] ] = $setting['kzcbs_value'];
			}
		}
		return $settings;
	}

	/**
	 * @return array
	 */
	public static function getGeneralSettingsNames() {
		return [
			'new_users_chatbot_rate', 'active_users_limit', 'active_users_limit_days', 'questions_daily_limit',
			'question_character_limit', 'feedback_character_limit', 'cookie_expiry_days', 'uuid_request_limit',
			'usage_help_url', 'terms_of_service_url'
		];
	}

	/**
	 * @param string $uuid
	 * @return int
	 */
	public static function getQuestionsPermitted( $uuid ) {
		$user = \RequestContext::getMain()->getUser();
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();

		// If the user has the bypass permission, they can always ask a question
		if ( $permissionManager->userHasRight( $user, 'kzchatbot-no-limits' ) ) {
			return 1;
		}

		$userData = self::getUserData( $uuid );
		$settings = self::getGeneralSettings();
		$dailyLimit = $settings['questions_daily_limit'] ?? 100;
		$lastActive = wfTimestamp( TS_UNIX, $userData['kzcbu_last_active'] );
		$lastActiveDay = date( 'z', $lastActive );
		if ( $lastActiveDay !== date( 'z' ) ) {
			return $dailyLimit;
		}
		$questionsLastActiveDay = $userData['kzcbu_questions_last_active_day'] ?? 0;
		return $dailyLimit - $questionsLastActiveDay;
	}

	/**
	 * Increments the questions last active day for a specific user.
	 *
	 * @param string $uuid The UUID of the user.
	 * @return void
	 */
	public static function useQuestion( $uuid ) {
		$userData = self::getUserData( $uuid );
		// Check if the user already asked some questions today, or we should start from scratch
		$userLastActiveTimestamp = wfTimestamp( TS_UNIX, $userData['kzcbu_last_active'] );
		$userLastActiveDay = date( 'z', $userLastActiveTimestamp );
		$currentDayOfYear = date( 'z' );
		$userQuestionsLastActiveDay = $userData['kzcbu_questions_last_active_day'] ?? 0;
		$questionsLastActiveDay = ( $userLastActiveDay === $currentDayOfYear )
			? $userQuestionsLastActiveDay
			: 0;

		$dbw = wfGetDB( DB_PRIMARY );
		$dbw->update(
			'kzchatbot_users',
			[
				'kzcbu_questions_last_active_day' => $questionsLastActiveDay + 1,
				'kzcbu_last_active' => wfTimestampNow(),
			],
			[ 'kzcbu_uuid' => $uuid ],
			__METHOD__
		);
	}

	/**
	 * @param array $data
	 * @return \IResultWrapper
	 */
	public static function saveGeneralSettings( $data ) {
		$dbw = wfGetDB( DB_PRIMARY );
		$generalSettingsNames = self::getGeneralSettingsNames();

		// Clear prior values.
		$dbw->delete(
			'kzchatbot_settings',
			[ 'kzcbs_name' => $generalSettingsNames ]
		);

		// Insert data.
		$insertRows = array_map(
			fn ( $name ) => [
				'kzcbs_name' => $name,
				'kzcbs_value' => $data[$name]
			],
			array_keys( $data )
		);
		return $dbw->insert(
			'kzchatbot_settings',
			$insertRows,
			__METHOD__
		);
	}

}
