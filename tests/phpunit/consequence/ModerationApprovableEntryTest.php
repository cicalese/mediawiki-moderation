<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020 Edward Chernenko.

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
 * Unit test of ModerationApprovableEntry.
 */

use MediaWiki\Moderation\AddLogEntryConsequence;
use MediaWiki\Moderation\DeleteRowFromModerationTableConsequence;
use MediaWiki\Moderation\IConsequenceManager;
use MediaWiki\Moderation\InstallApproveHookConsequence;
use Wikimedia\TestingAccessWrapper;

require_once __DIR__ . "/autoload.php";

class ModerationApprovableEntryTest extends ModerationUnitTestCase {
	/**
	 * Check possible errors and consequences of ModerationApprovableEntry::approve().
	 * @param array $opt
	 * @covers ModerationApprovableEntry
	 * @dataProvider dataProviderApprove
	 */
	public function testApprove( array $opt ) {
		$expectedError = $opt['expectedError'] ?? null;
		$doApproveError = $opt['errorFromDoApprove'] ?? null;
		$moderatorUser = User::newFromName( '10.60.110.160', false );

		$entry = $this->makeEntry(
		function ( &$row, $manager, $approveHook )
		use ( $moderatorUser, $opt, $expectedError, $doApproveError ) {
			$authorUser = self::getTestUser()->getUser();
			$title = Title::newFromText( "Project:Some page" );

			$row = (object)[
				'user' => $authorUser->getId(),
				'user_text' => $authorUser->getName(),
				'namespace' => $title->getNamespace(),
				'type' => ModerationNewChange::MOD_TYPE_EDIT,
				'title' => $title->getDBKey(),
				'id' => 12345,
				'timestamp' => '20001020010203',
				'ip' => '10.20.30.40',
				'header_xff' => 'Sample XFF',
				'header_ua' => 'Sample-User-Agent',
				'merged_revid' => 0,
				'rejected' => 0,
				'tags' => null
			];
			if ( $opt['isAlreadyRejected'] ?? false ) {
				$row->rejected = 1;
			}
			if ( $opt['isAlreadyMerged'] ?? false ) {
				$row->merged_revid = 56789;
			}

			if ( $expectedError && !$doApproveError ) {
				// Unsuccessful action shouldn't have any consequences,
				// except for situation when doApprove() returns unsuccessful Status object.
				$manager->expects( $this->never() )->method( 'add' );
			} else {
				$manager->expects( $this->at( 0 ) )->method( 'add' )->with( $this->consequenceEqualTo(
					new InstallApproveHookConsequence(
						$title,
						$authorUser,
						$row->type,
						[
							'ip' => $row->ip,
							'xff' => $row->header_xff,
							'ua' => $row->header_ua,
							'tags' => $row->tags,
							'timestamp' => $row->timestamp
						]
					)
				) );

				if ( $doApproveError ) {
					// If doApprove() has failed, then no consequences will happen
					// (except InstallApproveHookConsequence, which was added before doApprove).
					$manager->expects( $this->once() )->method( 'add' );
				} else {
					$manager->expects( $this->at( 1 ) )->method( 'add' )->with( $this->consequenceEqualTo(
						new AddLogEntryConsequence(
							'mocked-approve-subtype',
							$moderatorUser,
							$title,
							[ 'mocked-log-param' => 'mocked-param-value' ],
							true // Run ApproveHook on newly created log entry
						)
					) );
					$manager->expects( $this->at( 2 ) )->method( 'add' )->with( $this->consequenceEqualTo(
						new DeleteRowFromModerationTableConsequence( $row->id )
					) );
					$manager->expects( $this->exactly( 3 ) )->method( 'add' );
				}
			}
		}, [ 'doApprove', 'getApproveLogSubtype', 'getApproveLogParameters', 'canReapproveRejected' ] );

		// Mock methods like doApprove() that are supposed to be overridden by subclasses.
		if ( $expectedError && !$doApproveError ) {
			$entry->expects( $this->never() )->method( 'doApprove' );
		} else {
			$approveStatus = Status::newGood();
			if ( isset( $opt['errorFromDoApprove'] ) ) {
				$approveStatus->fatal( $opt['errorFromDoApprove'] );
			}

			$entry->expects( $this->once() )->method( 'doApprove' )->willReturn( $approveStatus );
			$entry->expects( $this->any() )->method( 'getApproveLogSubtype' )
				->willReturn( 'mocked-approve-subtype' );
			$entry->expects( $this->any() )->method( 'getApproveLogParameters' )
				->willReturn( [ 'mocked-log-param' => 'mocked-param-value' ] );
			$entry->expects( $this->any() )->method( 'canReapproveRejected' )
				->willReturn( $opt['canReapproveRejected'] ?? true );
		}

		'@phan-var ModerationApprovableEntry $entry';

		if ( $expectedError ) {
			$this->expectExceptionObject( new ModerationError( $expectedError ) );
		}
		$entry->approve( $moderatorUser );
	}

	/**
	 * Provide datasets for testApprove() runs.
	 * @return array
	 */
	public function dataProviderApprove() {
		return [
			'successful approval' => [ [] ],
			'successful approval (rejected edit)' => [ [
				'isAlreadyRejected' => true
			] ],
			'error: rejected too long ago' => [ [
				'expectedError' => 'moderation-rejected-long-ago',
				'isAlreadyRejected' => true,
				'canReapproveRejected' => false
			] ],
			'error: already merged' => [ [
				'expectedError' => 'moderation-already-merged',
				'isAlreadyMerged' => true
			] ],
			'error: doApprove() returned Status that indicates failure' => [ [
				'expectedError' => 'some-mocked-error-code',
				'errorFromDoApprove' => 'some-mocked-error-code'
			] ]
		];
	}

	/**
	 * Test return value of getApproveLogParameters() when it is not overridden in a subclass.
	 * @covers ModerationApprovableEntry
	 */
	public function testDefaultApproveLogParameters() {
		$entry = $this->makeEntry( function ( $row, $manager, $approveHook ) {
			$manager->expects( $this->never() )->method( 'add' );
			$approveHook->expects( $this->once() )->method( 'getLastRevId' )->willReturn( 56789 );
		} );

		$wrapper = TestingAccessWrapper::newFromObject( $entry );
		$this->assertSame( [ 'revid' => 56789 ], $wrapper->getApproveLogParameters() );
	}

	/**
	 * Test return value of getApproveLogSubtype() when it is not overridden in a subclass.
	 * @covers ModerationApprovableEntry
	 */
	public function testDefaultApproveLogSubtype() {
		$wrapper = TestingAccessWrapper::newFromObject( $this->makeEntry() );
		$this->assertSame( 'approve', $wrapper->getApproveLogSubtype() );
	}

	/**
	 * Test return value of getId().
	 * @covers ModerationApprovableEntry
	 */
	public function testGetId() {
		$entry = $this->makeEntry( function ( $row, $manager, $approveHook ) {
			$manager->expects( $this->never() )->method( 'add' );
			$row->id = 34567;
		} );

		'@phan-var ModerationApprovableEntry $entry';

		$this->assertSame( 34567, $entry->getId() );
	}

	/**
	 * Create ModerationApprovableEntry using callback that receives all mocked dependencies.
	 * @param callable|null $setupMocks Callback that can configure MockObject dependencies.
	 * @param string[] $methods Array of method names to mock (for MockBuilder::setMethods()).
	 * @return \PHPUnit\Framework\MockObject\MockObject
	 */
	private function makeEntry( callable $setupMocks = null, array $methods = [] ) {
		$row = (object)[];
		$manager = $this->createMock( IConsequenceManager::class );
		$approveHook = $this->createMock( ModerationApproveHook::class );

		if ( $setupMocks ) {
			$setupMocks( $row, $manager, $approveHook );
		} else {
			// Since we are not configuring a mock of ConsequenceManager,
			// it means that we expect no consequences to be added.
			$manager->expects( $this->never() )->method( 'add' );
		}

		return $this->getMockBuilder( ModerationApprovableEntry::class )
			->setConstructorArgs( [ $row, $manager, $approveHook ] )
			->setMethods( $methods )
			->getMockForAbstractClass();
	}

	/**
	 * Test the return value of ModerationApprovableEntry::getFields().
	 * @covers ModerationApprovableEntry
	 */
	public function testFields() {
		$expectedFields = [
			'mod_user AS user',
			'mod_user_text AS user_text',
			'mod_namespace AS namespace',
			'mod_title AS title',
			'mod_type AS type',
			'mod_page2_namespace AS page2_namespace',
			'mod_page2_title AS page2_title',
			'mod_id AS id',
			'mod_timestamp AS timestamp',
			'mod_cur_id AS cur_id',
			'mod_comment AS comment',
			'mod_minor AS minor',
			'mod_bot AS bot',
			'mod_last_oldid AS last_oldid',
			'mod_ip AS ip',
			'mod_header_xff AS header_xff',
			'mod_header_ua AS header_ua',
			'mod_text AS text',
			'mod_merged_revid AS merged_revid',
			'mod_rejected AS rejected',
			'mod_stash_key AS stash_key',
			'mod_tags AS tags'
		];

		$fields = ModerationApprovableEntry::getFields();
		$this->assertEquals( $expectedFields, $fields );
	}
}
