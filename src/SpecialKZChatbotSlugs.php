<?php

namespace MediaWiki\Extension\KZChatbot;

use Html;
use HTMLForm;
use SpecialPage;

/**
 * Management interface for text slugs in the Kol-Zchut chatbot.
 *
 * @ingroup SpecialPage
 */
class SpecialKZChatbotSlugs extends SpecialPage {

	/**
	 * @inheritDoc
	 */
	public function __construct() {
		parent::__construct( 'KZChatbotSlugs', 'manage-kolzchut-chatbot' );
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription() {
		return $this->msg( 'kolzchut-chatbot-desc' )->text();
	}

	/**
	 * Special page: Text slugs in the Kol-Zchut chatbot.
	 * @param string|null $par Parameters passed to the page
	 */
	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();
		$output = $this->getOutput();
		$output->addModules( 'ext.KZChatbot.form' );

		// Delete operation?
		$queryParams = $this->getRequest()->getQueryValues();
		if ( !empty( $queryParams['delete'] ) ) {
			$this->handleSlugDelete( $queryParams['delete'] );
			return;
		}

		// Edit operation?
		if ( !empty( $queryParams['edit'] ) ) {
			$slugs = KZChatbot::getSlugs();
			if ( isset( $slugs[ $queryParams['edit'] ] ) ) {
				$output->setPageTitle( $this->msg( 'kzchatbot-slugs-title' ) );
				$currentValues = [
					'slug' => $queryParams['edit'],
					'text' => $slugs[ $queryParams['edit'] ],
				];
				$htmlForm = HTMLForm::factory( 'ooui', $this->getSlugForm( $currentValues ), $this->getContext() );
				$htmlForm->setId( 'KZChatbotSlugForm' )
					->setFormIdentifier( 'KZChatbotSlugForm' )
					->setSubmitName( "kzcSubmit" )
					->setSubmitTextMsg( 'kzchatbot-slug-update' )
					->setSubmitCallback( [ $this, 'handleSlugSave' ] )
					->show();
				return;
			}
		}

		// Successful operation? If so, show status message.
		$session = $this->getRequest()->getSession();
		$savedSlug = $session->get( 'kzSlugSaved' );
		$deletedSlug = $session->get( 'kzSlugDeleted' );
		if ( !empty( $savedSlug ) || !empty( $deletedSlug ) ) {
			// Remove session data for the success message
			$session->remove( !empty( $savedSlug ) ? 'kzSlugSaved' : 'kzSlugDeleted' );
			$output->addModuleStyles( 'mediawiki.notification.convertmessagebox.styles' );
			$message = !empty( $savedSlug ) ? 'kzchatbot-slugs-status-save-success'
				: 'kzchatbot-slugs-status-delete-success';
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
						$this->msg( $message, !empty( $savedSlug ) ? $savedSlug : $deletedSlug )->text()
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

		// Build table of existing slugs.
		$slugs = KZChatbot::getSlugs();
		if ( !empty( $slugs ) ) {
			$output->addModuleStyles( 'jquery.tablesorter.styles' );
			$output->addModules( 'jquery.tablesorter' );
			$output->addHTML(
				Html::openElement(
					'table',
					[ 'class' => 'mw-datatable sortable', 'id' => 'kzchatbot-slugs-table' ]
				)
				. Html::openElement( 'thead' ) . Html::openElement( 'tr' )
				. Html::element( 'th', [], $this->msg( 'kzchatbot-slugs-label-slug' )->text() )
				. Html::element( 'th', [], $this->msg( 'kzchatbot-slugs-label-text' )->text() )
				. Html::element( 'th' )
				. Html::element( 'th' )
				. Html::closeElement( 'tr' ) . Html::closeElement( 'thead' )
				. Html::openElement( 'tbody' )
			);
			$editLabel = $this->msg( 'kzchatbot-slugs-op-edit' )->text();
			$deleteLabel = $this->msg( 'kzchatbot-slugs-op-delete' )->text();
			foreach ( $slugs as $slug => $text ) {
				$editUrl = $output->getTitle()->getLocalURL( [ 'edit' => $slug ] );
				$deleteUrl = $output->getTitle()->getLocalURL( [ 'delete' => $slug ] );
				$output->addHTML(
					Html::openElement( 'tr' )
					. Html::element( 'td', [], $slug )
					. Html::element( 'td', [], $text )
					. Html::rawElement( 'td', [],
						Html::element( 'a', [ 'href' => $editUrl ], $editLabel )
					)
					. Html::rawElement( 'td', [],
						Html::element( 'a', [ 'href' => $deleteUrl ], $deleteLabel )
					)
					. Html::closeElement( 'tr' )
				);
			}
			$output->addHTML(
				Html::closeElement( 'tbody' ) . Html::closeElement( 'table' )
			);
		} else {
			// No slugs defined. Provide status message instead of table.
			$output->addHtml(
				Html::element(
					'p',
					[ 'class' => 'kzc-slugs-empty' ],
					$this->msg( 'kzchatbot-slugs-status-empty' )->text()
				)
			);
		}

		// Build form.
		$output->setPageTitle( $this->msg( 'kzchatbot-slugs-title' ) );
		$htmlForm = HTMLForm::factory( 'ooui', $this->getSlugForm(), $this->getContext() );
		$htmlForm->setId( 'KZChatbotSlugForm' )
			->setFormIdentifier( 'KZChatbotSlugForm' )
			->setSubmitName( "kzcSubmit" )
			->setSubmitTextMsg( 'kzchatbot-slug-submit' )
			->setSubmitCallback( [ $this, 'handleSlugSave' ] )
			->show();
	}

	/**
	 * Define new banned word form structure
	 * @param array $editValues
	 * @return array
	 */
	private function getSlugForm( $editValues = [] ) {
		$form = [
			'kzcSlug' => [
				'type' => 'text',
				'cssclass' => 'ksl-new-slug',
				'label-message' => 'kzchatbot-slugs-label-add-slug',
				'help-message' => 'kzchatbot-slugs-help-add-slug',
				'required' => true,
			],
			'kzcText' => [
				'type' => 'textarea',
				'rows' => 3,
				'cssclass' => 'ksl-new-slug-text',
				'label-message' => 'kzchatbot-slugs-label-add-slug-text',
				'required' => true,
			],
		];
		if ( !empty( $editValues ) ) {
			$form['kzcSlug']['default'] = $editValues['slug'];
			$form['kzcText']['default'] = $editValues['text'];
		}
		return $form;
	}

	/**
	 * Handle new slug form submission
	 * @param array $postData Form submission data
	 * @return string|bool Return true on success, error message on failure
	 */
	public function handleSlugSave( $postData ) {
		// Sanitize user input.
		$slug = preg_replace( "/[^a-zA-Z_א-ת]+/", '', $postData['kzcSlug'] );
		if ( empty( $slug ) ) {
			return 'kzchatbot-slugs-error-alphanumeric-only';
		}
		$text = preg_replace( "/[^a-zA-Z_א-ת\\s\\<\\>\\\"\\/\\,\\`\\'\\%\\*\\(\\)\\!\\.]+/", '', $postData['kzcText'] );

		//@TODO: Check for existing slug by same name?
		// Save slug
		KZChatbot::saveSlug( $slug, $text );

		// Set session data for the success message
		$this->getRequest()->getSession()->set( 'kzSlugSaved', $slug );

		// Return to form.
		$url = $this->getPageTitle()->getFullUrlForRedirect();
		$this->getOutput()->redirect( $url );
		return true;
	}

	/**
	 * Handle new banned word deletion
	 * @param string $slug Slug to be deleted
	 * @return string|bool Return true on success, error message on failure
	 */
	public function handleSlugDelete( $slug ) {
		// Sanitize user input.
		$slug = preg_replace( "/[^a-zA-Z_א-ת]+/", '', $slug );

		// Delete word/pattern.
		KZChatbot::deleteSlug( $slug );

		// Set session data for the success message
		$this->getRequest()->getSession()->set( 'kzSlugDeleted', $slug );

		// Return to form.
		$url = $this->getPageTitle()->getFullUrlForRedirect();
		$this->getOutput()->redirect( $url );
		return true;
	}

}
