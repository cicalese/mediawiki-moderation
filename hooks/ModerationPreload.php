<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2020 Edward Chernenko.

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
 * Hooks/methods to preload edits which are pending moderation.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Moderation\EntryFactory;
use MediaWiki\Moderation\ForgetAnonIdConsequence;
use MediaWiki\Moderation\GiveAnonChangesToNewUserConsequence;
use MediaWiki\Moderation\IConsequenceManager;
use MediaWiki\Moderation\PendingEdit;
use MediaWiki\Moderation\RememberAnonIdConsequence;

/*
	Calculating 'mod_preload_id':
	1) For anonymous user: ']' + hex string in the session.
	2) For registered user: '[' + username.

	Note: ']' and '[' are used because they aren't allowed in usernames.
*/

class ModerationPreload {
	/** @var EditPage|null Editor object passed from onAlternateEdit() to onEditFormPreloadText() */
	protected $editPage = null;

	/** @var User|null Current user. If not set, $wgUser will be used. */
	private $user = null;

	/** @var EntryFactory */
	protected $entryFactory;

	/** @var IConsequenceManager */
	protected $consequenceManager;

	/**
	 * @param EntryFactory $entryFactory
	 * @param IConsequenceManager $consequenceManager
	 */
	public function __construct( EntryFactory $entryFactory,
		IConsequenceManager $consequenceManager
	) {
		$this->entryFactory = $entryFactory;
		$this->consequenceManager = $consequenceManager;
	}

	/**
	 * Get the request.
	 * @return WebRequest
	 */
	protected function getRequest() {
		return RequestContext::getMain()->getRequest();
	}

	/**
	 * Get the user.
	 * @return User
	 */
	protected function getUser() {
		if ( $this->user ) {
			return $this->user;
		}

		return RequestContext::getMain()->getUser();
	}

	/**
	 * Override the current user: preload for $user instead.
	 * @param User $user
	 */
	public function setUser( User $user ) {
		$this->user = $user;
	}

	/**
	 * Calculate value of mod_preload_id for the current user.
	 * @param bool $create If true, new preload ID will be generated for first-time anonymous editors.
	 * @return string|false Preload ID (string).
	 * Returns false if current user is anonymous AND hasn't edited before AND $create is false.
	 */
	public function getId( $create = false ) {
		$user = $this->getUser();
		if ( $user->isLoggedIn() ) {
			return '[' . $user->getName();
		}

		return $this->getAnonId( $create );
	}

	/**
	 * Calculate mod_preload_id for anonymous user.
	 * @param bool $create If true, new preload ID will be generated for first-time anonymous editors.
	 * @return string|false Preload ID (string), if already existed or just created.
	 */
	protected function getAnonId( $create ) {
		$anonToken = $this->getRequest()->getSessionData( 'anon_id' );
		if ( !$anonToken ) {
			if ( !$create ) {
				return false;
			}

			$anonToken = $this->consequenceManager->add( new RememberAnonIdConsequence() );
		}

		return ']' . $anonToken;
	}

	/**
	 * LocalUserCreated hook handler - called when user creates an account.
	 * If the user did some anonymous edits before registering,
	 * this hook makes them non-anonymous, so that they could be preloaded.
	 * @param User $user
	 * @param bool $autocreated @phan-unused-param
	 * @return true
	 */
	public static function onLocalUserCreated( $user, $autocreated ) {
		$preload = MediaWikiServices::getInstance()->getService( 'Moderation.Preload' );
		$preload->runNewUserHook( $user );

		return true;
	}

	/**
	 * Main logic of LocalUserCreated hook.
	 * @param User $user
	 */
	protected function runNewUserHook( User $user ) {
		$this->setUser( $user );

		$anonId = $this->getAnonId( false );
		if ( !$anonId ) { # This visitor never saved any edits
			return;
		}

		$this->consequenceManager->add( new GiveAnonChangesToNewUserConsequence(
			$user, $anonId, $this->getId()
		) );

		// Forget the fact that this user edited anonymously:
		// this user is now registered and no longer needs anonymous preload.
		$this->consequenceManager->add( new ForgetAnonIdConsequence() );
	}

	/**
	 * Check if there is a pending-moderation edit of this user to this page,
	 * and if such edit exists, then load its text and edit comment.
	 * @param Title $title
	 * @return PendingEdit|false
	 */
	public function findPendingEdit( Title $title ) {
		$id = $this->getId();
		if ( !$id ) { # This visitor never saved any edits
			return false;
		}

		return $this->entryFactory->findPendingEdit( $id, $title );
	}

	/**
	 * If there is an edit (currently pending moderation) made by the
	 * current user, inform EditPage object of its Text and Summary,
	 * so that the user can continue editing its own revision.
	 * @param string &$text @phan-output-reference
	 * @param Title $title
	 * @param EditPage|null $editPage
	 */
	protected function showPendingEdit( &$text, $title, $editPage ) {
		$section = $this->getRequest()->getVal( 'section', '' );
		if ( $section == 'new' ) {
			# Nothing to preload if new section is being created
			return;
		}

		$pendingEdit = $this->findPendingEdit( $title );
		if ( !$pendingEdit ) {
			return;
		}

		$out = RequestContext::getMain()->getOutput();
		$out->addModules( 'ext.moderation.edit' );
		$out->wrapWikiMsg( '<div id="mw-editing-your-version">$1</div>',
			[ 'moderation-editing-your-version' ] );

		$text = $pendingEdit->getText();
		if ( $editPage ) {
			$editPage->summary = $pendingEdit->getComment();
		}

		if ( $section !== '' ) {
			$fullContent = ContentHandler::makeContent( $text, $title );
			$sectionContent = $fullContent->getSection( $section );

			if ( $sectionContent ) {
				$text = $sectionContent->getNativeData();
			}
		}
	}

	/**
	 * AlternateEdit hook handler.
	 * Remember EditPage object, which will then be used in onEditFormPreloadText.
	 * @param EditPage $editPage
	 * @return true
	 */
	public static function onAlternateEdit( $editPage ) {
		$preload = MediaWikiServices::getInstance()->getService( 'Moderation.Preload' );
		$preload->editPage = $editPage;

		return true;
	}

	/**
	 * EditFormPreloadText hook handler.
	 * Preloads text/summary when the article doesn't exist yet.
	 * @param string &$text
	 * @param Title &$title
	 * @return true
	 */
	public static function onEditFormPreloadText( &$text, &$title ) {
		$preload = MediaWikiServices::getInstance()->getService( 'Moderation.Preload' );
		$preload->showPendingEdit( $text, $title, $preload->editPage );

		return true;
	}

	/**
	 * EditFormPreloadText hook handler.
	 * Preloads text/summary when the article already exists.
	 * @param EditPage $editPage
	 * @return true
	 */
	public static function onEditFormInitialText( $editPage ) {
		$preload = MediaWikiServices::getInstance()->getService( 'Moderation.Preload' );
		$preload->showPendingEdit( $editPage->textbox1, $editPage->getTitle(), $editPage );

		return true;
	}
}
