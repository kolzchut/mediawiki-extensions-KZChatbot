<?php

namespace MediaWiki\Extension\KZChatbot;

use Html;
use HTMLForm;
use SpecialPage;

/**
 * General settings management interface for the Kol-Zchut chatbot.
 *
 * @ingroup SpecialPage
 */
class SpecialKZChatbotSettings extends SpecialPage {

	/**
	 * @inheritDoc
	 */
	public function __construct() {
		parent::__construct( 'KZChatbotSettings', 'manage-kolzchut-chatbot' );
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription() {
		return $this->msg( 'kolzchut-chatbot-desc' )->text();
	}

	/**
	 * Special page: General configuration settings for the Kol-Zchut chatbot.
	 * @param string|null $par Parameters passed to the page
	 */
	public function execute( $par ) {
		parent::execute( $par );
		$output = $this->getOutput();

		// Successful save? If so, show status message.
		$session = $this->getRequest()->getSession();
		if ( $session->get( 'kzChatbotSettingsSaveSuccess' ) ) {
			// Remove session data for the success message
			$session->remove( 'kzChatbotSettingsSaveSuccess' );
			$output->addModules( 'ext.KZChatbot.form' );
			$output->addModuleStyles( 'mediawiki.notification.convertmessagebox.styles' );
			$output->addHTML(
				Html::rawElement(
					'div',
					[
						'class' => 'mw-preferences-messagebox mw-notify-success successbox',
						'id' => 'mw-preferences-success',
						'data-mw-autohide' => 'false',
					],
					Html::element( 'p', [], $this->msg( 'kzchatbot-settings-status-save-success' )->text() )
				)
			);
		}

		// Provide links to other admin pages.
		$bannedWordsPage = SpecialPage::getTitleFor( 'KZChatbotBannedWords' );
		$output->addHTML(
			Html::rawElement(
				'p',
				[
					'class' => 'kzc-banned-words-link',
				],
				Html::element(
					'a',
					[ 'href' => $bannedWordsPage ],
					$this->msg( 'kzchatbot-toplink-banned-words' )->text()
				)
			)
		);
		$slugsPage = SpecialPage::getTitleFor( 'KZChatbotSlugs' );
		$output->addHTML(
			Html::rawElement(
				'p',
				[
					'class' => 'kzc-slugs-link',
				],
				Html::element(
					'a',
					[ 'href' => $slugsPage ],
					$this->msg( 'kzchatbot-toplink-slugs' )->text()
				)
			)
		);

		// Determine form defaults from current settings.
		$settings = KZChatbot::getGeneralSettings();
		$defaults = [];
		foreach ( $this->getFormNameToDbNameMapping() as $inputName => $dbField ) {
			$defaults[ $inputName ] = isset( $settings[ $dbField ] ) ? $settings[ $dbField ] : '0';
		}
		if ( $defaults['kzcChatbotProminence'] == '0' ) {
			$defaults['kzcChatbotProminence'] = 'low';
		}

		// Build form.
		$output->setPageTitle( $this->msg( 'kzchatbot-settings-title' ) );
		$htmlForm = HTMLForm::factory( 'ooui', $this->getSettingsForm( $defaults ), $this->getContext() );
		$htmlForm->setId( 'KZChatbotSettingsForm' )
			->setFormIdentifier( 'KZChatbotSettingsForm' )
			->setSubmitName( "kzcSubmit" )
			->setSubmitTextMsg( 'kzchatbot-settings-submit' )
			->setSubmitCallback( [ $this, 'handleSettingsSave' ] )
			->show();
	}

	/**
	 * @return array
	 */
	private function getFormNameToDbNameMapping() {
		return [
			'kzcNewUsersChatbotRate' => 'new_users_chatbot_rate',
			'kzcActiveUsersLimit' => 'active_users_limit',
			'kzcActiveUsersLimitDays' => 'active_users_limit_days',
			'kzcChatbotProminence' => 'chatbot_prominence',
			'kzcQuestionsDailyLimit' => 'questions_daily_limit',
			'kzcQuestionWordsLimit' => 'question_words_limit',
			'kzcCookieExpiryDays' => 'cookie_expiry_days',
			'kzcUUIDRequestLimit' => 'uuid_request_limit',
		];
	}

	/**
	 * Define settings form structure
	 * @param array $defaults
	 * @return array
	 */
	private function getSettingsForm( $defaults ) {
		$form = [
			'kzcNewUsersChatbotRate' => [
				'type' => 'text',
				'default' => $defaults['kzcNewUsersChatbotRate'],
				'cssclass' => 'ksl-new-users-chatbot-rate',
				'label-message' => 'kzchatbot-settings-label-new-users-chatbot-rate',
				'help-message' => 'kzchatbot-settings-help-new-users-chatbot-rate',
				'section' => 'kzchatbot-settings-section-throttle',
				'required' => true,
			],
			'kzcActiveUsersLimit' => [
				'type' => 'text',
				'default' => $defaults['kzcActiveUsersLimit'],
				'cssclass' => 'ksl-active-users-limit',
				'label-message' => 'kzchatbot-settings-label-active-users-limit',
				'help-message' => 'kzchatbot-settings-help-active-users-limit',
				'section' => 'kzchatbot-settings-section-throttle',
				'required' => true,
			],
			'kzcActiveUsersLimitDays' => [
				'type' => 'text',
				'default' => $defaults['kzcActiveUsersLimitDays'],
				'cssclass' => 'ksl-active-users-limit-days',
				'label-message' => 'kzchatbot-settings-label-active-users-limit-days',
				'help-message' => 'kzchatbot-settings-help-active-users-limit-days',
				'section' => 'kzchatbot-settings-section-throttle',
				'required' => true,
			],
			'kzcChatbotProminence' => [
				'type' => 'select',
				'options' => [
					$this->msg( 'kzchatbot-settings-option-chatbot-prominence-low' )->text() => 'low',
					$this->msg( 'kzchatbot-settings-option-chatbot-prominence-high' )->text() => 'high',
				],
				'default' => $defaults['kzcChatbotProminence'],
				'cssclass' => 'ksl-chatbot-prominence',
				'label-message' => 'kzchatbot-settings-label-chatbot-prominence',
				'help-message' => 'kzchatbot-settings-help-chatbot-prominence',
				'section' => 'kzchatbot-settings-section-display',
				'required' => true,
			],
			'kzcQuestionsDailyLimit' => [
				'type' => 'text',
				'default' => $defaults['kzcQuestionsDailyLimit'],
				'cssclass' => 'ksl-questions-daily-limit',
				'label-message' => 'kzchatbot-settings-label-questions-daily-limit',
				'help-message' => 'kzchatbot-settings-help-questions-daily-limit',
				'section' => 'kzchatbot-settings-section-per-user',
				'required' => true,
			],
			'kzcQuestionWordsLimit' => [
				'type' => 'text',
				'default' => $defaults['kzcQuestionWordsLimit'],
				'cssclass' => 'ksl-question-words-limit',
				'label-message' => 'kzchatbot-settings-label-question-words-limit',
				'help-message' => 'kzchatbot-settings-help-question-words-limit',
				'section' => 'kzchatbot-settings-section-per-user',
				'required' => true,
			],
			'kzcCookieExpiryDays' => [
				'type' => 'text',
				'default' => $defaults['kzcCookieExpiryDays'],
				'cssclass' => 'ksl-uuid-cookie-expiry-days',
				'label-message' => 'kzchatbot-settings-label-cookie-expiry-days',
				'help-message' => 'kzchatbot-settings-help-cookie-expiry-days',
				'section' => 'kzchatbot-settings-section-per-user',
				'required' => true,
			],
			'kzcUUIDRequestLimit' => [
				'type' => 'text',
				'default' => $defaults['kzcUUIDRequestLimit'],
				'cssclass' => 'ksl-uuid-request-limit',
				'label-message' => 'kzchatbot-settings-label-uuid-request-limit',
				'help-message' => 'kzchatbot-settings-help-uuid-request-limit',
				'section' => 'kzchatbot-settings-section-per-user',
				'required' => true,
			],
		];
		return $form;
	}

	/**
	 * Handle settings form submission
	 * @param array $postData Form submission data
	 * @return string|bool Return true on success, error message on failure
	 */
	public function handleSettingsSave( $postData ) {
		// Check integer values.
		$numericFields = [
			'kzcNewUsersChatbotRate' => 'new-users-chatbot-rate',
			'kzcActiveUsersLimit' => 'active-users-limit',
			'kzcActiveUsersLimitDays' => 'active-users-limit-days',
			'kzcQuestionsDailyLimit' => 'questions-daily-limit',
			'kzcQuestionWordsLimit' => 'question-words-limit',
			'kzcCookieExpiryDays' => 'cookie-expiry-days',
			'kzcUUIDRequestLimit' => 'uuid-request-limit',
		];
		foreach ( array_keys( $numericFields ) as $fieldName ) {
			if ( !isset( $postData[$fieldName] ) || !is_numeric( $postData[$fieldName] )
				|| intval( $postData[$fieldName] ) != $postData[$fieldName]
				|| $postData[$fieldName] < 0
			) {
				return [ [
					'kzchatbot-settings-error-integer-required',
					$this->msg( 'kzchatbot-settings-label-' . $numericFields[$fieldName] )->text()
				] ];
			}
		}

		// Chatbot rate should be 0-100.
		if ( $postData['kzcNewUsersChatbotRate'] > 100 ) {
			return [ [
				'kzchatbot-settings-error-new-users-chatbot-rate',
				$this->msg( 'kzchatbot-settings-label-new-users-chatbot-rate' )->text()
			] ];
		}

		// Save new values to database.
		$formNameToDbName = $this->getFormNameToDbNameMapping();
		$data = array_map(
			fn( $formName ) => $postData[$formName],
			array_flip( $formNameToDbName )
		);
		KZChatbot::saveGeneralSettings( $data );

		// Set session data for the success message
		$this->getRequest()->getSession()->set( 'kzChatbotSettingsSaveSuccess', 1 );

		// Return to form.
		$url = $this->getPageTitle()->getFullUrlForRedirect();
		$this->getOutput()->redirect( $url );
		return true;
	}

}
