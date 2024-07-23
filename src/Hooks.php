<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

namespace MediaWiki\Extension\KZChatbot;

class Hooks implements
	\MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook
{

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdates
	 * @param \DatabaseUpdater $updater DatabaseUpdater subclass
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onLoadExtensionSchemaUpdates( $updater ): bool {
		$updater->addExtensionTable(
			'kzchatbot_users',
			__DIR__ . '/../sql/table_kzchatbot_users.sql'
		);
		$updater->addExtensionTable(
			'kzchatbot_settings',
			__DIR__ . '/../sql/table_kzchatbot_settings.sql'
		);
		$updater->addExtensionTable(
			'kzchatbot_text',
			__DIR__ . '/../sql/table_kzchatbot_text.sql'
		);
		return true;
	}

}
