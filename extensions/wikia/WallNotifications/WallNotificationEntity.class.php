<?php

use Wikia\Logger\WikiaLogger;

class WallNotificationEntity {
	/** @var int */
	public $id;

	/** @var stdClass data stored in memcache */
	public $data = null;

	public $parentTitleDbKey = '';
	public $msgText = '';
	public $threadTitleFull = '';

	/*
	 *	Public Interface
	 */

	/**
	 * Create a new object from an existing revision object.
	 *
	 * @param Revision $rev A revision object
	 * @param bool $useMasterDB Whether to query the MASTER DB on Title lookup.
	 *
	 * @return WallNotificationEntity
	 */
	public static function createFromRev( Revision $rev, $useMasterDB = false ) {
		$wn = new WallNotificationEntity();

		if ( $wn->loadDataFromRev( $rev, $useMasterDB ) ) {
			$wn->saveToCache();
			return $wn;
		}

		return null;
	}

	/**
	 * Create a new object from a Revision ID and a wikia ID.  If the wikia ID does not
	 * match the current wikia, this will make an external call to the wikia that
	 * owns the data.
	 *
	 * @param int $revId The revision ID for this notification entity
	 * @param int $wikiId The wiki where this revision exists
	 * @param bool $useMasterDB Whether to query the MASTER DB on Title lookup.
	 *
	 * @return WallNotificationEntity
	 */
	public static function createFromRevId( $revId, $wikiId, $useMasterDB = false ) {
		$wn = new WallNotificationEntity();

		if ( $wikiId == F::app()->wg->CityId ) {
			$success = $wn->loadDataFromRevId( $revId, $useMasterDB );
		} else {
			$success = $wn->loadDataFromRevIdOnWiki( $revId, $wikiId, $useMasterDB );
		}

		if ( $success ) {
			$wn->saveToCache();
			return $wn;
		}

		return null;
	}

	/**
	 * Create a new object from an entity ID.
	 *
	 * @param int $entityId An entity ID
	 * @param bool $useMasterDB Whether to query the MASTER DB on Title lookup.
	 *
	 * @return WallNotificationEntity
	 */
	public static function createFromId( $entityId, $useMasterDB = false ) {
		list( $revId, $wikiId ) = explode( '_', $entityId );

		return self::createFromRevId( $revId, $wikiId, $useMasterDB );
	}

	/**
	 * This method attempts to load data from the cache first before
	 *
	 * @param $id
	 *
	 * @return WallNotificationEntity
	 */
	public static function getById( $id ) {
		$key = self::getMemcKey( $id );
		$data = F::app()->wg->Memc->get( $key );

		if ( empty( $data ) ) {
			return self::createFromId( $id );
		} else {
			$wn = new WallNotificationEntity();
			$wn->id = $id;
			$wn->data = $data;

			return $wn;
		}
	}

	/**
<<<<<<< Updated upstream
	 * Tests whether this is a notification for a message that is a new thread topic.
	 *
	 * @return bool True if the message is a new topic, false if it is a reply to a thread topic
=======
	 * Returns true if this entity represents a forum topic and false if it represents
	 * a comment to a topic
	 *
	 * @return bool
>>>>>>> Stashed changes
	 */
	public function isMain() {
		return empty( $this->data->parent_id );
	}

	/**
	 * Tests whether this is a notification for a reply to a thread topic.
	 *
	 * @return bool True if the message is a *reply* to a thread topic, false if it is a new thread topic
	 */
	public function isReply() {
		return !$this->isMain();
	}

	public function getUniqueId() {
		if ( $this->isMain() ) {
			return $this->data->title_id;
		} else {
			return $this->data->parent_id;
		}
	}

	/**
	 * This method calls out to the wiki given by $wikiId to get revision data, since
	 * this data cannot be gathered locally if $wikiId != $wgCityId
	 *
	 * @param int $revId
	 * @param int $wikiId
	 *
	 * @return bool
	 */
	public function loadDataFromRevIdOnWiki( $revId, $wikiId, $useMasterDB = false ) {
		$dbName = WikiFactory::IDtoDB( $wikiId );
		$params = [
			'controller' => 'WallNotifications',
			'method' => 'getEntityData',
			'revId' => $revId,
			'useMasterDB' => $useMasterDB,
		];

		$response = ApiService::foreignCall( $dbName, $params, ApiService::WIKIA );
		if ( !empty( $response['status'] ) && $response['status'] == 'ok' ) {
			$this->parentTitleDbKey = $response['parentTitleDbKey'];
			$this->msgText = $response['msgText'];
			$this->threadTitleFull = ['threadTitleFull'];
			$this->data = ['data'];

			return true;
		}

		return false;
	}

	/**
	 * @param int $revId
	 * @param bool $userMasterDB
	 *
	 * @return bool
	 */
	public function loadDataFromRevId( $revId, $userMasterDB = false ) {
		$rev = Revision::newFromId( $revId );
		if ( empty( $rev ) ) {
			return false;
		}

		return $this->loadDataFromRev( $rev, $userMasterDB );
	}

	/**
	 * @param Revision $rev
	 * @param bool $useMasterDB
	 *
	 * @return bool
	 */
	public function loadDataFromRev( Revision $rev, $useMasterDB = false ) {
		// Reset any existing info stored in $this->data and start collecting in a new $data var
		$this->data = null;

		$data = new StdClass();
		$data->wiki = F::app()->wg->CityId;
		$data->wikiname = F::app()->wg->Sitename;

		$this->setMessageAuthorData( $data, $rev->getUser() );
		$this->id = $rev->getId() . '_' .  $data->wiki;
		$data->rev_id = $rev->getId();
		$data->timestamp = $rev->getTimestamp();

		// Set all data related to the WallMessage
		/* @var $wm WallMessage */
		$wm = WallMessage::newFromTitle( $rev->getTitle() );
		$wm->load();

		if ( !$this->setWallUserData( $data, $wm, $useMasterDB ) ) {
			return false;
		}
		$this->setArticleTitleData( $data, $wm );

		$this->msgText = $wm->getText();
		$data->parent_page_id = $wm->getArticleTitle()->getArticleId();
		$data->title_id = $wm->getTitle()->getArticleId();
		$data->url = $wm->getMessagePageUrl();

		$data->notifyeveryone = $wm->getNotifyeveryone();
		$data->reason = $wm->isEdited() ? $wm->getLastEditSummary() : '';

		$this->setMessageParentData( $data, $wm );

		$this->data = $data;

		return true;
	}

	private function setWallUserData( stdClass $data, WallMessage $wm, $useMasterDB ) {
		$wallUser = $wm->getWallOwner( $useMasterDB );

		if ( empty( $wallUser ) ) {
			WikiaLogger::instance()->error( 'Wall owner not found', [
				'method' => __METHOD__,
				'notificationEntityId' => $this->id,
			] );

			return false;
		}

		$data->wall_username = $wallUser->getName();
		$data->wall_userid = $wallUser->getId();
		$data->wall_displayname = $data->wall_username;

		return true;
	}

	private function setArticleTitleData( stdClass $data, WallMessage $wm ) {
		$wallTitle = $wm->getArticleTitle();
		if ( !empty( $wallTitle ) && $wallTitle->exists() ) {
			$data->article_title_ns = $wallTitle->getNamespace();
			$data->article_title_text = $wallTitle->getText();
			$data->article_title_dbkey = $wallTitle->getDBkey();
			$data->article_title_id = $wallTitle->getArticleId();
		} else {
			$data->article_title_ns = null;
			$data->article_title_text = null;
			$data->article_title_dbkey = null;
			$data->article_title_id = null;
		}
	}

	private function setMessageAuthorData( stdClass $data, $userID ) {
		$authorUser = User::newFromId( $userID );

		if ( $authorUser instanceof User ) {
			$data->msg_author_id = $authorUser->getId();
			$data->msg_author_username = $authorUser->getName();
			if ( $authorUser->getId() > 0 ) {
				$data->msg_author_displayname = $data->msg_author_username;
			} else {
				$data->msg_author_displayname = wfMessage( 'oasis-anon-user' )->text();
			}
		} else {
			// Treat a user we can't find as anon
			$data->msg_author_id = 0;
			$data->msg_author_displayname = wfMessage( 'oasis-anon-user' )->text();
		}
	}

	private function setMessageParentData( $data, WallMessage $wm ) {
		$acParent = $wm->getTopParentObj();

		if ( empty( $acParent ) ) {
			$this->threadTitleFull = $wm->getMetaTitle();

			$data->parent_id = null;
			$data->thread_title = $wm->getMetaTitle();
			$data->parent_username = $data->wall_username;
		} else {
			$acParent->load();
			$title = $acParent->getTitle();
			$this->parentTitleDbKey = $title->getDBkey();
			$this->threadTitleFull = $acParent->getMetaTitle();

			$this->setMessageParentUserData( $data, $acParent );
			$data->parent_id = $acParent->getId();
			$data->thread_title = $acParent->getMetaTitle();
		}
	}

	private function setMessageParentUserData( stdClass $data, WallMessage $parent ) {
		$parentUser = $parent->getUser();

		if ( $parentUser instanceof User ) {
			$data->parent_username = $parentUser->getName();
			$data->parent_user_id = $parentUser->getId();

			if ( $data->parent_user_id > 0 ) {
				$data->parent_displayname = $data->parent_username;
			} else {
				$data->parent_displayname = wfMessage( 'oasis-anon-user' )->text();
			}
		} else {
			/* parent was deleted and somehow reply stays in the system
			 * the only way I've reproduced it was: I deleted a thread
			 * then I went to Special:Log/delete and restored only its reply
			 * an edge case but it needs to be handled
			 * --nAndy
			 */
			$data->parent_username = wfMessage( 'oasis-anon-user' )->text();
			$data->parent_displayname = $data->parent_username;
			$data->parent_user_id = 0;
		}
	}

	public function saveToCache() {
		$cache = F::app()->wg->Memc;
		$key = self::getMemcKey( $this->id );

		$cache->set( $key, $this->data );
	}

	public static function getMemcKey( $id ) {
		return wfSharedMemcKey( __CLASS__, "v32", $id, 'notification' );
	}
}
