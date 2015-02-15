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
	@brief Implements modaction=approve(all) on [[Special:Moderation]].
*/

class ModerationActionApprove extends ModerationAction {

	public function execute() {
		if($this->actionName == 'approve')
			$this->executeApproveOne();
		else if($this->actionName == 'approveall')
			$this->executeApproveAll();
	}

	function prepareApproveHooks()
	{
		global $wgHooks;

		# Just a precaution: disable moderation hook (wfModerationPageContentSave)
		# in case moderator is for some reason not automoderated.
		$hook_key = array_search('wfModerationPageContentSave', $wgHooks['PageContentSave']);
		unset($wgHooks['PageContentSave'][$hook_key]);
	}

	public function executeApproveOne() {
		$out = $this->mSpecial->getOutput();
		$this->prepareApproveHooks();

		$status = $this->approveEditById($this->id);
		if(!$status->isGood())
			$out->addWikiText( $status->getWikiText() );
		else
			$out->addWikiMsg( 'moderation-approved-ok', 1 );
	}

	public function executeApproveAll() {
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
				'mod_rejected' => 0, # Previously rejected edits are not approved by "Approve all"
				'mod_conflict' => 0 # No previously detected conflicts (they need manual merging).
			),
			__METHOD__,
			array(
				# Images are approved first.
				# Otherwise the page can be rendered with the
				# image redlink, because the image didn't exist
				# when the edit to this page was approved.
				'ORDER BY' => 'mod_stash_key IS NULL',
				'USE INDEX' => 'moderation_approveall'
			)
		);
		if(!$res || $res->numRows() == 0)
		{
			$out->addWikiMsg( 'moderation-nothing-to-approveall' );
			return;
		}

		$this->prepareApproveHooks();

		$approved = 0;
		$failed = 0;
		foreach($res as $row)
		{
			$status = $this->approveEditById($row->id);

			if($status->isGood())
				$approved ++;
			else
				$failed ++;
		}

		if($approved)
		{
			$logEntry = new ManualLogEntry( 'moderation', 'approveall' );
			$logEntry->setPerformer( $this->moderator );
			$logEntry->setTarget( $userpage );
			$logEntry->setParameters(array('4::count' => $approved));
			$logid = $logEntry->insert();
			$logEntry->publish($logid);
		}

		$out->addWikiMsg( 'moderation-approved-ok', $approved );
		if($failed)
			$out->addWikiMsg( 'moderation-approved-errors', $failed );
	}

	function approveEditById($id)
	{
		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation',
			array(
				'mod_id AS id',
				'mod_timestamp AS timestamp',
				'mod_user AS user',
				'mod_user_text AS user_text',
				'mod_cur_id AS cur_id',
				'mod_namespace AS namespace',
				'mod_title AS title',
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
				'mod_stash_key AS stash_key'
			),
			array( 'mod_id' => $id ),
			__METHOD__
		);
		if(!$row)
			return Status::newFatal('moderation-show-not-found');

		if($row->merged_revid)
			return Status::newFatal('moderation-already-merged');

		if($row->rejected && $row->timestamp < $this->mSpecial->earliestReapprovableTimestamp)
			return Status::newFatal('moderation-rejected-long-ago');

		# For CheckUser extension to work properly, IP, XFF and UA
		# should be set to the correct values for the original user
		# (not from the moderator)
		$cuHook = new ModerationCheckUserHook();
		$cuHook->install($row->ip, $row->header_xff, $row->header_ua);

		# Prepare everything
		$title = Title::makeTitle( $row->namespace, $row->title );
		$model = $title->getContentModel();

		$user = $row->user ?
			User::newFromId($row->user) :
			User::newFromName($row->user_text, false);

		$flags = EDIT_DEFER_UPDATES | EDIT_AUTOSUMMARY;
		if($row->bot && $user->isAllowed('bot'))
			$flags |= EDIT_FORCE_BOT;
		if($row->minor) # doEditContent() checks the right
			$flags |= EDIT_MINOR;

		$status = Status::newGood();

		if($row->stash_key)
		{
			# This is the upload from stash.

			$stash = RepoGroup::singleton()->getLocalRepo()->getUploadStash($user);
			try {
				$file = $stash->getFile($row->stash_key);
			} catch(MWException $e) {
				return Status::newFatal('moderation-missing-stashed-image');
			}

			$upload = new UploadFromStash($user, $stash);
			$upload->initialize($row->stash_key, $title->getText());
			$status = $upload->performUpload($row->comment, $row->text, 0, $user);
		}
		else
		{
			# This is normal edit (not an upload).
			$new_content = ContentHandler::makeContent( $row->text, null, $model );

			$page = new WikiPage($title);
			if(!$page->exists())
			{
				# New page
				$status = $page->doEditContent(
					$new_content,
					$row->comment,
					$flags,
					false,
					$user
				);
			}
			else
			{
				# Existing page
				$latest = $page->getLatest();

				if($latest == $row->last_oldid)
				{
					# Page hasn't changed since this edit was queued for moderation.
					$status = $page->doEditContent(
						$new_content,
						$row->comment,
						$flags,
						$row->last_oldid,
						$user
					);
				}
				else
				{
					# Page has changed!
					# Let's attempt merging, as MediaWiki does in private EditPage::mergeChangesIntoContent().

					$base_content = $row->last_oldid ?
						Revision::newFromId($row->last_oldid)->getContent(Revision::RAW) :
						ContentHandler::makeContent('', null, $model);

					$latest_content = Revision::newFromId($latest)->getContent(Revision::RAW);

					$handler = ContentHandler::getForModelID( $base_content->getModel() );
					$merged_content = $handler->merge3( $base_content, $new_content, $latest_content );

					if($merged_content)
					{
						$status = $page->doEditContent(
							$merged_content,
							$row->comment,
							$flags,
							$latest, # Because $merged_content goes after $latest
							$user
						);
					}
					else
					{
						$status = Status::newFatal('moderation-edit-conflict');

						$dbw = wfGetDB( DB_MASTER );
						$dbw->update( 'moderation',
							array( 'mod_conflict' => 1 ),
							array( 'mod_id' => $id ),
							__METHOD__
						);
					}
				}
			}
		}
		$cuHook->deinstall();

		if($status->isGood())
		{
			# $status from doEditContent() stores revision;
			# $status from performUpload() does not.
			$rev = $row->stash_key ? false : $status->value['revision'];

			if($rev)
			{
				$revid = $rev->getId();
			}
			else
			{
				$page = new WikiPage($title);
				$page->loadPageData('fromdbmaster');
				$revid = $page->getLatest();
			}

			$logEntry = new ManualLogEntry( 'moderation', 'approve' );
			$logEntry->setPerformer( $this->moderator );
			$logEntry->setTarget( $title );
			$logEntry->setParameters(array('revid' => $revid));
			$logid = $logEntry->insert();
			$logEntry->publish($logid);

			# Approved edits are removed from "moderation" table,
			# because they already exist in page history, recentchanges etc.

			$dbw = wfGetDB( DB_MASTER );
			$dbw->delete( 'moderation', array( 'mod_id' => $id ), __METHOD__ );

			# Here we set the timestamp of this edit to $row->timestamp
			# (this is needed because doEditContent() always uses current timestamp).
			#
			# NOTE: timestamp in recentchanges table is not updated on purpose:
			# users would want to see new edits as they appear,
			# without the edits surprisingly appearing somewhere in the past.

			$dbw->update( 'revision',
				array(
					'rev_timestamp' => $dbw->timestamp($row->timestamp),

					#
					# performUpload() mistakenly tags image reuploads as made by moderator (rather than $user).
					# Let's fix this here.
					#
					'rev_user' => $user->getId(),
					'rev_user_text' => $user->getName()
				),
				array('rev_id' => $revid),
				__METHOD__
			);
		}
		return $status;
	}
}
