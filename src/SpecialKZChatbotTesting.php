<?php

namespace MediaWiki\Extension\KZChatbot;

use MediaWiki\Extension\KZChatbot\Rest\RagUiHandler;
use MediaWiki\Message\Message;
use SpecialPage;

/**
 * Embeds the RAG backend's own testing interfaces in an iframe:
 *  - Special:KZChatbotTesting        -> index.html       (single-query tester)
 *  - Special:KZChatbotTesting/batch  -> batch-runner.html (batch runner)
 *
 * The interfaces themselves are served (and their API calls proxied +
 * permission-checked) by the REST handlers under /kzchatbot/v0/ragui and
 * /kzchatbot/v0/ragproxy. This page gates access, hosts the sandboxed frame and
 * relays its endpoint calls (ext.KZChatbot.ragui).
 */
class SpecialKZChatbotTesting extends SpecialPage {

	public function __construct() {
		parent::__construct( 'KZChatbotTesting', 'kzchatbot-testing' );
	}

	/** @inheritDoc */
	public function execute( $subPage ): void {
		parent::execute( $subPage );

		$out = $this->getOutput();
		$out->enableClientCache( false );
		$out->addModuleStyles( 'ext.KZChatbot.ragui.styles' );

		$isBatch = ( $subPage === 'batch' );
		$iface = $isBatch ? 'batch' : 'index';

		$out->addSubtitle( $this->buildNavLinks( $isBatch ) );

		$out->addModules( 'ext.KZChatbot.ragui' );
		$out->addHTML( RagUiHandler::buildIframe(
			$iface,
			$this->getConfig()->get( 'RestPath' ),
			$this->msg( 'kzchatbot-testing-title' )->text()
		) );
	}

	/**
	 * Build the subtitle navigation: a link to the other tester plus a link to
	 * the RAG settings page.
	 *
	 * @param bool $isBatch Whether the batch runner is currently shown
	 * @return string HTML
	 */
	private function buildNavLinks( bool $isBatch ): string {
		$linkRenderer = $this->getLinkRenderer();

		if ( $isBatch ) {
			$otherLink = $linkRenderer->makeLink(
				self::getTitleFor( 'KZChatbotTesting' ),
				$this->msg( 'kzchatbot-testing-nav-to-single' )->text()
			);
		} else {
			$otherLink = $linkRenderer->makeLink(
				self::getTitleFor( 'KZChatbotTesting', 'batch' ),
				$this->msg( 'kzchatbot-testing-nav-to-batch' )->text()
			);
		}

		$ragSettingsLink = $linkRenderer->makeLink(
			self::getTitleFor( 'KZChatbotRagSettings' ),
			$this->msg( 'kzchatbot-testing-nav-to-rag-settings' )->text()
		);

		return $this->getLanguage()->pipeList( [ $otherLink, $ragSettingsLink ] );
	}

	/** @inheritDoc */
	public function getDescription(): Message {
		return $this->msg( 'kzchatbot-testing-title' );
	}
}
