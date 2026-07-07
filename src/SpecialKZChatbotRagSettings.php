<?php

namespace MediaWiki\Extension\KZChatbot;

use Html;
use SpecialPage;

/**
 * Embeds the RAG backend's own admin/config interface in an iframe.
 *
 * The interface is served (and its API calls proxied + permission-checked) by the
 * REST handlers under /kzchatbot/v0/ragui and /kzchatbot/v0/ragproxy. This page
 * only gates access and hosts the frame; edit-level and destructive controls are
 * hidden by the UI handler for users who lack the corresponding right.
 */
class SpecialKZChatbotRagSettings extends SpecialPage {

	public function __construct() {
		// Viewing requires view-rag-settings; the chatbot-admin group is granted
		// that right too, so editors can reach the page. Editing/destructive
		// controls are gated separately by their own rights.
		parent::__construct( 'KZChatbotRagSettings', 'kzchatbot-view-rag-settings' );
	}

	/** @inheritDoc */
	public function execute( $par ) {
		parent::execute( $par );

		$out = $this->getOutput();
		$out->enableClientCache( false );
		$out->addModuleStyles( 'ext.KZChatbot.ragui.styles' );

		// Navigation link to the testing interface
		$testingTitle = SpecialPage::getTitleFor( 'KZChatbotTesting' );
		$out->addSubtitle( $this->getLinkRenderer()->makeLink(
			$testingTitle,
			$this->msg( 'kzchatbot-rag-settings-nav-to-testing' )->text()
		) );

		$src = $this->getConfig()->get( 'RestPath' ) . '/kzchatbot/v0/ragui/admin';
		$out->addHTML( Html::element( 'iframe', [
			'src' => $src,
			'class' => 'kzchatbot-rag-iframe',
			'title' => $this->msg( 'kzchatbot-rag-settings' )->text(),
		] ) );
	}

	/** @inheritDoc */
	public function getDescription(): string {
		return $this->msg( 'kzchatbot-rag-settings' )->text();
	}
}
