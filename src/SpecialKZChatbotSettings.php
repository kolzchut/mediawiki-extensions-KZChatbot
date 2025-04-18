<?php

namespace MediaWiki\Extension\KZChatbot;

use Html;
use HTMLForm;
use MediaWiki\MediaWikiServices;
use PermissionsError;
use SpecialPage;

/**
 * General settings management interface for the Kol-Zchut chatbot.
 *
 * @ingroup SpecialPage
 */
class SpecialKZChatbotSettings extends SpecialPage {

	/** @var bool */
	private bool $isAllowedEdit;

	/** @var bool */
	private bool $isAllowedView;

	/**
	 * @inheritDoc
	 */
	public function __construct() {
		parent::__construct( 'KZChatbotSettings' );
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription() {
		return $this->msg( 'kzchatbot-desc' )->text();
	}

	/**
	 * Special page: General configuration settings for the Kol-Zchut chatbot.
	 * @param string|null $par Parameters passed to the page
	 * @throws PermissionsError
	 */
	public function execute( $par ) {
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		$this->isAllowedView = $permissionManager->userHasRight( $this->getUser(), 'kzchatbot-view-settings' );
		$this->isAllowedEdit = $permissionManager->userHasRight( $this->getUser(), 'kzchatbot-edit-settings' );

		// Check if user can at least view
		if ( !$this->isAllowedView && !$this->isAllowedEdit ) {
			throw new PermissionsError( 'kzchatbot-view-settings' );
		}

		parent::execute( $par );
		$output = $this->getOutput();

		// Show view-only notice if user can't manage
		if ( !$this->isAllowedEdit ) {
			$output->addWikiMsg( 'kzchatbot-settings-view-only' );
		}

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

		// Build form.
		$output->setPageTitle( $this->msg( 'kzchatbot-settings-title' ) );
		$htmlForm = HTMLForm::factory( 'ooui', $this->getSettingsForm(), $this->getContext() );
		$htmlForm->setId( 'KZChatbotSettingsForm' )
			->setFormIdentifier( 'KZChatbotSettingsForm' );

		// If user only has view permission, make form read-only
		if ( $this->isAllowedEdit ) {
			$htmlForm->setSubmitName( "kzcSubmit" )
				->setSubmitTextMsg( 'kzchatbot-settings-submit' )
				->setSubmitCallback( [ $this, 'handleSettingsSave' ] );
		} else {
			$htmlForm->setAction( null )->suppressDefaultSubmit();
		}

		$htmlForm->show();
	}

	/**
	 * @return array
	 */
	private function getFormNameToDbNameMapping() {
		return [
			'kzcNewUsersChatbotRate' => 'new_users_chatbot_rate',
			'kzcActiveUsersLimit' => 'active_users_limit',
			'kzcActiveUsersLimitDays' => 'active_users_limit_days',
			'kzcQuestionsDailyLimit' => 'questions_daily_limit',
			'kzcQuestionCharacterLimit' => 'question_character_limit',
			'kzcFeedbackCharacterLimit' => 'feedback_character_limit',
			'kzcCookieExpiryDays' => 'cookie_expiry_days',
			'kzcUUIDRequestLimit' => 'uuid_request_limit',
			'kzcUsageHelpUrl' => 'usage_help_url',
			'kzcTermsOfServiceUrl' => 'terms_of_service_url'
		];
	}

	/**
	 * Define settings form structure
	 * @return array
	 */
	private function getSettingsForm() {
		$form = [
			'kzcNewUsersChatbotRate' => [
				'type' => 'int',
				'label-message' => 'kzchatbot-settings-label-new-users-chatbot-rate',
				'help-message' => 'kzchatbot-settings-help-new-users-chatbot-rate',
				'section' => 'kzchatbot-settings-section-throttle',
				'required' => true,
				// Min/Max values
				'min' => 0,
				'max' => 100,
			],
			'kzcActiveUsersLimit' => [
				'type' => 'int',
				'label-message' => 'kzchatbot-settings-label-active-users-limit',
				'help-message' => 'kzchatbot-settings-help-active-users-limit',
				'section' => 'kzchatbot-settings-section-throttle',
				'required' => false,
				'min' => 0,
			],
			'kzcCurrentActiveUsers' => [
				'type' => 'info',
				'label-message' => 'kzchatbot-settings-label-current-active-users',
				'section' => 'kzchatbot-settings-section-throttle',
				'default' => (string)KZChatbot::getCurrentActiveUsersCount(),
			],
			'kzcActiveUsersLimitDays' => [
				'type' => 'int',
				'label-message' => 'kzchatbot-settings-label-active-users-limit-days',
				'help-message' => 'kzchatbot-settings-help-active-users-limit-days',
				'section' => 'kzchatbot-settings-section-throttle',
				'required' => true,
			],
			'kzcQuestionsDailyLimit' => [
				'type' => 'int',
				'label-message' => 'kzchatbot-settings-label-questions-daily-limit',
				'section' => 'kzchatbot-settings-section-per-user',
				'required' => true,
			],
			'kzcQuestionCharacterLimit' => [
				'type' => 'int',
				'label-message' => 'kzchatbot-settings-label-question-character-limit',
				'section' => 'kzchatbot-settings-section-per-user',
				'required' => true,
			],
			'kzcFeedbackCharacterLimit' => [
				'type' => 'int',
				'label-message' => 'kzchatbot-settings-label-feedback-character-limit',
				'section' => 'kzchatbot-settings-section-per-user',
				'required' => true,
				'min' => 0
			],
			'kzcCookieExpiryDays' => [
				'type' => 'int',
				'label-message' => 'kzchatbot-settings-label-cookie-expiry-days',
				'help-message' => 'kzchatbot-settings-help-cookie-expiry-days',
				'section' => 'kzchatbot-settings-section-per-user',
				'required' => true,
			],
			'kzcUUIDRequestLimit' => [
				'type' => 'int',
				'label-message' => 'kzchatbot-settings-label-uuid-request-limit',
				'help-message' => 'kzchatbot-settings-help-uuid-request-limit',
				'section' => 'kzchatbot-settings-section-per-user',
				'required' => true,
			],
			'kzcTermsOfServiceUrl' => [
				'type' => 'url',
				'label-message' => 'kzchatbot-settings-label-terms-of-service-url',
				'section' => 'kzchatbot-settings-section-general',
				'required' => true,
			],
			'kzcUsageHelpUrl' => [
				'type' => 'url',
				'label-message' => 'kzchatbot-settings-label-usage-help-url',
				'section' => 'kzchatbot-settings-section-general',
				'required' => true,
			],
		];

		// Determine form defaults from current settings.
		$settings = KZChatbot::getGeneralSettings();
		$formNameToDbName = $this->getFormNameToDbNameMapping();
		foreach ( $form as $inputName => &$attribs ) {
			$valueName = $formNameToDbName[ $inputName ] ?? null;
			if ( isset( $settings[$valueName] ) ) {
				$attribs['default'] = $settings[$valueName];
			} elseif ( $attribs['type'] === 'int' ) {
				$attribs['default'] = 0;
			}

			if ( !$this->isAllowedEdit ) {
				$attribs['disabled'] = true;
			}
		}

		return $form;
	}

	/**
	 * Handle settings form submission
	 * @param array $postData Form submission data
	 * @return string|bool Return true on success, error message on failure
	 */
	public function handleSettingsSave( $postData ) {
		// Save new values to database.
		$formNameToDbName = $this->getFormNameToDbNameMapping();
		$data = array_map(
			fn ( $formName ) => $postData[$formName],
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
