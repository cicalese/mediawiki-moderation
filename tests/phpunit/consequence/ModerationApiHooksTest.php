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
 * Unit test of ModerationApiHooks.
 */

use MediaWiki\Moderation\PendingEdit;

require_once __DIR__ . "/autoload.php";

class ModerationApiHooksTest extends ModerationUnitTestCase {
	/**
	 * Ensure that ApiEdit parameters appendtext, prependtext and section work for intercepted edits.
	 * @dataProvider dataProviderApiBeforeMain
	 * @covers ModerationApiHooks
	 */
	public function testApiBeforeMain( array $opt ) {
		$title = Title::newFromText( "Talk:UTPage " . rand( 0, 100000 ) );
		$defaultParams = [ 'action' => 'edit', 'title' => $title->getFullText() ];

		$inputParams = $opt['inputParams'] + $defaultParams;
		$expectedParams = ( $opt['expectedParams'] ?? $opt['inputParams'] ) + $defaultParams;

		// Mock findPendingEdit() in the Moderation.Preload service.
		$pendingText = $opt['pendingText'] ?? null;
		if ( $pendingText ) {
			$pendingEdit = $this->createMock( PendingEdit::class );
			$pendingEdit->expects( $this->any() )->method( 'getText' )->willReturn( $pendingText );
		} else {
			$pendingEdit = false;
		}

		$preload = $this->createMock( ModerationPreload::class );
		$preload->expects( $this->any() )->method( 'findPendingEdit' )->will( $this->returnCallback(
			function ( Title $lookupTitle ) use ( $title, $pendingEdit ) {
				$this->assertSame( $title->getFullText(), $lookupTitle->getFullText() );
				return $pendingEdit;
			}
		) );
		$this->setService( 'Moderation.Preload', $preload );

		// Prepare ApiMain object with input parameters.
		$context = new RequestContext;
		$context->setRequest( new FauxRequest( $inputParams ) );
		$context->setTitle( Title::makeTitle( NS_SPECIAL, 'Badtitle/dummy title for API calls' ) );

		$processor = new ApiMain( $context, true );
		$hookResult = Hooks::run( 'ApiBeforeMain', [ &$processor ] );

		$this->assertTrue( $hookResult, 'Handler of ApiBeforeMain hook should return true.' );
		$this->assertArrayEquals( $expectedParams, $processor->getRequest()->getValues(), false, true );
	}

	/**
	 * Provide datasets for testApiBeforeMain() runs.
	 * @return array
	 */
	public function dataProviderApiBeforeMain() {
		return [
			'not action=edit' => [ [
				'inputParams' => [ 'action' => 'query' ]
			] ],
			'no section, no prependtext, no appendtext' => [ [
				'inputParams' => []
			] ],
			'has section=, but no pending edit' => [ [
				'inputParams' => [ 'section' => '123' ]
			] ],
			'has appendtext=, but no pending edit' => [ [
				'inputParams' => [ 'appendtext' => '123' ]
			] ],
			'has prependtext=, but no pending edit' => [ [
				'inputParams' => [ 'prependtext' => '123' ]
			] ],
			'has appendtext= and pending edit' => [ [
				'inputParams' => [ 'appendtext' => 'Cats' ],
				'pendingText' => 'Dogs',
				'expectedParams' => [ 'text' => 'DogsCats' ]
			] ],
			'has prependtext= and pending edit' => [ [
				'inputParams' => [ 'prependtext' => 'Foxes' ],
				'pendingText' => 'Dogs',
				'expectedParams' => [ 'text' => 'FoxesDogs' ]
			] ],
			'has appendtext=, prependtext= and pending edit' => [ [
				'inputParams' => [ 'prependtext' => 'Foxes', 'appendtext' => 'Cats' ],
				'pendingText' => 'Dogs',
				'expectedParams' => [ 'text' => 'FoxesDogsCats' ]
			] ],
		];
	}
}
