<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2015 Edward Chernenko.

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
	@file
	@brief Implements modaction=reject(all) on [[Special:Moderation]].
*/

class ModerationActionReject extends ModerationAction {

	public function execute() {
		if($this->actionName == 'reject')
			$this->executeRejectOne();
		else if($this->actionName == 'rejectall')
			$this->executeRejectAll();
	}

	public function executeRejectOne() {
		$out = $this->mSpecial->getOutput();

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation',
			array(
				'mod_namespace AS namespace',
				'mod_title AS title',
				'mod_user AS user',
				'mod_user_text AS user_text',
				'mod_merged_revid AS merged_revid'
			),
			array( 'mod_id' => $this->id ),
			__METHOD__
		);
		if(!$row)
		{
			$out->addWikiMsg( 'moderation-edit-not-found' );
			return;
		}
		if($row->merged_revid)
		{
			$out->addWikiMsg( 'moderation-already-merged' );
			return;
		}

		$dbw->update( 'moderation',
			array(
				'mod_rejected' => 1,
				'mod_rejected_by_user' => $this->moderator->getId(),
				'mod_rejected_by_user_text' => $this->moderator->getName(),
				'mod_preloadable' => 0
			),
			array( 'mod_id' => $this->id, 'mod_merged_revid' => 0 ),
			__METHOD__
		);

		$nrows = $dbw->affectedRows();
		$out->addWikiMsg( $nrows ? 'moderation-rejected-ok' : 'moderation-edit-not-found', $nrows);

		if($nrows)
		{
			$title = Title::makeTitle( $row->namespace, $row->title );

			$logEntry = new ManualLogEntry( 'moderation', 'reject' );
			$logEntry->setPerformer( $this->moderator );
			$logEntry->setTarget( $title );
			$logEntry->setParameters(array('modid' => $this->id, 'user' => $row->user, 'user_text' => $row->user_text));
			$logid = $logEntry->insert();
			$logEntry->publish($logid);
		}
	}

	public function executeRejectAll() {
		$out = $this->mSpecial->getOutput();

		$userpage = $this->mSpecial->getUserpageByModId($this->id);
		if(!$userpage)
		{
			$out->addWikiMsg( 'moderation-edit-not-found' );
			return;
		}

		$dbw = wfGetDB( DB_MASTER ); # Need latest data without lag
		$res = $dbw->select('moderation',
			array('mod_id AS id'),
			array(
				'mod_user_text' => $userpage->getText(),
				'mod_rejected' => 0,
				'mod_merged_revid' => 0
			),
			__METHOD__,
			array('USE INDEX' => 'moderation_rejectall')
		);
		if(!$res || $res->numRows() == 0)
		{
			$out->addWikiMsg( 'moderation-nothing-to-rejectall' );
			return;
		}

		$ids = array();
		foreach($res as $row)
			$ids[] = $row->id;

		$dbw->update('moderation',
			array(
				'mod_rejected' => 1,
				'mod_rejected_by_user' => $this->moderator->getId(),
				'mod_rejected_by_user_text' => $this->moderator->getName(),
				'mod_rejected_batch' => 1,
				'mod_preloadable' => 0
			),
			array(
				'mod_id' => $ids
			),
			__METHOD__
		);

		$nrows = $dbw->affectedRows();
		if($nrows)
		{
			$logEntry = new ManualLogEntry( 'moderation', 'rejectall' );
			$logEntry->setPerformer( $this->moderator );
			$logEntry->setTarget( $userpage );
			$logEntry->setParameters(array('4::count' => $nrows));
			$logid = $logEntry->insert();
			$logEntry->publish($logid);
		}

		$out->addWikiMsg('moderation-rejected-ok', $nrows);
	}
}
