<?php

namespace MediaWiki\Extension\KZChatbot;

use Exception;
use RuntimeException;

class BannedWord {
	private ?int $id;
	private ?string $pattern;
	private ?string $description;
	private bool $isLoaded = false;

	/**
	 * @param int|null $id
	 * @param string|null $pattern
	 * @param string|null $description
	 */
	public function __construct( ?int $id = null, ?string $pattern = null, ?string $description = null ) {
		$this->id = $id;
		$this->pattern = $pattern;
		$this->description = $description;
	}

	/**
	 * @param string $pattern
	 * @param string|null $description
	 * @return self
	 */
	public static function createNew( string $pattern, ?string $description ): self {
		return new self( null, $pattern, $description );
	}

	/**
	 * @param null $id
	 * @return array
	 */
	private static function getRows( $id = null ): array {
		$dbr = wfGetDB( DB_REPLICA );
		$conds = $id ? [ 'kzcbb_id' => $id ] : [];

		$res = $dbr->select(
		   'kzchatbot_bannedwords',
			[ 'kzcbb_id', 'kzcbb_pattern', 'kzcbb_description' ],
			$conds
		);

		$bannedWords = [];
		for ( $row = $res->fetchRow(); is_array( $row ); $row = $res->fetchRow() ) {
			$bannedWords[] = $row;
		}

		return $bannedWords;
	}

	/**
	 * @param int $id
	 * @return mixed|null
	 */
	private static function getRow( int $id ) {
		$rows = self::getRows( $id );
		return $rows[0] ?? null;
	}

	/**
	 * @param array $row
	 * @return BannedWord
	 */
	public static function newFromRow( array $row ): BannedWord {
		return new self( $row['kzcbb_id'], $row['kzcbb_pattern'], $row['kzcbb_description'] );
	}

	/**
	 * Get all DB records as objects
	 *
	 * @return BannedWord[]
	 */
	public static function getAll(): array {
		$rows = self::getRows();
		$objects = [];
		foreach ( $rows as $row ) {
			$objects[] = self::newFromRow( $row );
		}

		return $objects;
	}

	/**
	 * Load the DB record
	 *
	 * @return void
	 */
	private function loadData() {
		if ( !$this->isLoaded && $this->id !== null ) {
			$row = self::getRow( $this->id );
			if ( empty( $row ) ) {
				throw new RuntimeException( "Word with id {$this->id} not found" );
			}

			$this->pattern = $row['kzcbb_pattern'];
			$this->description = $row['kzcbb_description'];
			$this->isLoaded = true;
		}
	}

	/**
	 * Is the pattern saved into the database
	 *
	 * @return bool
	 */
	public function exists(): bool {
		try {
			$this->loadData();

		} catch ( Exception $e ) {
			return false;
		}
		return $this->isLoaded;
	}

	/**
	 * Delete the object from the database
	 * @return bool
	 */
	public function delete(): bool {
		$dbw = wfGetDB( DB_PRIMARY );

		if ( !self::exists() ) {
			throw new RuntimeException( "Unsaved banned word cannot be deleted" );
		}

		try {
			$dbw->delete(
				'kzchatbot_bannedwords',
				[ 'kzcbb_id' => $this->id ]
			);
		} catch ( Exception $e ) {
			return false;
		}

		// There's no ID anymore, but someone might decide to call save() again
		$this->id = null;

		return true;
	}

	/**
	 * @return int|null
	 */
	public function getId(): ?int {
		return $this->id;
	}

	/**
	 * @return string|null
	 */
	public function getPattern(): ?string {
		// Don't override if we already changed the object
		if ( !$this->pattern ) {
			$this->loadData();
		}
		return $this->pattern;
	}

	/**
	 * @return string|null
	 */
	public function getDescription(): ?string {
		// Don't override if we already changed the object
		if ( !$this->description ) {
			$this->loadData();
		}
		return $this->description;
	}

	/**
	 * @return bool
	 */
	public function save(): bool {
		$dbw = wfGetDB( DB_PRIMARY );

		$recordContent = [
			'kzcbb_pattern' => $this->getPattern(),
			'kzcbb_description' => $this->getDescription()
		];

		// Update or save new
		try {
			if ( $this->id ) {
				$dbw->update(
					'kzchatbot_bannedwords',
					$recordContent,
					[ 'kzcbb_id' => $this->getId() ]
				);
			} else {
				$dbw->insert(
					'kzchatbot_bannedwords',
					$recordContent
				);

				$this->id = $dbw->insertId();
			}
		} catch ( Exception $e ) {
			return false;
		}

		$this->isLoaded = true;
		return true;
	}
}
