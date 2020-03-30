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
 * Adds ajaxhook-related JavaScript modules when they are needed.
 *
 * Default behavior: automatically check for presence of extension.
 * For example, if Extension:VisualEditor is detected,
 * then module 'ext.moderation.ve' will be attached.
 *
 * This can be overridden in LocalSettings.php:
 * $wgModerationSupportVisualEditor = true; - attach even if not detected.
 * $wgModerationSupportVisualEditor = false; - don't attach even if detected.
 * $wgModerationSupportVisualEditor = "guess"; - default behavior.
 *
 * If at least one module is attached (or if $wgModerationForceAjaxHook is
 * set to true), "ext.moderation.ajaxhook" will also be attached.
 */

use MediaWiki\MediaWikiServices;

class ModerationAjaxHook {

	/**
	 * Depending on $configName being true/false/"guess", return true/false/$default.
	 * @param string $configName
	 * @param bool $default
	 * @return bool
	 */
	protected static function need( $configName, $default ) {
		$config = RequestContext::getMain()->getConfig();
		$val = $config->get( $configName );
		return ( is_bool( $val ) ? $val : $default );
	}

	/**
	 * Convenience method: returns true if in Mobile skin, false otherwise
	 * @return bool
	 */
	protected static function isMobile() {
		return ( class_exists( 'MobileContext' ) &&
			MobileContext::singleton()->shouldDisplayMobileView() );
	}

	/**
	 * Guess whether VisualEditor needs to be supported
	 * @return bool
	 */
	protected static function guessVE() {
		return ( class_exists( 'ApiVisualEditorEdit' ) && !self::isMobile() );
	}

	/**
	 * Add needed modules to $out.
	 * @param OutputPage &$out
	 */
	public static function add( OutputPage &$out ) {
		global $wgVersion;
		$modules = [];

		if ( self::need( 'ModerationSupportVisualEditor', self::guessVE() ) ) {
			$modules[] = 'ext.moderation.ve';
		}

		if ( self::need( 'ModerationSupportMobileFrontend', self::isMobile() ) ) {
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
		}

		if ( $modules || $out->getConfig()->get( 'ModerationForceAjaxHook' ) ) {
			$modules[] = 'ext.moderation.ajaxhook';
			$out->addModules( $modules );
		}
	}
}
