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
		return $this->msg( 'kzchatbot-slugs-title' )->text();
	}

	/**
	 * Special page: Text slugs in the Kol-Zchut chatbot.
	 * @param string|null $par Parameters passed to the page
	 */
	public function execute( $par ) {
		parent::execute( $par );
		$output = $this->getOutput();
		$request = $this->getRequest();

		$slugs = [];

		foreach ( KZChatbot::getDefaultSlugs() as $name => $value ) {
			$slugs[$name] = [
				'value' => $value,
				'changed' => false
			];
		}
		foreach ( KZChatbot::getSlugsFromDB() as $name => $value ) {
			$slugs[$name] = [
				'value' => $value,
				'changed' => true
			];
		}

		$output->addModules( 'ext.KZChatbot.form' );

		// Delete operation?
		$queryParams = $this->getRequest()->getQueryValues();
		if ( !empty( $queryParams['delete'] ) ) {
			$this->handleSlugDelete( $queryParams['delete'] );
			return;
		}

		// Edit operation?
		if ( !empty( $queryParams['edit'] ) || $request->getVal( 'wpkzcAction' ) === 'edit' ) {
			if ( $request->wasPosted() ) {
				$currentValues = [
					'slug' => $request->getVal( 'wpkzcSlug' ),
					'text' => $request->getVal( 'wpkzcText' )
				];
			} else {
				$currentValues = [
					'slug' => $queryParams['edit'],
					'text' => $slugs[$queryParams['edit']]['value'] ?? null,
				];
			}

			$htmlForm = HTMLForm::factory( 'ooui', $this->getSlugForm( $currentValues ), $this->getContext() );
			$htmlForm->setId( 'KZChatbotSlugForm' )
				->setFormIdentifier( 'KZChatbotSlugForm' )
				->setSubmitName( "kzcSubmit" )
				->setSubmitTextMsg( 'kzchatbot-slug-update' )
				->setSubmitCallback( [ $this, 'handleSlugSave' ] );

			if ( $request->wasPosted() ) {
				if ( $this->getRequest()->getVal( 'wpkzcAction' ) === 'edit' ) {
					$htmlForm->prepareForm()
						->trySubmit();
				}
			} elseif ( !empty( $queryParams['edit'] ) ) {
				if ( isset( $slugs[$queryParams['edit']] ) ) {
					$htmlForm->show();
					return;
				}
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
			foreach ( $slugs as $slug => $attribs ) {
				$editUrl = $output->getTitle()->getLocalURL( [ 'edit' => $slug ] );
				$deleteUrl = $output->getTitle()->getLocalURL( [ 'delete' => $slug ] );
				$cssClass = $attribs['changed'] ? '' : 'default-value';
				$output->addHTML(
					Html::openElement( 'tr', [ 'class' => $cssClass ] )
					. Html::element( 'td', [], $slug )
					. Html::element( 'td', [], $attribs['value'] )
					. Html::rawElement( 'td', [],
						Html::element( 'a', [ 'href' => $editUrl ], $editLabel )
					)
					. Html::rawElement( 'td', [],
						$attribs['changed'] ? Html::element( 'a', [ 'href' => $deleteUrl ], $deleteLabel ) : ''
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
				'readonly' => true,
				'required' => true,
				'validation-callback' => [ KZChatbot::class, 'isValidSlugName' ]
			],
			'kzcText' => [
				'type' => 'textarea',
				'rows' => 3,
				'cssclass' => 'ksl-new-slug-text',
				'label-message' => 'kzchatbot-slugs-label-add-slug-text',
				'required' => true,
			],
			'kzcAction' => [
				'type' => 'hidden',
				'default' => 'edit'
			]
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
	 * @return bool
	 */
	public function handleSlugSave( $postData ): bool {
		$slug = $postData['kzcSlug'];
		$text = $postData['kzcText'];

		// @TODO handle exceptions
		try {
			$result = KZChatbot::saveSlug( $slug, $text );
		} catch ( \Exception $e ) {
			$result = false;
		}

		if ( $result ) {
			// Set session data for the success message
			$this->getRequest()->getSession()->set( 'kzSlugSaved', $slug );
		}

		// Return to form.
		$url = $this->getPageTitle()->getFullUrlForRedirect();
		$this->getOutput()->redirect( $url );
		return $result;
	}

	/**
	 * Handle new banned word deletion
	 * @param string $slug Slug to be deleted
	 * @return bool
	 */
	public function handleSlugDelete( $slug ): bool {
		// Delete word/pattern.
		$result = KZChatbot::deleteSlug( $slug );

		if ( $result ) {
			// Set session data for the success message
			$this->getRequest()->getSession()->set( 'kzSlugDeleted', $slug );
		}

		// Return to form.
		$url = $this->getPageTitle()->getFullUrlForRedirect();
		$this->getOutput()->redirect( $url );
		return $result;
	}

}
