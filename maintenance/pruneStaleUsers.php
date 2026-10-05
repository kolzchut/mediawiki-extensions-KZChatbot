<?php

namespace MediaWiki\Extension\KZChatbot\Maintenance;

use MediaWiki\Extension\KZChatbot\KZChatbot;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Delete kzchatbot_users rows whose kzcbu_last_active is older than the
 * "active users limit" window. Such rows no longer count toward the
 * active-users cap (see KZChatbot::getCurrentActiveUsersCount), so they
 * are dead weight in the table.
 */
class PruneStaleUsers extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Delete kzchatbot_users rows whose last_active is older than the '
			. 'configured active-users window.'
		);
		$this->addOption(
			'dry-run',
			'Report the cutoff date and how many rows would be removed, then exit.',
			false, false
		);
		$this->addOption(
			'cutoff-days',
			'Override the inactivity threshold in days (defaults to the '
			. '"active_users_limit_days" setting).',
			false, true
		);
		$this->setBatchSize( 1000 );
		$this->requireExtension( 'KZChatbot' );
	}

	public function execute() {
		// Validated before any output: an unusable value must not reach the
		// LIMIT below, where it deletes nothing and still reports success.
		$batchSize = $this->getBatchSize();
		if ( $batchSize < 1 ) {
			// getBatchSize() has already cast, so report what was actually typed.
			$raw = $this->getOption( 'batch-size', (string)$batchSize );
			$this->fatalError( "Refusing to run: --batch-size must be a positive integer, got '$raw'" );
		}

		$cutoffDays = $this->resolveCutoffDays();
		if ( $cutoffDays <= 0 ) {
			$this->fatalError(
				"Refusing to run: cutoff-days must be > 0 (got $cutoffDays). "
				. "Check the 'active_users_limit_days' setting or pass --cutoff-days."
			);
		}

		$cutoffUnix = time() - ( $cutoffDays * 24 * 60 * 60 );
		$cutoffTs = wfTimestamp( TS_MW, $cutoffUnix );
		$cutoffHuman = wfTimestamp( TS_ISO_8601, $cutoffUnix );

		$this->output( "Cutoff: $cutoffHuman (last_active older than $cutoffDays days)\n" );

		// COUNT(*) on this table is a large index scan. Only pay for it when the
		// figure is the whole point (dry runs); real runs report progress from
		// the running delete tally instead.
		if ( $this->hasOption( 'dry-run' ) ) {
			$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
			$totalRows = (int)$dbr->selectField(
				'kzchatbot_users', 'COUNT(*)', [], __METHOD__
			);
			$staleRows = (int)$dbr->selectField(
				'kzchatbot_users',
				'COUNT(*)',
				[ 'kzcbu_last_active < ' . $dbr->addQuotes( $cutoffTs ) ],
				__METHOD__
			);
			$this->output( "Stale rows: $staleRows of $totalRows total\n" );
			$this->output( "Dry run — no rows deleted.\n" );
			return;
		}

		$dbw = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$deleted = 0;
		$batch = 0;

		do {
			$uuids = $dbw->selectFieldValues(
				'kzchatbot_users',
				'kzcbu_uuid',
				[ 'kzcbu_last_active < ' . $dbw->addQuotes( $cutoffTs ) ],
				__METHOD__,
				[ 'LIMIT' => $batchSize ]
			);
			if ( !$uuids ) {
				break;
			}
			$dbw->delete(
				'kzchatbot_users',
				[ 'kzcbu_uuid' => $uuids ],
				__METHOD__
			);
			$deleted += count( $uuids );

			// Blocking on replication after every batch dominates wall-clock on
			// large purges. Throttle it: every 10 batches keeps replica lag
			// bounded without the per-batch stall.
			if ( ++$batch % 10 === 0 ) {
				$this->output( "  ...deleted $deleted\n" );
				$lbFactory->waitForReplication();
			}
		} while ( count( $uuids ) === $batchSize );

		$lbFactory->waitForReplication();
		$this->output( "Done. Deleted $deleted stale user row(s).\n" );
	}

	private function resolveCutoffDays(): int {
		$override = $this->getOption( 'cutoff-days' );
		if ( $override !== null ) {
			return (int)$override;
		}
		$settings = KZChatbot::getGeneralSettings();
		return (int)( $settings['active_users_limit_days'] ?? 30 );
	}
}

$maintClass = PruneStaleUsers::class;
require_once RUN_MAINTENANCE_IF_MAIN;
