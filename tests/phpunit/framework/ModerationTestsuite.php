<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015-2020 Edward Chernenko.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
*/

require_once __DIR__ . '/autoload.php';

/**
 * @file
 * Automated testsuite of Extension:Moderation.
 */

class ModerationTestsuite {
	const TEST_PASSWORD = '123456';
	const DEFAULT_USER_AGENT = 'MediaWiki Moderation Testsuite';

	/** @var ModerationTestsuiteEngine */
	protected $engine;

	/** @var ModerationTestsuiteHTML */
	public $html;

	/** @var array Misc. information about the last edit, as populated by setLastEdit() */
	public $lastEdit = [];

	public function __construct() {
		$this->engine = ModerationTestsuiteEngine::factory();

		$this->prepareDbForTests();

		$this->html = new ModerationTestsuiteHTML( $this->engine );
		$this->setUserAgent( self::DEFAULT_USER_AGENT );

		# With "qqx" language selected, messages are replaced with
		# their names, so parsing process is translation-independent.
		# NOTE: this requires ModerationTestsuiteEngine subclass to support setMwConfig(),
		# which was optional before (RealHttpEngine doesn't support it,
		# but RealHttpEngine is incompatible with MW 1.28+, so it can be ignored for now).
		$this->setMwConfig( 'LanguageCode', 'qqx' );
	}

	public function query( $apiQuery ) {
		return $this->engine->query( $apiQuery );
	}

	public function httpGet( $url ) {
		return $this->engine->httpGet( $url );
	}

	public function httpPost( $url, array $postData = [] ) {
		return $this->engine->httpPost( $url, $postData );
	}

	public function getEditToken() {
		return $this->engine->getEditToken();
	}

	/**
	 * Sets MediaWiki global variable. Not supported by RealHttpEngine.
	 * @param string $name Name of variable without the $wg prefix.
	 * @throws PHPUnit_Framework_SkippedTestError TestsuiteEngine doesn't support this method.
	 */
	public function setMwConfig( $name, $value ) {
		$this->engine->setMwConfig( $name, $value );
	}

	/** Add an arbitrary HTTP header to all outgoing requests. */
	public function setHeader( $name, $value ) {
		$this->engine->setHeader( $name, $value );
	}

	/**
	 * Set User-Agent header for all outgoing requests.
	 */
	public function setUserAgent( $ua ) {
		$this->setHeader( 'User-Agent', $ua );
	}

	/**
	 * Don't throw exception when HTTP request returns $code.
	 */
	public function ignoreHttpError( $code ) {
		$this->engine->ignoreHttpError( $code );
	}

	/**
	 * Re-enable throwing an exception when HTTP request returns $code.
	 */
	public function stopIgnoringHttpError( $code ) {
		$this->engine->stopIgnoringHttpError( $code );
	}

	#
	# Functions for parsing Special:Moderation.
	#
	private $lastFetchedSpecial = [];

	public $new_entries;
	public $deleted_entries;

	public function getSpecialURL( $query = [] ) {
		$title = Title::newFromText( 'Moderation', NS_SPECIAL )->fixSpecialName();
		return wfAppendQuery( $title->getLocalURL(), $query );
	}

	/**
	 * Delete the results of previous fetchSpecial().
	 * If fetchSpecial() is then called, all entries
	 * in this folder will be considered new entries.
	 */
	public function assumeFolderIsEmpty( $folder = 'DEFAULT' ) {
		$this->lastFetchedSpecial[$folder] = [];
	}

	/**
	 * Download and parse Special:Moderation. Diff its current
	 * state with the previously downloaded/parsed state, and
	 * populate the arrays \b $new_entries, \b $old_entries.
	 * @note Logs in as $moderator.
	 */
	public function fetchSpecial( $folder = 'DEFAULT' ) {
		if ( !$this->isModerator() ) { /* Don't relogin in testModeratorNotAutomoderated() */
			$this->loginAs( $this->moderator );
		}

		$query = [ 'limit' => 150 ];
		if ( $folder != 'DEFAULT' ) {
			$query['folder'] = $folder;
		}
		$url = $this->getSpecialURL( $query );

		$html = $this->html->loadFromURL( $url );
		$spans = $html->getElementsByTagName( 'span' );

		$entries = [];
		foreach ( $spans as $span ) {
			if ( strpos( $span->getAttribute( 'class' ), 'modline' ) !== false ) {
				$e = new ModerationTestsuiteEntry( $span );
				$entries[$e->id] = $e;
			}
		}

		if ( array_key_exists( $folder, $this->lastFetchedSpecial ) ) {
			$before = $this->lastFetchedSpecial[$folder];
		} else {
			$before = [];
		}
		$after = $entries;

		$this->new_entries = array_values( array_diff_key( $after, $before ) );
		$this->deleted_entries = array_values( array_diff_key( $before, $after ) );

		$this->lastFetchedSpecial[$folder] = $entries;
	}

	#
	# Database-related functions.
	#
	private function createTestUser( $name, $groups = [] ) {
		$user = User::createNew( $name );
		if ( !$user ) {
			throw new MWException( __METHOD__ . ": failed to create User:$name." );
		}

		TestUser::setPasswordForUser( $user, self::TEST_PASSWORD );

		$user->saveSettings();

		foreach ( $groups as $g ) {
			$user->addGroup( $g );
		}

		return $user;
	}

	/**
	 * Create controlled environment before each test.
	 * (as in "Destroy everything on testsuite's path")
	 */
	private function prepareDbForTests() {
		/*
			Workaround the following problem: https://gerrit.wikimedia.org/r/328718

			Since MediaWiki 1.28, MediaWikiTestCase class
			started to aggressively isolate us from the real database.

			However this entire testsuite does the blackbox testing
			on the site, making HTTP queries as the users would do,
			so we need to check/modify the real database.

			Therefore we escape the "test DB" jail installed by MediaWikiTestCase.
		*/
		if ( class_exists( 'MediaWikiTestCase' ) ) { // Not in benchmark scripts
			$this->engine->escapeDbSandbox();
		}

		$dbw = wfGetDB( DB_MASTER );

		/* Make sure the database is in a consistent state
			(after messy tests like RollbackResistantQueryTest.php) */
		if ( $dbw->writesOrCallbacksPending() ) {
			$dbw->commit( __METHOD__, 'flush' );
		}

		$dbw->begin( __METHOD__ );

		$tablesToTruncate = [
			'moderation',
			'moderation_block',
			'user',
			'user_groups',
			'user_newtalk',
			'user_properties',
			'page',
			'revision',
			'revision_comment_temp',
			'logging',
			'log_search',
			'text',
			'image',
			'uploadstash',
			'recentchanges',
			'watchlist',
			'change_tag',
			'tag_summary',
			'actor',
			'abuse_filter',
			'abuse_filter_action',
			'ip_changes',
			'cu_changes',
			'slots',
			'objectcache'
		];
		if ( $dbw->getType() == 'postgres' ) {
			$tablesToTruncate[] = 'mwuser';
			$tablesToTruncate[] = 'pagecontent';
		}

		foreach ( $tablesToTruncate as $table ) {
			# Short version of MediaWikiIntegrationTestCase::truncateTable(),
			# which doesn't exist in MW 1.31 and is a protected method.

			if ( $dbw->tableExists( $table ) ) {
				$dbw->delete( $table, '*', __METHOD__ );
				if ( $dbw->getType() == 'postgres' ) {
					$dbw->resetSequenceForTable( $table, __METHOD__ );
				}
			}
		}

		$dbw->commit( __METHOD__ );

		$this->moderator =
			$this->createTestUser( 'User 1', [ 'moderator', 'automoderated' ] );
		$this->moderatorButNotAutomoderated =
			$this->createTestUser( 'User 2', [ 'moderator' ] );
		$this->automoderated =
			$this->createTestUser( 'User 3', [ 'automoderated' ] );
		$this->rollback =
			$this->createTestUser( 'User 4', [ 'rollback' ] );
		$this->unprivilegedUser =
			$this->createTestUser( 'User 5', [] );
		$this->unprivilegedUser2 =
			$this->createTestUser( 'User 6', [] );
		$this->moderatorAndCheckuser =
			$this->createTestUser( 'User 7', [ 'moderator', 'checkuser' ] );

		$this->purgeTagCache();

		// Avoid stale data being reported by Title::getArticleId(), etc. on the test side
		// when running multiple sequential tests, e.g. in ModerationQueueTest.
		Title::clearCaches();

		// Clear the memcached (NOTE: works only for one memcached server).
		$this->purgeMemcached();
	}

	/**
	 * Clear the memcached keys related to this testwiki.
	 */
	public function purgeMemcached() {
		global $wgMemCachedServers, $wgDBname;
		if ( empty( $wgMemCachedServers ) ) {
			return;
		}

		$startTime = microtime( true );

		// NOTE: this works only for one memcached server.
		$memcClient = new MemcachedClient( [
			'servers' => [ $wgMemCachedServers[0] ]
		] );
		$sock = $memcClient->get_sock( 'anyKey' ); // Only one Memcached server

		// Delete all memcached keys related to this wiki.
		// NOTE: simpler alternative is $memcClient->run_command( $sock, "flush_all\r\n" ),
		// but that wouldn't allow to run testsuite in multiple threads, where each thread
		// uses a separate database (and therefore has different $wgDBname).
		$keysToDelete = [];
		$keysToSpare = []; // For debugging only

		$ret = $memcClient->run_command( $sock, "lru_crawler metadump all\r\n" );
		foreach ( $ret as $foundObject ) {
			foreach ( explode( ' ', $foundObject ) as $param ) {
				$pairs = explode( '=', $param );
				if ( $pairs[0] === 'key' && isset( $pairs[1] ) ) {
					$key = rawurldecode( $pairs[1] );

					// Note: we shouldn't use wfWikiId() instead of $wgDBname,
					// because "rdbms-server-readonly" key only contains $wgDBname,
					// and it MUST be cleared after ReadOnly tests.
					if ( strpos( $key, $wgDBname ) !== false ) {
						$keysToDelete[] = $key;
					} else {
						$keysToSpare[] = $key;
					}
					break;
				}
			}
		}

		if ( method_exists( 'BagOStuff', 'deleteMulti' ) ) {
			// MediaWiki 1.33+
			$cache = wfGetMainCache();
			$cache->deleteMulti( $keysToDelete );
		} else {
			// MediaWiki 1.31-1.32
			foreach ( $keysToDelete as $key ) {
				if ( !$memcClient->delete( $key ) ) {
					throw new MWException( __METHOD__ . ": cleaning Memcached FAILED." );
				}
			}
		}

		$timeSpent = sprintf( '%.3f', ( microtime( true ) - $startTime ) );

		$logger = new ModerationTestsuiteLogger( 'ModerationTestsuite' );
		$logger->info( '[cleanup] Purged Memcached', [
			'deletedKeys' => $keysToDelete,
			'sparedKeys' => $keysToSpare,
			'timeSpentOnPurge' => $timeSpent
		] );
	}

	/** Prevent tags set by the previous test from affecting the current test */
	public function purgeTagCache() {
		ChangeTags::purgeTagCacheAll(); /* For RealHttpEngine tests */
	}

	#
	# High-level test functions.
	#
	public $moderator;
	public $moderatorButNotAutomoderated;
	public $rollback;
	public $automoderated;
	public $unprivilegedUser;
	public $unprivilegedUser2;
	public $moderatorAndCheckuser;

	/** @var User */
	protected $currentUser = null;

	public function loggedInAs() {
		if ( $this->currentUser === null ) {
			$this->currentUser = $this->engine->loggedInAs();
		}

		return $this->currentUser;
	}

	public function isModerator() {
		if ( !$this->currentUser ) {
			return false;
		}

		return in_array( $this->currentUser->getId(), [
			$this->moderator->getId(),
			$this->moderatorButNotAutomoderated->getId(),
			$this->moderatorAndCheckuser->getId()
		] );
	}

	public function loginAs( User $user ) {
		if ( $this->currentUser && $user->getId() == $this->currentUser->getId() ) {
			return; /* Nothing to do, already logged in */
		}

		if ( $user->isAnon() ) {
			$this->logout();
			return;
		}

		$this->engine->loginAs( $user );
		$this->currentUser = $user;
	}

	public function logout() {
		$this->engine->logout();
		$this->currentUser = $this->engine->loggedInAs();
	}

	/**
	 * Create an account and return User object.
	 * @note Will not login automatically (loginAs must be called).
	 * @return User
	 */
	public function createAccount( $username ) {
		return $this->engine->createAccount( $username );
	}

	/**
	 * Perform a test move.
	 * @return ModerationTestsuiteBotResponse
	 */
	public function doTestMove( $oldTitle, $newTitle, $reason = '', array $extraParams = [] ) {
		return $this->getBot( 'nonApi' )->move( $oldTitle, $newTitle, $reason, $extraParams );
	}

	/**
	 * Place information about newly made change into lastEdit[] array.
	 */
	public function setLastEdit( $title, $summary, array $extraData = [] ) {
		$this->lastEdit = $extraData + [
			'User' => $this->loggedInAs()->getName(),
			'Title' => $title,
			'Summary' => $summary
		];
	}

	/**
	 * Create a new bot.
	 * @param string $method One of the following: 'api', 'nonApi'.
	 * @return ModerationTestsuiteBot
	 */
	public function getBot( $method ) {
		return ModerationTestsuiteBot::factory( $method, $this );
	}

	/**
	 * Perform a test edit.
	 * @return ModerationTestsuiteBotResponse
	 */
	public function doTestEdit(
		$title = null,
		$text = null,
		$summary = null,
		$section = '',
		$extraParams = []
	) {
		return $this->getBot( 'nonApi' )->edit( $title, $text, $summary, $section, $extraParams );
	}

	public $TEST_EDITS_COUNT = 3; /* See doNTestEditsWith() */

	/**
	 * Do 2*N alternated edits - N by $user1 and N by $user2.
	 * Number of edits is $TEST_EDITS_COUNT.
	 * If $user2 is null, only makes N edits by $user1.
	 */
	public function doNTestEditsWith( $user1, $user2 = null,
		$prefix1 = 'Page', $prefix2 = 'AnotherPage'
	) {
		for ( $i = 0; $i < $this->TEST_EDITS_COUNT; $i++ ) {
			$this->loginAs( $user1 );
			$this->doTestEdit( $prefix1 . $i );

			if ( $user2 ) {
				$this->loginAs( $user2 );
				$this->doTestEdit( $prefix2 . $i );
			}
		}
	}

	/**
	 * Makes one edit and returns its correct entry.
	 * @note Logs in as $moderator.
	 * @return ModerationTestsuiteEntry
	 */
	public function getSampleEntry( $title = null ) {
		$this->fetchSpecial();
		$this->loginAs( $this->unprivilegedUser );
		$this->doTestEdit( $title );
		$this->fetchSpecial();

		return $this->new_entries[0];
	}

	/**
	 * Perform a test upload.
	 * @return ModerationTestsuiteBotResponse
	 */
	public function doTestUpload(
		$title = null,
		$srcFilename = null,
		$text = null,
		array $extraParams = []
	) {
		return $this->getBot( 'nonApi' )->upload( $title, $srcFilename, $text, $extraParams );
	}

	/**
	 * Resolve $srcFilename into an absolute path.
	 * Used in tests: '1.png' is found at [tests/resources/1.png].
	 * @return string
	 */
	public static function findSourceFilename( $srcFilename ) {
		if ( !$srcFilename ) {
			$srcFilename = "image100x100.png";
		}

		if ( substr( $srcFilename, 0, 1 ) != '/' ) {
			$srcFilename = __DIR__ . "/../../resources/" . $srcFilename;
		}

		return realpath( $srcFilename );
	}

	/**
	 * Get up to $count moderation log entries via API (most recent first).
	 * @return array
	 */
	public function apiLogEntries( $count = 100 ) {
		$ret = $this->query( [
			'action' => 'query',
			'list' => 'logevents',
			'letype' => 'moderation',
			'lelimit' => $count
		] );
		return $ret['query']['logevents'];
	}

	/**
	 * Get up to $count moderation log entries NOT via API (most recent first).
	 * @return array
	 */
	public function nonApiLogEntries( $count = 100 ) {
		$title = Title::newFromText( 'Log/moderation', NS_SPECIAL )->fixSpecialName();
		$url = wfAppendQuery( $title->getLocalURL(), [
			'limit' => $count
		] );
		$html = $this->html->loadFromURL( $url );

		$events = [];
		$list_items = $html->getElementsByTagName( 'li' );
		foreach ( $list_items as $li ) {
			$class = $li->getAttribute( 'class' );
			if ( strpos( $class, 'mw-logline-moderation' ) !== false ) {
				$matches = null;
				if ( preg_match( '/\(logentry-moderation-([^:]+): (.*)\)\s*$/',
					$li->textContent, $matches ) ) {
					$events[] = [
						'type' => $matches[1],
						'params' => explode( ', ', $matches[2] )
					];
				}
			}
		}
		return $events;
	}

	/**
	 * Get the last revision of page $title via API.
	 * @return array
	 */
	public function getLastRevision( $title ) {
		$ret = $this->query( [
			'action' => 'query',
			'prop' => 'revisions',
			'rvlimit' => 1,
			'rvprop' => 'user|timestamp|comment|content|ids|tags',
			'titles' => $title
		] );
		$retPage = array_shift( $ret['query']['pages'] );
		return isset( $retPage['missing'] ) ? false : $retPage['revisions'][0];
	}

	/**
	 * Remove "token=" from URL and return its new HTML title.
	 * @return string|null
	 */
	public function noTokenTitle( $url ) {
		$bad_url = preg_replace( '/token=[^&]*/', '', $url );
		return $this->html->getTitle( $bad_url );
	}

	/**
	 * Corrupt "token=" in URL and return its new HTML title.
	 * @return string|null
	 */
	public function badTokenTitle( $url ) {
		$bad_url = preg_replace( '/(token=)([^&]*)/', '\1WRONG\2', $url );
		return $this->html->getTitle( $bad_url );
	}

	/**
	 * Wait for "recentchanges" table to be updated by DeferredUpdates.
	 *
	 * Usage:
	 * 	$waiter = $t->waitForRecentChangesToAppear();
	 * 	// Do something that should create N recentchanges entries
	 * 	$waiter( N );
	 * @return callable
	 */
	public function waitForRecentChangesToAppear() {
		$dbw = wfGetDB( DB_MASTER );
		$lastRcId = $dbw->selectField( 'recentchanges', 'rc_id', '', __METHOD__,
			[ 'ORDER BY' => 'rc_timestamp DESC' ]
		);

		return function ( $numberOfEdits ) use ( $dbw, $lastRcId ) {
			$pollTimeLimitSeconds = 5; /* Polling will fail after these many seconds */
			$pollRetryPeriodSeconds = 0.2; /* How often to check recentchanges */

			/* Wait for all $revisionIds to appear in recentchanges table */
			$maxTime = time() + $pollTimeLimitSeconds;
			do {
				$rcRowsFound = $dbw->selectRowCount(
					'recentchanges', 'rc_id',
					[ 'rc_id > ' . $dbw->addQuotes( $lastRcId ) ],
					'waitForRecentChangesToAppear',
					[ 'LIMIT' => $numberOfEdits ]
				);
				if ( $rcRowsFound >= $numberOfEdits ) {
					return; /* Success */
				}

				/* Continue polling */
				usleep( $pollRetryPeriodSeconds * 1000 * 1000 );
			} while ( time() < $maxTime );

			throw new MWException(
				"waitForRecentChangesToAppear(): new $numberOfEdits entries haven't " .
				"appeared in $pollTimeLimitSeconds seconds." );
		};
	}

	/**
	 * Queue an edit that would cause an edit conflict when approved.
	 * @return ModerationEntry
	 */
	public function causeEditConflict( $title, $origText, $textOfUser1, $textOfUser2 ) {
		$this->loginAs( $this->automoderated );
		$this->doTestEdit( $title, $origText );

		$this->loginAs( $this->unprivilegedUser );
		$this->doTestEdit( $title, $textOfUser1 );

		$this->loginAs( $this->automoderated );
		$this->doTestEdit( $title, $textOfUser2 );

		$this->fetchSpecial();
		return $this->new_entries[0];
	}

	/**
	 * Get cuc_agent of the last entry in "cu_changes" table.
	 * @return User-agent (string).
	 */
	public function getCUCAgent() {
		$agents = $this->getCUCAgents( 1 );
		return array_pop( $agents );
	}

	/**
	 * Get cuc_agent of the last entries in "cu_changes" table.
	 * @param int $limit How many entries to select.
	 * @return Array of user-agents.
	 */
	public function getCUCAgents( $limit ) {
		$dbw = wfGetDB( DB_MASTER );
		return $dbw->selectFieldValues(
			'cu_changes', 'cuc_agent', '',
			__METHOD__,
			[
				'ORDER BY' => 'cuc_id DESC',
				'LIMIT' => $limit
			]
		);
	}

	/**
	 * Create AbuseFilter rule that will assign tags to all edits.
	 * @return ID of the newly created filter.
	 */
	public function addTagAllAbuseFilter( array $tags ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->insert( 'abuse_filter',
			[
				'af_pattern' => 'true',
				'af_user' => 0,
				'af_user_text' => 'MediaWiki default',
				'af_timestamp' => $dbw->timestamp(),
				'af_enabled' => 1,
				'af_comments' => '',
				'af_public_comments' => 'Assign tags to all edits',
				'af_hidden' => 0,
				'af_hit_count' => 0,
				'af_throttled' => 0,
				'af_deleted' => 0,
				'af_actions' => 'tag',
				'af_global' => 0,
				'af_group' => 'default'
			],
			__METHOD__
		);
		$filterId = $dbw->insertId();

		$dbw->insert( 'abuse_filter_action',
			[
				'afa_filter' => $filterId,
				'afa_consequence' => 'tag',
				'afa_parameters' => implode( "\n", $tags )
			],
			__METHOD__
		);

		return $filterId;
	}

	/**
	 * Disable AbuseFilter rule #$filterId.
	 */
	public function disableAbuseFilter( $filterId ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'abuse_filter', [ 'af_enabled' => 0 ], [ 'af_id' => $filterId ], __METHOD__ );
		$this->purgeTagCache();
	}

	/**
	 * Assert that API response $ret contains error $expectedErrorCode.
	 */
	public function assertApiError( $expectedErrorCode, array $ret, MediaWikiTestCase $tcase ) {
		$tcase->assertArrayHasKey( 'error', $ret );
		$tcase->assertEquals( $expectedErrorCode, $ret['error']['code'] );
	}

	/**
	 * Call version_compare on $wgVersion.
	 * @return bool
	 */
	public static function mwVersionCompare( $compareWith, $operator ) {
		global $wgVersion;
		return version_compare( $wgVersion, $compareWith, $operator );
	}

	/**
	 * Sleep before the reupload, so that it wouldn't fail due to archive name collision.
	 *
	 * Archived image names are based on time (up to the second), so if two uploads happen
	 * within the same second, only the first would succeed.
	 */
	public function sleepUntilNextSecond() {
		usleep( 1000 * 1000 - gettimeofday()['usec'] );
	}

	/** Apply ModerationBlock to $user */
	public function modblock( User $user ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->insert( 'moderation_block',
			[
				'mb_address' => $user->getName(),
				'mb_user' => $user->getId(),
				'mb_by' => 0,
				'mb_by_text' => 'Some moderator',
				'mb_timestamp' => $dbw->timestamp()
			],
			__METHOD__
		);
	}
}
