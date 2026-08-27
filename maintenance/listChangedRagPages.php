<?php

namespace MediaWiki\Extension\KZChatbot\Maintenance;

use MediaWiki\Extension\ChatbotRagContent\ChatbotRagContent;
use MediaWiki\Maintenance\Maintenance;
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
		// Validated before any work, so a bad value fails on its own rather than
		// as an array_chunk() ValueError after the window has already printed.
		$batchSize = $this->getBatchSize();
		if ( $batchSize < 1 ) {
			// getBatchSize() has already cast, so report what was actually typed.
			$raw = $this->getOption( 'batch-size', (string)$batchSize );
			$this->fatalError( "Refusing to run: --batch-size must be a positive integer, got '$raw'" );
		}

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

		// Pre-warm the caches the relevance checks below read. This does not
		// batch away the exclude_from_rag lookups: PageProps caches only rows it
		// finds, so a page without the property is re-queried by every
		// isRelevantTitle() call. The saving comes from the LinkBatch inside
		// PageProps::getGoodIDs(), which warms LinkCache for the existence and
		// redirect checks.
		//
		// Measure that against *no* pre-warm, never across --batch-size: the
		// relevance loop costs the same at every batch size, so a --batch-size
		// sweep only prices the pre-warm's own queries. Total selects over 6,350
		// candidates -- 16,606 with no pre-warm, 10,280 here, and 22,958 at
		// --batch-size=1, which is worse than not pre-warming at all.
		//
		// The saving is gone above LinkCache::MAX_SIZE (10,000): a sequential
		// scan over a larger working set thrashes the LRU, and 10,218 candidates
		// cost 26,120 with against 26,107 without. Left unconditional because the
		// windows this is built for are far smaller -- prod's default -2m window
		// was 670 pages -- but a wide re-ingest is where it stops paying.
		foreach ( array_chunk( $titles, $batchSize ) as $chunk ) {
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
