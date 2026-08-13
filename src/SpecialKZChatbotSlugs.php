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
		parent::__construct( 'KZChatbotSlugs', 'kzchatbot-edit-settings' );
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription() {
		return $this->msg( 'kzchatbot-slugs-title' )->text();
	}

	/**
	 * Special page: Text slugs in the Kol-Zchut chatbot.
	 * @param string|null $subPage Parameters passed to the page
	 */
	public function execute( $subPage ) {
		parent::execute( $subPage );
		$output = $this->getOutput();
		$request = $this->getRequest();

		$slugs = [];

		foreach ( Slugs::getDefaultSlugs() as $name => $value ) {
			$slugs[$name] = [
				'value' => $value,
				'changed' => false
			];
		}
		foreach ( Slugs::getSlugsFromDB() as $name => $value ) {
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

		// Obsolete override rows are listed so they can be deleted, but there is
		// nothing to edit: the key is gone from the defaults, so Slugs::saveSlug()
		// would reject any change anyway. The table omits their edit link, but
		// both the form URL and a hand-made POST stay reachable, so the refusal
		// belongs here rather than in the markup.
		$editSlug = $this->getRequestedEditSlug();
		if ( $editSlug !== null && !Slugs::isValidSlugName( $editSlug ) ) {
			$this->getRequest()->getSession()->set(
				'kzSlugError',
				$this->msg( 'kzchatbot-slugs-error-obsolete', $editSlug )->text()
			);
			$this->getOutput()->redirect( $this->getPageTitle()->getFullUrlForRedirect() );
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
					// A submission that got as far as handleSlugSave() has stored its
					// outcome in the session and set a redirect. Carrying on would run
					// the status block below, which reads that message and clears it —
					// rendering it into a response the browser discards, and leaving
					// nothing for the redirected page to show. Validation failures set
					// no redirect and still fall through.
					if ( $output->getRedirect() !== '' ) {
						return;
					}
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
		$slugStatus = $session->get( 'kzSlugStatus' );
		$deletedSlug = $session->get( 'kzSlugDeleted' );
		$slugError = $session->get( 'kzSlugError' );
		if ( !empty( $slugStatus ) || !empty( $deletedSlug ) || !empty( $slugError ) ) {
			$session->remove( 'kzSlugStatus' );
			$session->remove( 'kzSlugDeleted' );
			$session->remove( 'kzSlugError' );
			$output->addModuleStyles( 'mediawiki.notification.convertmessagebox.styles' );
			if ( !empty( $slugError ) ) {
				$output->addHTML(
					Html::rawElement(
						'div',
						[
							'class' => 'mw-preferences-messagebox mw-notify-error errorbox',
							'id' => 'mw-preferences-error',
							'data-mw-autohide' => 'false',
						],
						Html::element( 'p', [], $slugError )
					)
				);
			} else {
				// $slugStatus is ['message-key', ...params] as set by Status::newGood( [...] )
				$msgArgs = !empty( $slugStatus )
					? $slugStatus
					: [ 'kzchatbot-slugs-status-delete-success', $deletedSlug ];
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
							$this->msg( ...$msgArgs )->text()
						)
					)
				);
			}
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
			$formattedSlugs = Slugs::getFormattedSlugs();
			$output->addModuleStyles( 'jquery.tablesorter.styles' );
			$output->addModules( 'jquery.tablesorter' );
			$output->addHTML(
				Html::openElement(
					'table',
					[ 'class' => 'mw-datatable sortable kzc-slugs-table', 'id' => 'kzchatbot-slugs-table' ]
				)
				. Html::openElement( 'thead' ) . Html::openElement( 'tr' )
				. Html::element( 'th', [], $this->msg( 'kzchatbot-slugs-label-slug' )->text() )
				. Html::element( 'th', [], $this->msg( 'kzchatbot-slugs-label-text' )->text() )
				. Html::element( 'th', [], $this->msg( 'kzchatbot-slugs-label-formatting' )->text() )
				. Html::element( 'th' )
				. Html::element( 'th' )
				. Html::closeElement( 'tr' ) . Html::closeElement( 'thead' )
				. Html::openElement( 'tbody' )
			);
			$editLabel = $this->msg( 'kzchatbot-slugs-op-edit' )->text();
			$deleteLabel = $this->msg( 'kzchatbot-slugs-op-delete' )->text();
			$formattingLabel = $this->msg( 'kzchatbot-slugs-formatting-supported' )->text();
			$obsoleteLabel = $this->msg( 'kzchatbot-slugs-obsolete' )->text();
			$obsoleteTooltip = $this->msg( 'kzchatbot-slugs-obsolete-tooltip' )->text();
			foreach ( $slugs as $slug => $attribs ) {
				// A row the chatbot no longer has any use for: it exists only because
				// an override was saved under a slug that has since been renamed or
				// retired. Deleting it is the only thing left to do with it.
				$isObsolete = !Slugs::isValidSlugName( $slug );
				$editUrl = $output->getTitle()->getLocalURL( [ 'edit' => $slug ] );
				$deleteUrl = $output->getTitle()->getLocalURL( [ 'delete' => $slug ] );
				if ( $isObsolete ) {
					$cssClass = 'obsolete-value';
				} else {
					$cssClass = $attribs['changed'] ? '' : 'default-value';
				}
				$output->addHTML(
					Html::openElement( 'tr', [ 'class' => $cssClass ] )
					. Html::rawElement( 'td', [],
						Html::element( 'span', [], $slug )
						. ( $isObsolete
							? ' ' . Html::element(
								'span',
								[ 'class' => 'kzc-obsolete-badge', 'title' => $obsoleteTooltip ],
								$obsoleteLabel
							)
							: '' )
					)
					. Html::element( 'td', [], $attribs['value'] )
					. Html::element( 'td', [], in_array( $slug, $formattedSlugs ) ? $formattingLabel : '' )
					. Html::rawElement( 'td', [],
						$isObsolete ? '' : Html::element( 'a', [ 'href' => $editUrl ], $editLabel )
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
	 * The slug an edit request is targeting, whether it arrived as a query
	 * parameter (opening the form) or as a form submission (saving it).
	 *
	 * @return string|null Null when the request is not an edit
	 */
	private function getRequestedEditSlug(): ?string {
		$request = $this->getRequest();
		if ( $request->wasPosted() && $request->getVal( 'wpkzcAction' ) === 'edit' ) {
			return $request->getVal( 'wpkzcSlug' );
		}
		$slug = $request->getQueryValues()['edit'] ?? null;
		return empty( $slug ) ? null : $slug;
	}

	/**
	 * Define new banned word form structure
	 * @param array $editValues
	 * @return array
	 */
	private function getSlugForm( $editValues = [] ) {
		$isFormatted = !empty( $editValues['slug'] ) &&
			in_array( $editValues['slug'], Slugs::getFormattedSlugs() );
		$form = [
			'kzcSlug' => [
				'type' => 'text',
				'cssclass' => 'ksl-new-slug',
				'label-message' => 'kzchatbot-slugs-label-add-slug',
				'readonly' => true,
				'required' => true,
				'validation-callback' => [ Slugs::class, 'isValidSlugName' ]
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
		if ( $isFormatted ) {
			$form['kzcText']['help-message'] = 'kzchatbot-slugs-formatting-help';
		}
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

		$status = Slugs::saveSlug( $slug, $text );

		if ( $status->isOK() ) {
			// Store the message data (key + params) from the Status value for display after redirect
			$this->getRequest()->getSession()->set( 'kzSlugStatus', $status->getValue() );
		} else {
			$this->getRequest()->getSession()->set( 'kzSlugError', $status->getMessage()->plain() );
		}

		// Return to form.
		$url = $this->getPageTitle()->getFullUrlForRedirect();
		$this->getOutput()->redirect( $url );
		return $status->isOK();
	}

	/**
	 * Handle new banned word deletion
	 * @param string $slug Slug to be deleted
	 * @return bool
	 */
	public function handleSlugDelete( $slug ): bool {
		// Delete word/pattern.
		$status = Slugs::deleteSlug( $slug );

		if ( $status->isOK() ) {
			// Set session data for the success message
			$this->getRequest()->getSession()->set( 'kzSlugDeleted', $slug );
		} else {
			$this->getRequest()->getSession()->set( 'kzSlugError', $status->getMessage()->plain() );
		}

		// Return to form.
		$url = $this->getPageTitle()->getFullUrlForRedirect();
		$this->getOutput()->redirect( $url );
		return $status->isOK();
	}

}
