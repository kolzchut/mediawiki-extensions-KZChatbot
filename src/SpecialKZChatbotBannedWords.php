<?php

namespace MediaWiki\Extension\KZChatbot;

use Exception;
use Html;
use HTMLForm;
use SpecialPage;

/**
 * Management interface for banned words/patterns in the Kol-Zchut chatbot.
 *
 * @ingroup SpecialPage
 */
class SpecialKZChatbotBannedWords extends SpecialPage {

	/**
	 * @inheritDoc
	 */
	public function __construct() {
		parent::__construct( 'KZChatbotBannedWords', 'manage-kolzchut-chatbot' );
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription() {
		return $this->msg( 'kolzchut-chatbot-desc' )->text();
	}

	/**
	 * Special page: Banned words/patterns in the Kol-Zchut chatbot.
	 * @param string|null $par Parameters passed to the page
	 */
	public function execute( $par ) {
		parent::execute( $par );
		$output = $this->getOutput();
		$output->addModules( 'ext.KZChatbot.form' );

		// Delete operation?
		$params = $this->getRequest()->getQueryValues();
		$editId = isset( $params['edit'] ) && is_numeric( $params['edit'] ) ? intval( $params['edit'] ) : null;

		if ( isset( $params['delete'] ) && is_numeric( $params['delete'] )
			&& $params['delete'] == intval( $params['delete'] )
		) {
			$this->handleBannedWordDelete( $params['delete'] );
			return;
		}

		// Successful operation? If so, show status message.
		$session = $this->getRequest()->getSession();
		$savedWord = $session->get( 'kzBannedWordSaved' );
		$deletedWord = $session->get( 'kzBannedWordDeleted' );
		if ( !empty( $savedWord ) || !empty( $deletedWord ) ) {
			// Remove session data for the success message
			$session->remove( !empty( $savedWord ) ? 'kzBannedWordSaved' : 'kzBannedWordDeleted' );
			$output->addModuleStyles( 'mediawiki.notification.convertmessagebox.styles' );
			$message = !empty( $savedWord ) ? 'kzchatbot-banned-words-status-save-success'
				: 'kzchatbot-banned-words-status-delete-success';
			$output->addHTML(
				Html::rawElement(
					'div',
					[
						'class' => 'mw-preferences-messagebox mw-notify-success successbox',
						'id' => 'mw-preferences-success',
						'data-mw-autohide' => 'false',
					],
					Html::element(
						'p', [],
						$this->msg( $message, !empty( $savedWord ) ? $savedWord : $deletedWord )->text()
					)
				)
			);
		}

		// Provide links to other admin pages.
		$settingsPage = SpecialPage::getTitleFor( 'KZChatbotSettings' );
		$output->addHTML(
			Html::rawElement(
				'p',
				[
					'class' => 'kzc-settings-link',
				],
				Html::element(
					'a',
					[ 'href' => $settingsPage ],
					$this->msg( 'kzchatbot-toplink-general-settings' )->text()
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

		// Build table of existing banned words/patterns.
		$bannedWords = BannedWord::getAll();
		if ( !empty( $bannedWords ) ) {
			$output->addModuleStyles( 'jquery.tablesorter.styles' );
			$output->addModules( 'jquery.tablesorter' );
			$output->addHTML(
				Html::openElement(
					'table',
					[ 'class' => 'mw-datatable sortable', 'id' => 'kzchatbot-banned-words-table' ]
				)
				. Html::openElement( 'thead' ) . Html::openElement( 'tr' )
				. Html::element( 'th', [], $this->msg( 'kzchatbot-banned-words-label-banned-word' )->text() )
				. Html::element( 'th', [], $this->msg( 'kzchatbot-banned-words-label-banned-word-desc' )->text() )
				. Html::element( 'th', [], $this->msg( 'kzchatbot-banned-words-label-reply-message' )->text() )
				. Html::element( 'th' )
				. Html::closeElement( 'tr' ) . Html::closeElement( 'thead' )
				. Html::openElement( 'tbody' )
			);
			$deleteLabel = $this->msg( 'kzchatbot-banned-words-op-delete' )->text();
			$editLabel = $this->msg( 'kzchatbot-banned-words-op-edit' )->text();
			for ( $i = 0; $i < count( $bannedWords ); $i++ ) {
				$word = $bannedWords[$i];
				$deleteUrl = $output->getTitle()->getLocalURL( [ 'delete' => $word->getId() ] );
				$editUrl = $output->getTitle()->getLocalURL( [ 'edit' => $word->getId() ] );
				$output->addHTML(
					Html::openElement( 'tr' )
					. Html::element( 'td', [], $word->getPattern() )
					. Html::element( 'td', [], $word->getDescription() )
					. Html::element( 'td', [], $word->getReplyMessage() )
					. Html::rawElement( 'td', [],
						Html::element( 'a', [ 'href' => $editUrl ], $editLabel ) . ' | ' .
						Html::element( 'a', [ 'href' => $deleteUrl ], $deleteLabel )
					)
					. Html::closeElement( 'tr' )
				);
			}
			$output->addHTML(
				Html::closeElement( 'tbody' ) . Html::closeElement( 'table' )
			);
		} else {
			// No banned words. Provide status message instead of table.
			$output->addHtml(
				Html::element(
					'p',
					[ 'class' => 'kzc-banned-words-empty' ],
					$this->msg( 'kzchatbot-banned-words-status-empty' )->text()
				)
			);
		}

		// Build form.
		$output->setPageTitle( $this->msg( 'kzchatbot-banned-words-title' ) );
		$legendMsg = $editId ? 'kzchatbot-banned-words-form-edit-word' : 'kzchatbot-banned-words-form-new-word';

		$htmlForm = HTMLForm::factory( 'ooui', $this->getBannedWordForm( $editId ), $this->getContext() );
		$htmlForm->setId( 'KZChatbotBannedWordForm' )
			->setFormIdentifier( 'KZChatbotBannedWordForm' )
			->setSubmitName( "kzcSubmit" )
			->setSubmitTextMsg( 'kzchatbot-banned-word-submit' )
			->setSubmitCallback( [ $this, 'handleBannedWordSave' ] )
			->setWrapperLegendMsg( $legendMsg );

		if ( $editId ) {
			$htmlForm->setCancelTarget( $this->getPageTitle() )->showCancel();
		}

		$htmlForm->show();
	}

	/**
	 * Define new banned word form structure
	 * @param int|null $editId
	 * @return array
	 */
	private function getBannedWordForm( ?int $editId = null ): array {
		$form = [
			'kzcNewBannedWord' => [
				'type' => 'text',
				'label-message' => 'kzchatbot-banned-words-label-banned-word',
				'help-message' => 'kzchatbot-banned-words-help-banned-word',
				'required' => true
			],
			'kzcBannedWordDescription' => [
				'type' => 'text',
				'label-message' => 'kzchatbot-banned-words-label-banned-word-desc',
				'help-message' => 'kzchatbot-banned-words-help-banned-word-desc'
			],
			'kzcBannedWordReplyMessage' => [
				'type' => 'text',
				'label-message' => 'kzchatbot-banned-words-label-reply-message',
				'help-message' => 'kzchatbot-banned-words-help-reply-message'
			],
			'kzcBannedWordID' => [
				'type' => 'hidden',
				'default' => $editId
			]
		];

		if ( $editId ) {
			$word = new BannedWord( $editId );
			$form['kzcNewBannedWord']['default'] = $word->getPattern();
			$form['kzcBannedWordDescription']['default'] = $word->getDescription();
			$form['kzcBannedWordReplyMessage']['default'] = $word->getReplyMessage();
		}

		return $form;
	}

	/**
	 * Handle new banned word form submission
	 * @param array $postData Form submission data
	 * @return string|bool Return true on success, error message on failure
	 */
	public function handleBannedWordSave( $postData ) {
		$pattern = $postData['kzcNewBannedWord'];
		if ( empty( $pattern ) ) {
			return 'kzchatbot-banned-words-error-empty-string';
		}
		if ( $pattern[0] === '/' && !self::validateRegexPattern( $pattern ) ) {
			return 'kzchatbot-banned-words-error-invalid-regex';
		}

		$newWordDesc = $postData['kzcBannedWordDescription'];
		$replyMessage = $postData['kzcBannedWordReplyMessage'];
		$editId = $this->getRequest()->getIntOrNull( 'kzcBannedWordID' );

		$word = new BannedWord( $editId, $pattern, $newWordDesc, $replyMessage );

		// Save word/pattern.
		$result = $word->save();

		if ( $result ) {
			// Set session data for the success message
			$this->getRequest()->getSession()->set( 'kzBannedWordSaved', $pattern );
			// Return to form.
			$url = $this->getPageTitle()->getFullUrlForRedirect();
			$this->getOutput()->redirect( $url );
			return true;
		}

		return false;
	}

	/**
	 * Handle banned word deletion
	 * @param int $id ID of the word to be deleted
	 * @return string|bool Return true on success, error message on failure
	 * @throws \MWException
	 */
	public function handleBannedWordDelete( $id ) {
		$doomedWord = new BannedWord( $id );
		if ( !$doomedWord->exists() ) {
			throw new \MWException( "No banned word with id $id  was found" );
		}

		$result = $doomedWord->delete();
		if ( !$result ) {
			// @todo i18n
			return 'Failed to delete banned word with id ' . $id;
		}

		// Set session data for the success message
		$this->getRequest()->getSession()->set( 'kzBannedWordDeleted', $doomedWord->getPattern() );

		// Return to form.
		$url = $this->getPageTitle()->getFullUrlForRedirect();
		$this->getOutput()->redirect( $url );
		return true;
	}

	/**
	 * Validate a regular expression.
	 * In our case, regular expressions should not be empty or contain any modifiers.
	 *
	 * @param string $pattern
	 * @return bool
	 */
	private static function validateRegexPattern( $pattern ) {
		// Check if the pattern is a non-empty string
		if ( !is_string( $pattern ) || empty( $pattern ) ) {
			return false;
		}

		// Check if the pattern starts and ends with delimiters
		if ( $pattern[0] !== $pattern[strlen( $pattern ) - 1] ) {
			return false;
		}

		// Get the delimiter
		$delimiter = $pattern[0];

		// Split the pattern into parts
		$parts = explode( $delimiter, $pattern );

		// Check if there are exactly 3 parts (empty start, pattern, modifiers)
		if ( count( $parts ) !== 3 ) {
			return false;
		}

		// Do not allow an empty pattern
		if ( $parts[1] === '' ) {
			return false;
		}

		// Check if there are any modifiers
		if ( !empty( $parts[2] ) ) {
			return false;
		}

		// Try to compile the pattern
		try {
			preg_match( $pattern, '' );
			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}

}
