<?php

namespace MediaWiki\Extension\KZChatbot;

use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

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
			'kzcbu_ranking_eligible_answer_id' => 'eligibleAnswerId',
			'chatbot_prominence' => 'chatbotProminence'
		];
	}

	/**
	 * @return array|bool
	 */
	public static function newUser() {
		$settings = self::getGeneralSettings();
		$cookieExpiry = time() + ( $settings['cookie_expiry_days'] ?? 356 ) * 24 * 60 * 60;
		$newUsersChatbotRate = $settings['new_users_chatbot_rate'] ?? 100;
		$activeUsersLimit = $settings['active_users_limit'];
		$activeUsersLimitDays = $settings['active_users_limit_days'] ?? 30;
		// Show this user the chatbot?
		$dbw = wfGetDB( DB_PRIMARY );
		$currentAverage = $dbw->select(
			[ 'kzchatbot_users' ],
			[ 'AVG(kzcbu_is_shown) AS average' ],
			[],
			__METHOD__,
		)->fetchRow();
		$isShown = empty( $currentAverage['average'] ) ? ( $newUsersChatbotRate > 0 )
			: ( $currentAverage['average'] <= ( $newUsersChatbotRate / 100 ) );
		if ( $isShown && !empty( $activeUsersLimit ) ) {
			// Need also to check that we haven't hit the absolute maximum on active users.
			$activeUsersCount = $dbw->select(
				[ 'kzchatbot_users' ],
				[ 'COUNT(*) as count' ],
				[ 'kzcbu_last_active <= ' . wfTimestamp( TS_MW, time() - ( $activeUsersLimitDays * 24 * 60 * 60 ) ) ],
				__METHOD__,
			)->fetchRow();
			$isShown = empty( $activeUsersCount['count'] ) ? 1 : ( $activeUsersLimit < $activeUsersCount['count'] );
		}
		// Build and insert new user record
		$userData = [
			'kzcbu_uuid' => uniqid(),
			'kzcbu_is_shown' => $isShown,
			'kzcbu_cookie_expiry' => wfTimestamp( TS_MW, $cookieExpiry ),
			'kzcbu_ip_address' => $_SERVER['SERVER_ADDR'],
			'kzcbu_last_active' => wfTimestamp( TS_MW ),
			'kzcbu_questions_last_active_day' => 0,
		];
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
	 * @return array|bool
	 */
	public static function getBannedWords() {
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			[ 'settings' => 'kzchatbot_settings' ],
			[ 'kzcbs_name', 'kzcbs_value' ],
			[ 'settings.kzcbs_name' => 'banned_words' ],
			__METHOD__,
		);
		$row = $res->fetchRow();
		$bannedWords = !empty( $row ) ? json_decode( $row['kzcbs_value'] ) : [];
		return $bannedWords;
	}

	/**
	 * @return array|bool
	 */
	public static function getSlugs() {
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			[ 'text' => 'kzchatbot_text' ],
			[ 'kzcbt_slug', 'kzcbt_text' ],
			[ '1=1' ],
			__METHOD__,
			[ 'kzcbt_slug' => 'ASC' ]
		);
		$slugs = [];
		for ( $slug = $res->fetchRow(); !empty( $slug ); $slug = $res->fetchRow() ) {
			$slugs[ $slug['kzcbt_slug'] ] = $slug['kzcbt_text'];
		}
		return $slugs;
	}

	/**
	 * @return array
	 */
	public static function getGeneralSettingsNames() {
		return [
			'new_users_chatbot_rate', 'active_users_limit', 'active_users_limit_days', 'chatbot_prominence',
			'questions_daily_limit', 'question_words_limit', 'cookie_expiry_days', 'uuid_request_limit'
		];
	}

	public static function getQuestionsPermitted( $uuid ) {
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
	public static function incrementQuestionsLastActiveDay( $uuid ) {
		$dbw = wfGetDB( DB_PRIMARY );
		$dbw->update(
			'kzchatbot_users',
			[
				'kzcbu_questions_last_active_day = kzcbu_questions_last_active_day + 1',
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

		// Sanitize data.
		foreach ( array_diff( $generalSettingsNames, [ 'chatbot_prominence' ] ) as $intField ) {
			if ( !empty( $data[$intField] ) ) {
				$data[$intField] = intval( $data[$intField] );
			}
		}
		if ( !in_array( $data['chatbot_prominence'], [ 'low', 'high' ] ) ) {
			$data['chatbot_prominence'] = 'low';
		}

		// Clear prior values.
		$dbw->delete(
			'kzchatbot_settings',
			[ 'kzcbs_name' => $generalSettingsNames ]
		);

		// Insert data.
		$insertRows = array_map(
			fn( $name ) => [
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
	 * @param string $newWord
	 * @return \IResultWrapper
	 */
	public static function saveBannedWord( $newWord ) {
		$bannedWords = self::getBannedWords();
		$bannedWords[] = $newWord;
		$dbw = wfGetDB( DB_PRIMARY );

		// Clear prior value.
		$dbw->delete(
			'kzchatbot_settings',
			[ 'kzcbs_name' => 'banned_words' ]
		);

		// Insert updated data.
		return $dbw->insert(
			'kzchatbot_settings',
			[
				'kzcbs_name' => 'banned_words',
				'kzcbs_value' => json_encode( $bannedWords ),
			],
			__METHOD__
		);
	}

	/**
	 * @param string $slug
	 * @param string $text
	 * @return \IResultWrapper
	 */
	public static function saveSlug( $slug, $text ) {
		// @TODO: additional data sanitization?
		$dbw = wfGetDB( DB_PRIMARY );
		// Clear prior value if one exists.
		$dbw->delete(
			'kzchatbot_text',
			[ 'kzcbt_slug' => $slug ]
		);
		// Insert and return result.
		return $dbw->insert(
			'kzchatbot_text',
			[
				'kzcbt_slug' => $slug,
				'kzcbt_text' => $text,
			],
			__METHOD__
		);
	}

	/**
	 * @param int $wordIndex
	 * @return \IResultWrapper
	 */
	public static function deleteBannedWord( $wordIndex ) {
		$bannedWords = self::getBannedWords();
		array_splice( $bannedWords, $wordIndex, 1 );
		$dbw = wfGetDB( DB_PRIMARY );

		// Clear prior value.
		$dbw->delete(
			'kzchatbot_settings',
			[ 'kzcbs_name' => 'banned_words' ]
		);

		// Insert updated data.
		return $dbw->insert(
			'kzchatbot_settings',
			[
				'kzcbs_name' => 'banned_words',
				'kzcbs_value' => json_encode( $bannedWords ),
			],
			__METHOD__
		);
	}

	/**
	 * @param text $slug
	 * @return \IResultWrapper
	 */
	public static function deleteSlug( $slug ) {
		// @TODO: additional data sanitization?
		$dbw = wfGetDB( DB_PRIMARY );
		return $dbw->delete(
			'kzchatbot_text',
			[ 'kzcbt_slug' => $slug ]
		);
	}

}
