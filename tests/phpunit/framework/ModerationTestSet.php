<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018 Edward Chernenko.

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
 * @brief Parent class for TestSet objects used in the Moderation testsuite.
 */

abstract class ModerationTestsuiteTestSet {
	/** @var ModerationTestsuite */
	private $testsuite;

	/** @var MediaWikiTestCase */
	private $testcase;

	/** @brief Returns ModerationTestsuite object. */
	protected function getTestsuite() {
		return $this->testsuite;
	}

	/**
	 * @brief Returns current MediaWikiTestCase object.
	 * Used for calling assert*() methods.
	 */
	protected function getTestcase() {
		return $this->testcase;
	}

	/**
	 * @brief Run this TestSet from input of dataProvider.
	 * @param array $options Parameters of test, e.g. [ 'user' => '...', 'title' => '...' ].
	 * @param MediaWikiTestCase $testcase
	 */
	final public static function run( array $options, MediaWikiTestCase $testcase ) {
		$set = new static( $options, $testcase );

		$set->makeChanges();
		$set->assertResults( $testcase );
	}

	/**
	 * @brief Construct TestSet from the input of dataProvider.
	 */
	final protected function __construct( array $options, MediaWikiTestCase $testcase ) {
		$this->testsuite = new ModerationTestsuite; // Cleans the database
		$this->testcase = $testcase;

		$this->applyOptions( $options );
	}

	/*-------------------------------------------------------------------*/

	/**
	 * @brief Initialize this TestSet from the input of dataProvider.
	 */
	abstract protected function applyOptions( array $options );

	/**
	 * @brief Execute this TestSet, making the edit with requested parameters.
	 */
	abstract protected function makeChanges();

	/**
	 * @brief Assert whether the situation after the edit is correct or not.
	 */
	abstract protected function assertResults( MediaWikiTestCase $testcase );

	/*-------------------------------------------------------------------*/

	/**
	 * @brief Assert that recent row in 'moderation' SQL table consists of $expectedFields.
	 * @param array $expectedFields Key-value list of all mod_* fields.
	 * @throws AssertionFailedError
	 * @return stdClass $row
	 */
	protected function assertRowEquals( array $expectedFields ) {
		$testcase = $this->getTestcase();

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation', '*', '', __METHOD__ );

		foreach ( $expectedFields as $key => $val ) {
			if ( $val instanceof ModerationTestSetRegex ) {
				$testcase->assertRegExp( $val->regex, $row->$key, "Field $key doesn't match regex" );
			} else {
				$testcase->assertEquals( $val, $row->$key, "Field $key doesn't match expected" );
			}
		}
		return $row;
	}
}

/**
 * @brief Regular expression that can be used in assertRowEquals() as values of $expectedFields.
 */
class ModerationTestSetRegex {
	public $regex;

	public function __construct( $regex ) {
		$this->regex = $regex;
	}
}
