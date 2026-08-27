<?php

namespace MediaWiki\Extension\KZChatbot\Maintenance;

use Maintenance;
use MediaWiki\Extension\ChatbotRagContent\ChatbotRagContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * List the page IDs edited within a time window that are relevant to the
 * chatbot's RAG index, i.e. ChatbotRagContent::isRelevantTitle() returns true.
 *
 * The window is narrowed in SQL (rev_timestamp is indexed); relevance is then
 * decided in PHP, because four of isRelevantTitle()'s checks cannot be
 * expressed against the page/revision tables: the page's language, the
 * exclude_from_rag page property, the configured title allowlist, and the
 * ArticleType blocklist.
 *
 * IDs go to stdout and everything else to stderr, so the output pipes straight
 * into a re-ingest run.
 */
class ListChangedRagPages extends Maintenance {

	/** Shorthand duration suffixes accepted by --from/--to, mapped to strtotime units. */
	private const UNITS = [
		'min' => 'minutes',
		's' => 'seconds',
		'h' => 'hours',
		'd' => 'days',
		'w' => 'weeks',
		'm' => 'months',
		'y' => 'years',
	];

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'List page IDs changed within a time window that are relevant to the '
			. 'chatbot RAG index. IDs are written to stdout, one per line.'
		);
		$this->addOption(
			'from',
			'Start of the window. Relative shorthand (-30d, -2m, -1y, -6h, -90min), '
			. 'anything strtotime() understands ("-2 months", "2026-06-01"), or a '
			. '14-digit MediaWiki timestamp. Default: -2m.',
			false, true
		);
		$this->addOption(
			'to',
			'End of the window, same formats as --from. Default: now.',
			false, true
		);
		$this->addOption(
			'titles',
			'Append a tab and the prefixed page title to each line (TSV).',
			false, false
		);
		$this->addOption(
			'stats-only',
			'Report the candidate and relevant counts, but list no IDs.',
			false, false
		);
		$this->setBatchSize( 500 );
		$this->requireExtension( 'KZChatbot' );
		$this->requireExtension( 'ChatbotRagContent' );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$dbr = $services->getConnectionProvider()->getReplicaDatabase();

		$fromUnix = $this->parseTime( $this->getOption( 'from', '-2m' ), 'from' );
		$toUnix = $this->parseTime( $this->getOption( 'to', 'now' ), 'to' );
		if ( $fromUnix >= $toUnix ) {
			$this->fatalError(
				'Refusing to run: --from (' . wfTimestamp( TS_ISO_8601, $fromUnix )
				. ') is not before --to (' . wfTimestamp( TS_ISO_8601, $toUnix ) . ').'
			);
		}

		$this->error(
			'Window: ' . wfTimestamp( TS_ISO_8601, $fromUnix )
			. ' .. ' . wfTimestamp( TS_ISO_8601, $toUnix )
		);

		// Pull namespace and title in the same pass, so deciding relevance below
		// costs no per-page Title::newFromID() lookup.
		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'page_id', 'page_namespace', 'page_title' ] )
			->distinct()
			->from( 'revision' )
			->join( 'page', null, 'page_id = rev_page' )
			->where( [
				$dbr->expr( 'rev_timestamp', '>=', $dbr->timestamp( $fromUnix ) ),
				$dbr->expr( 'rev_timestamp', '<=', $dbr->timestamp( $toUnix ) ),
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$titles = [];
		foreach ( $rows as $row ) {
			$titles[(int)$row->page_id] =
				Title::makeTitle( (int)$row->page_namespace, $row->page_title );
		}
		$this->error( 'Changed pages in window: ' . count( $titles ) );

		if ( !$titles ) {
			$this->error( 'Relevant: 0' );
			return;
		}

		$pageProps = $services->getPageProps();
		$config = $services->getMainConfig();
		$contentLanguage = $services->getContentLanguage();

		// PageProps caches per title, and getProperties() takes a batch. Warming
		// it up front turns one query per candidate into one query per batch.
		foreach ( array_chunk( $titles, $this->getBatchSize() ) as $chunk ) {
			$pageProps->getProperties( $chunk, 'exclude_from_rag' );
		}

		$showTitles = $this->hasOption( 'titles' );
		$statsOnly = $this->hasOption( 'stats-only' );
		$relevant = 0;

		foreach ( $titles as $pageId => $title ) {
			if ( !ChatbotRagContent::isRelevantTitle(
				$title, $pageProps, $config, $contentLanguage
			) ) {
				continue;
			}
			$relevant++;
			if ( $statsOnly ) {
				continue;
			}
			$this->output(
				$showTitles ? $pageId . "\t" . $title->getPrefixedText() . "\n" : $pageId . "\n"
			);
		}

		$this->error( "Relevant: $relevant" );
	}

	/**
	 * Resolve a --from/--to value to a Unix timestamp.
	 *
	 * Accepts relative shorthand (-30d), a 14-digit MediaWiki timestamp, or
	 * anything strtotime() parses. Note that "m" means months and "min" minutes,
	 * matching how these windows are usually spoken about here.
	 *
	 * @param string $value
	 * @param string $optionName Used only for the error message.
	 * @return int
	 */
	private function parseTime( string $value, string $optionName ): int {
		$value = trim( $value );

		if ( preg_match( '/^\d{14}$/', $value ) ) {
			$unix = wfTimestampOrNull( TS_UNIX, $value );
			if ( $unix !== null ) {
				return (int)$unix;
			}
		}

		// -30d / +2w / -90min — strtotime rejects the bare suffixes, so expand them.
		if ( preg_match( '/^([+-]?\d+)\s*([a-z]+)$/i', $value, $m )
			&& isset( self::UNITS[strtolower( $m[2] )] )
		) {
			$value = $m[1] . ' ' . self::UNITS[strtolower( $m[2] )];
		}

		$unix = strtotime( $value );
		if ( $unix === false ) {
			$this->fatalError( "Could not parse --$optionName value: '$value'" );
		}
		return $unix;
	}
}

$maintClass = ListChangedRagPages::class;
require_once RUN_MAINTENANCE_IF_MAIN;
