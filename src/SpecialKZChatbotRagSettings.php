<?php

namespace MediaWiki\Extension\KZChatbot;

use MediaWiki\Extension\KZChatbot\Rest\RagUiHandler;
use MediaWiki\Message\Message;
use SpecialPage;

/**
 * Embeds the RAG backend's own admin/config interface in an iframe.
 *
 * The interface is served (and its API calls proxied + permission-checked) by the
 * REST handlers under /kzchatbot/v0/ragui and /kzchatbot/v0/ragproxy. This page
 * gates access, hosts the sandboxed frame and relays its endpoint calls
 * (ext.KZChatbot.ragui). Edit-level and destructive controls are hidden by the
 * UI handler for users who lack the corresponding right.
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

		$out->addModules( 'ext.KZChatbot.ragui' );
		$out->addHTML( RagUiHandler::buildIframe(
			'admin',
			$this->getConfig()->get( 'RestPath' ),
			$this->msg( 'kzchatbot-rag-settings' )->text()
		) );
	}

	/** @inheritDoc */
	public function getDescription(): Message {
		return $this->msg( 'kzchatbot-rag-settings' );
	}
}
