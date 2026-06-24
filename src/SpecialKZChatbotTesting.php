<?php

namespace MediaWiki\Extension\KZChatbot;

use Html;
use SpecialPage;

/**
 * Embeds the RAG backend's own batch-runner interface in an iframe.
 *
 * The interface itself is served (and its API calls proxied + permission-checked)
 * by the REST handlers under /kzchatbot/v0/ragui and /kzchatbot/v0/ragproxy. This
 * page only gates access and hosts the frame.
 */
class SpecialKZChatbotTesting extends SpecialPage {

	public function __construct() {
		parent::__construct( 'KZChatbotTesting', 'kzchatbot-testing' );
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		parent::execute( $subPage );

		$out = $this->getOutput();
		$out->enableClientCache( false );
		$out->addModuleStyles( 'ext.KZChatbot.ragui.styles' );

		// Navigation link to RAG Settings
		$ragSettingsTitle = SpecialPage::getTitleFor( 'KZChatbotRagSettings' );
		$out->addSubtitle( $this->getLinkRenderer()->makeLink(
			$ragSettingsTitle,
			$this->msg( 'kzchatbot-testing-nav-to-rag-settings' )->text()
		) );

		$src = $this->getConfig()->get( 'RestPath' ) . '/kzchatbot/v0/ragui/batch';
		$out->addHTML( Html::element( 'iframe', [
			'src' => $src,
			'class' => 'kzchatbot-rag-iframe',
			'title' => $this->msg( 'kzchatbot-testing-title' )->text(),
		] ) );
	}

	/** @inheritDoc */
	public function getDescription(): string {
		return $this->msg( 'kzchatbot-testing-title' )->text();
	}
}
