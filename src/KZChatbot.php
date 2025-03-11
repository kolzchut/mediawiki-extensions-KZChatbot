<?php

namespace MediaWiki\Extension\KZChatbot;

use Exception;
use InvalidArgumentException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MWException;
use Psr\Log\LoggerInterface;
use Random\RandomException;
use RequestContext;
use StatusValue;
use Wikimedia\Rdbms\DBQueryError;

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
	public static function getLogger(): LoggerInterface {
		if ( empty( self::$logger ) ) {
			self::$logger = LoggerFactory::getInstance( 'KZChatbot' );
		}
		return self::$logger;
	}

	/**
	 * @return array
	 */
	public static function mappingDbToJson(): array {
		return [
			'kzcbu_uuid' => 'uuid',
			'kzcbu_ip_address' => 'ip',
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
		$cookieExpiry = time() + ( $settings['cookie_expiry_days'] ?? 365 ) * 24 * 60 * 60;

		// Quick bypass check before any DB operations
		$isShown = UserLimitBypass::shouldBypass();

		// Only do the complex checks if we're not bypassing
		if ( !$isShown ) {
			$newUsersChatbotRate = $settings['new_users_chatbot_rate'] ?? 0;
			$activeUsersLimit = $settings['active_users_limit'] ?? null;

			try {
				$isShown = ( random_int( 1, 100 ) <= $newUsersChatbotRate );
			} catch ( RandomException $e ) {
				$isShown = rand( 1, 100 ) <= $newUsersChatbotRate;
			}

			// If the user isn't selected, or we're not showing the bot to anyone (rate = 0), don't create new UUIDs
			// Consumers of this function should be aware of this and handle the false return value
			if ( !$isShown || $newUsersChatbotRate === 0 ) {
				return false;
			}

			// Now that the user was theoretically selected, check if we have available "seats" (max active users)
			// Check active users limit only if a limit is set (non-null, non-empty string)
			if ( $activeUsersLimit !== null && $activeUsersLimit !== '' ) {
				$activeUsersCount = self::getCurrentActiveUsersCount();
				if ( (int)$activeUsersLimit <= $activeUsersCount ) {
					return false;
				}
			}
		}

		// All the checks passed, insert new user record
		$ipAddress = RequestContext::getMain()->getRequest()->getIP();
		$binaryIP = inet_pton( $ipAddress );

		$userData = [
			'kzcbu_uuid' => uniqid(),
			'kzcbu_cookie_expiry' => wfTimestamp( TS_MW, $cookieExpiry ),
			'kzcbu_ip_address' => $binaryIP,
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
			'*',
			[ 'kzcbu_uuid' => $uuid ],
			__METHOD__,
		);
		if ( $res ) {
			$res = $res->fetchRow();
			$res['kzcbu_ip_address'] = inet_ntop( $res['kzcbu_ip_address'] );
			return $res;
		}

		return false;
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
	public static function getGeneralSettingsNames(): array {
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
	public static function getQuestionsPermitted( string $uuid ): int {
		$user = RequestContext::getMain()->getUser();
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
	public static function useQuestion( string $uuid ) {
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
	 * @return bool
	 */
	public static function saveGeneralSettings( array $data ): bool {
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

	/**
	 * Gets the current active users count
	 * @return int
	 */
	public static function getCurrentActiveUsersCount(): int {
		$dbr = wfGetDB( DB_REPLICA );
		$activeUsersLimitDays = self::getGeneralSettings()['active_users_limit_days'] ?? 30;
		$activeUsersCount = $dbr->select(
			[ 'kzchatbot_users' ],
			[ 'COUNT(*) as count' ],
			[
				'kzcbu_last_active >= ' . wfTimestamp(
					TS_MW, time() - ( $activeUsersLimitDays * 24 * 60 * 60 )
				)
			]
		)->fetchRow();
		return $activeUsersCount['count'] ?? 0;
	}

	/**
	 * Get the remote RAG server configuration
	 *
	 * @return StatusValue
	 */
	public static function getRagConfig(): StatusValue {
		$services = MediaWikiServices::getInstance();
		$mwConfig = $services->getMainConfig();
		$httpFactory = $services->getHttpRequestFactory();

		$apiUrl = rtrim( $mwConfig->get( 'KZChatbotLlmApiUrl' ), '/' );
		$status = new StatusValue();

		try {
			$response = $httpFactory->get(
				"$apiUrl/get_config",
				[
					'timeout' => 30,
				]
			);

			if ( $response === null ) {
				$status->fatal( 'kzchatbot-rag-settings-error-api-unreachable' );
				return $status;
			}

			$data = json_decode( $response, true );
			if ( !is_array( $data ) ) {
				$status->fatal( 'kzchatbot-rag-settings-error' );
				return $status;
			}

			$status->setResult( true, $data );
			return $status;
		} catch ( Exception $e ) {
			$status->fatal( 'kzchatbot-rag-settings-error-api-unreachable' );
			return $status;
		}
	}

}
