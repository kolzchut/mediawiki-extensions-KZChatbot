<?php

namespace MediaWiki\Extension\KZChatbot;

use InvalidArgumentException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MWException;
use Psr\Log\LoggerInterface;
use Random\RandomException;
use RequestContext;
use Status;
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

		try {
			$ipAddress = RequestContext::getMain()->getRequest()->getIP();
			$binaryIP = inet_pton( $ipAddress );
		} catch ( MWException $e ) {
			// Couldn't get an IP address, log and return false
			self::getLogger()->error( $e->getMessage() );
			return false;
		}

		// All the checks passed, generate a UUID and insert new user record
		$globalIdGenerator = MediaWikiServices::getInstance()->getGlobalIdGenerator();

		$formattedUuid = $globalIdGenerator->newUUIDv4();
		$rawUuid = self::rawUuidFromFormatted( $formattedUuid );

		$userData = [
			'kzcbu_uuid' => $rawUuid,
			'kzcbu_cookie_expiry' => wfTimestamp( TS_MW, $cookieExpiry ),
			'kzcbu_ip_address' => $binaryIP,
			'kzcbu_last_active' => wfTimestamp( TS_MW ),
			'kzcbu_questions_last_active_day' => 0,
		];

		$dbw = wfGetDB( DB_PRIMARY );
		try {
			$dbw->insert( 'kzchatbot_users', $userData, __METHOD__ );

			$userData['kzcbu_uuid'] = $formattedUuid;
			return $userData;
		} catch ( DBQueryError $e ) {
			self::getLogger()->error( 'Failed to insert new user: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * - Raw UUIDs (no hyphens) for database operations
	 * - Formatted UUIDs (with hyphens) for client responses
	 *
	 * @param string $uuid The formatted or raw UUID
	 * @return array|bool
	 */
	public static function getUserData( string $uuid ) {
		$rawUuid = self::rawUuidFromFormatted( $uuid );
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			[ 'kzchatbot_users' ],
			'*',
			[ 'kzcbu_uuid' => $rawUuid ],
			__METHOD__,
		);
		if ( $res ) {
			$res = $res->fetchRow();
			if ( $res ) {
				// Convert IP address from binary
				$res['kzcbu_ip_address'] = inet_ntop( $res['kzcbu_ip_address'] );
				$res['kzcbu_uuid'] = self::formatRawUuid( $res['kzcbu_uuid'] );
				return $res;
			}
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
	 * @param string $uuid The UUID of the user (either formatted or raw).
	 * @return void
	 */
	public static function useQuestion( string $uuid ) {
		$userData = self::getUserData( $uuid );
		if ( !$userData ) {
			return;
		}

		// Convert the input UUID to raw format for DB update
		$rawUuid = self::rawUuidFromFormatted( $uuid );

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
			[ 'kzcbu_uuid' => $rawUuid ],
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
	 * Convert a raw UUID (32 hex chars without hyphens) to formatted UUID string
	 * with hyphens in the standard 8-4-4-4-12 format.
	 *
	 * @param string $uuid The 32-character raw UUID
	 * @return string The formatted UUID with hyphens
	 */
	public static function formatRawUuid( string $uuid ): string {
		// If it's a legacy ID, return as is
		if ( self::isLegacyId( $uuid ) ) {
			return $uuid;
		}

		// If it's already in formatted form with hyphens, return as is
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid ) ) {
			return $uuid;
		}

		if ( !preg_match( '/^[0-9a-f]{32}$/i', $uuid ) ) {
			throw new InvalidArgumentException( 'Invalid raw UUID format. Expected 32 hex characters.' );
		}

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $uuid, 0, 8 ),
			substr( $uuid, 8, 4 ),
			substr( $uuid, 12, 4 ),
			substr( $uuid, 16, 4 ),
			substr( $uuid, 20, 12 )
		);
	}

	/**
	 * Convert a formatted UUID with hyphens back to a raw 32-character UUID
	 *
	 * @param string $uuid The UUID string with hyphens
	 * @return string The raw UUID without hyphens
	 */
	public static function rawUuidFromFormatted( string $uuid ): string {
		// If it's a legacy ID, return as is
		if ( self::isLegacyId( $uuid ) ) {
			return $uuid;
		}

		// If it's already in raw format, return as is
		if ( preg_match( '/^[0-9a-f]{32}$/i', $uuid ) ) {
			return $uuid;
		}

		// Otherwise validate and convert from formatted
		if ( !preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid ) ) {
			throw new InvalidArgumentException(
				'Invalid UUID format. Expected either 32 hex characters or format: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'
			);
		}

		return str_replace( '-', '', $uuid );
	}

	/**
	 * Check if ID is a legacy format (from uniqid)
	 * @param string $id
	 * @return bool
	 */
	private static function isLegacyId( string $id ): bool {
		// uniqid() typically produces 13-23 character strings that aren't valid UUIDs
		return !preg_match( '/^[0-9a-f]{32}$/i', $id ) &&
			!preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id );
	}

	/**
	 * Make a request to the RAG API
	 *
	 * @param string $endpoint The API endpoint to call (without the base URL)
	 * @param array|null $postData Optional data to send in a POST request
	 * @param string $method HTTP method (GET or POST)
	 * @return Status Status object with the result or error
	 */
	public static function makeRagApiRequest(
		string $endpoint, ?array $postData = null, string $method = 'GET' ): Status {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$httpFactory = $services->getHttpRequestFactory();

		$apiUrl = rtrim( $config->get( 'KZChatbotLlmApiUrl' ), '/' ) . '/' . ltrim( $endpoint, '/' );

		$options = [
			'method' => $method,
			'timeout' => 30,
			'headers' => [
				'Accept' => 'application/json'
			]
		];

		if ( $postData !== null && $method === 'POST' ) {
			$options['headers']['Content-Type'] = 'application/json';
			$options['postData'] = json_encode( $postData );
		}

		$request = $httpFactory->create( $apiUrl, $options, __METHOD__ );
		try {
			$status = $request->execute();
		} catch ( \Exception $e ) {
			return Status::newFatal( 'kzchatbot-rag-settings-error-execution-failed', $e->getMessage() );
		}
		if ( !$status->isOK() ) {
			return Status::newFatal( 'kzchatbot-rag-settings-error-api-unreachable' );
		}

		$content = $request->getContent();
		$result = json_decode( $content, true );

		if ( $result === null && json_last_error() !== JSON_ERROR_NONE ) {
			return Status::newFatal( 'kzchatbot-rag-settings-error-invalid-response' );
		}

		return Status::newGood( $result );
	}

	/**
	 * Get RAG configuration
	 *
	 * @return Status Status object with the config or error
	 */
	public static function getRagConfig(): Status {
		return self::makeRagApiRequest( 'get_config' );
	}

	/**
	 * Get RAG models version information
	 *
	 * @return Status Status object with the model version or error
	 */
	public static function getModelsVersion(): Status {
		$status = self::makeRagApiRequest( 'get_models_version' );
		if ( $status->isOK() ) {
			$status->setResult( true, $status->getValue()[0] );
		}

		return $status;
	}
}
