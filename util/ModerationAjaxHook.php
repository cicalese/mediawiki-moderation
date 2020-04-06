<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2016-2020 Edward Chernenko.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
*/

/**
 * @file
 * Adds ajaxhook-related JavaScript modules when they are needed
 * (when MobileFrontend and/or VisualEditor extensions are installed and used).
 */

use MediaWiki\MediaWikiServices;

class ModerationAjaxHook {
	/**
	 * Add needed modules to $out.
	 * @param OutputPage &$out
	 */
	public static function add( OutputPage &$out ) {
		global $wgVersion;

		$modules = [];
		if ( class_exists( 'MobileContext' ) && MobileContext::singleton()->shouldDisplayMobileView() ) {
			$modules[] = 'ext.moderation.mf.notify';

			if ( version_compare( $wgVersion, '1.33.0', '>=' ) ) {
				$modules[] = 'ext.moderation.mf.preload33';

				$title = $out->getTitle();
				$preload = MediaWikiServices::getInstance()->getService( 'Moderation.Preload' );

				if ( !$title->exists() && $preload->findPendingEdit( $title ) ) {
					// This user has a pending revision in $title, but $title doesn't exist.
					// Non-existent pages have wgArticleId=0, and MobileFrontend won't even try
					// to load their text.
					// HACK: fake wgArticleId makes MobileFrontend think that this page exists.
					$out->addJsConfigVars( 'wgArticleId', -1 ); // Not 0 means "page exists"
				}

			} else {
				// For MediaWiki 1.31-1.32
				$modules[] = 'ext.moderation.mf.preload31';
			}
		} elseif ( class_exists( 'ApiVisualEditorEdit' ) ) {
			$modules[] = 'ext.moderation.ve';
		}

		if ( $modules || $out->getConfig()->get( 'ModerationForceAjaxHook' ) ) {
			$modules[] = 'ext.moderation.ajaxhook';
			$out->addModules( $modules );
		}
	}
}
