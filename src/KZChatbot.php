<?php

namespace MediaWiki\Extension\KZChatbot;

use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\DBError;

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


	public static function getSlugs() {
		return array_merge( self::getDefaultSlugs(), self::getSlugsFromDB() );
	}

	public static function getDefaultSlugs() {
		return [
			'chat_icon' => 'כל שאלה',
			'chat_tip_link' => 'טיפים לניסוח שאלה טובה',
			'close_chat_icon' => 'סגירה',
			'dislike_follow_up_question' => 'תודה על המשוב. נשמח לדעת למה.',
			'dislike_followup_q_first' => 'המידע לא נכון',
			'dislike_followup_q_second' => 'התשובה לא קשורה לשאלה',
			'dislike_followup_q_third' => 'התשובה לא ברורה',
			'feedback_free_text' => 'רוצה לפרט? זה יעזור לנו להשתפר',
			'feedback_free_text_disclaimer' => 'אין לשתף פרטים מזהים או מידע רגיש',
			'new_question_button' => 'שאלה חדשה',
			'new_question_filed' => 'שאלה חדשה',
			'question_disclaimer' => 'אין לשתף פרטים מזהים או מידע רגיש',
			'question_field' => 'מה רצית לדעת',
			'ranking_request' => 'האם התשובה עזרה לך?',
			'returning_links_title' => 'כדאי לבדוק את התשובה גם כאן =>',
			'tc_link' => 'תנאי שימוש',
			'welcome_message_first' => 'שלום! הצ\'אט של \'כל זכות\' יכול למצוא לך תשובות מתוך \'כל זכות\' מהר ובקלות בעזרת בינה מלאכותית. אפשר לשאול כל שאלה על זכויות בשפה חופשית. כדאי לציין מאפיינים רלוונטיים כמו גיל ומצב משפחתי.',
			'welcome_message_second' => 'חשוב * אין למסור מידע מזהה או רגיש כמו שם, כתובת או מידע רפואי. המידע נאסף לצורך שיפור השירות. * הצ\'אט יכול לטעות. כל זכות אינה אחראית לנכונות התשובות וממליצה לבדוק את המידע גם בעמוד המתאים באתר. בתקופת ההרצה הצ\'אט יופיע רק לחלק מהגולשים.',
			'welcome_message_third' => null,
			'feedback_character_limit' => 'מקסימום $1 תווים',
			'questions_daily_limit' => 'לא ניתן לשאול שאלות נוספות היום',
			'question_character_limit' => 'מקסימום $1 תווים',
			'banned_word_found' => 'אנא נסחו מחדש את השאלה'
		];
	}

	/**
	 * @return array
	 */
	public static function getSlugsFromDB() {
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
	public static function useQusetion( $uuid ) {
		$userData = self::getUserData( $uuid );
		$lastActiveDay = date( 'z', wfTimestamp( TS_UNIX, $userData['kzcbu_last_active'] ) );
		$questionsLastActiveDay = $lastActiveDay === date( 'z' ) ? ( $userData['kzcbu_questions_last_active_day'] ?? 0 ) : 0;
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
	 * @return true
	 * @throws \MWException
	 */
	public static function saveSlug( string $slug, string $text ) {
		$slugs = self::getDefaultSlugs();
		if ( !array_key_exists( $slug, $slugs ) ) {
			throw new \MWException( 'invalid slug name' );
		}
		if ( $text === $slugs[$slug] ) {
			throw new \MWException( 'same as default text' );
		}
		$dbw = wfGetDB( DB_PRIMARY );
		// Clear prior value if one exists.
		$dbw->upsert(
			'kzchatbot_text',
			[
				'kzcbt_slug' => $slug,
				'kzcbt_text' => $text,
			],
			'kzcbt_slug',
			[
				'kzcbt_text' => $text
			]
		);

		return true;
	}

	/**
	 * @param string $slug
	 * @return bool
	 * @throws \MWException
	 */
	public static function deleteSlug( $slug ) {
		if ( !self::isValidSlugName( $slug ) ) {
			throw new \MWException( 'invalid slug name' );
		}
		$dbw = wfGetDB( DB_PRIMARY );
		return $dbw->delete(
			'kzchatbot_text',
			[ 'kzcbt_slug' => $slug ]
		);
	}

	public static function isValidSlugName( $slug ): bool {
		$slugs = KZChatbot::getDefaultSlugs();
		return ( array_key_exists( $slug, $slugs ) );
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

}
