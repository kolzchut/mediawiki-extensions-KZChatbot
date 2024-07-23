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
	 * @return array|bool
	 */
	public static function getGeneralSettings() {
		$dbr = wfGetDB( DB_REPLICA );
		$generalSettingsNames = self::getGeneralSettingsNames();
		$res = $dbr->select(
			[ 'settings' => 'kzchatbot_settings' ],
			[ 'kzcbs_name', 'kzcbs_value' ],
			[ 'settings.kzcbs_name' => $generalSettingsNames ],
			__METHOD__,
		);
		$settings = [];
		for ( $setting = $res->fetchRow(); !empty( $setting ); $setting = $res->fetchRow() ) {
			$settings[ $setting['kzcbs_name'] ] = $setting['kzcbs_value'];
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
		//@TODO: additional data sanitization?
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
		//@TODO: additional data sanitization?
		$dbw = wfGetDB( DB_PRIMARY );
		return $dbw->delete(
			'kzchatbot_text',
			[ 'kzcbt_slug' => $slug ]
		);
	}

}
