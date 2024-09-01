<?php

namespace MediaWiki\Extension\KZChatbot;

class Slugs {
	/**
	 * @var array|null of texts
	 */
	protected static ?array $slugsRaw;

	/**
	 * @param string $slugName
	 * @return bool
	 */
	public static function isValidSlugName( string $slugName ): bool {
		$slugs = self::getDefaultSlugs();
		return ( array_key_exists( $slugName, $slugs ) );
	}

	/**
	 * Get the default slugs
	 * @return array
	 * @todo move these to MW i18n json format?
	 *
	 */
	public static function getDefaultSlugs(): array {
        // phpcs:disable Generic.Files.LineLength.TooLong
		return [
			'chat_icon' => 'כל שאלה',
			'chat_tip_link' => 'טיפים לניסוח שאלה טובה',
			'close_chat_icon' => 'סגירה',
			'dislike_follow_up_question' => 'תודה! נשמח לדעת למה',
			'dislike_followup_q_first' => 'המידע לא נכון',
			'dislike_followup_q_second' => 'התשובה לא קשורה לשאלה',
			'dislike_followup_q_third' => 'התשובה לא ברורה',
			'dislike_free_text' => 'רוצה לפרט? זה יעזור לנו להשתפר',
			'like_follow_up_question' => 'תודה!',
			'like_free_text' => 'רוצה לפרט?',
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
			'banned_word_found' => 'אנא נסחו מחדש את השאלה',
			'general_error' => 'אירעה שגיאה במערכת. אנא נסו שנית מאוחר יותר.'
		];
        // phpcs:enable Generic.Files.LineLength.TooLong
	}

	/**
	 * Get a single slug text, without parameter replacement
	 * @param string $slugName
	 * @return string|null
	 */
	public static function getSlugRaw( string $slugName ): ?string {
		return self::getSlugsRaw()[$slugName] ?? null;
	}

	/**
	 * Get the final list of slugs, including overrides saved in the database
	 *
	 * @return array
	 */
	public static function getSlugsRaw(): array {
		if ( !isset( self::$slugsRaw ) ) {
			self::$slugsRaw = array_merge( self::getDefaultSlugs(), self::getSlugsFromDB() );
		}

		return self::$slugsRaw;
	}

	/**
	 * @param string $slug
	 * @return bool
	 * @throws \MWException
	 */
	public static function deleteSlug( string $slug ): bool {
		if ( !self::isValidSlugName( $slug ) ) {
			throw new \MWException( 'invalid slug name' );
		}

		// Reset the static cache, so it is refreshed next time
		self::$slugsRaw = null;

		$dbw = wfGetDB( DB_PRIMARY );
		return $dbw->delete(
			'kzchatbot_text',
			[ 'kzcbt_slug' => $slug ]
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
		if ( !self::isValidSlugName( $slug ) ) {
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

		// Update the static cache
		if ( isset( self::$slugsRaw ) ) {
			self::$slugsRaw[$slug] = $text;
		}

		return true;
	}

	/**
	 * This returns the slugs from self::getSlugs(), but replaced parameters with the real values
	 *
	 * @return array
	 */
	public static function getSlugs() {
		$slugs = self::getSlugsRaw();
		$settings = KZChatbot::getGeneralSettings();

		$slugs['feedback_character_limit'] = str_replace(
			'$1', $settings['feedback_character_limit'], $slugs['feedback_character_limit']
		);

		$slugs['question_character_limit'] = str_replace(
			'$1', $settings['question_character_limit'], $slugs['question_character_limit']
		);

		return $slugs;
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
			$slugs[$slug['kzcbt_slug']] = $slug['kzcbt_text'];
		}
		return $slugs;
	}

	/**
	 * Get a single slug text
	 * @param string $slugName
	 * @return string|null
	 */
	public static function getSlug( string $slugName ) {
		$slugs = self::getSlugs();
		return $slugs[$slugName] ?? null;
	}
}
