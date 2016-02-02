<?php
/**
 * @author Björn Schießle <schiessle@owncloud.com>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\FederatedFileSharing;

use OC\Share20\Share;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\ILogger;
use OCP\Share\IShare;
use OCP\Share\IShareProvider;
use OC\Share20\Exception\InvalidShare;
use OC\Share20\Exception\ProviderException;
use OC\Share20\Exception\ShareNotFound;
use OC\Share20\Exception\BackendError;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IDBConnection;
use OCP\Files\Node;

class FederatedShareProvider implements IShareProvider {

	const SHARE_TYPE_REMOTE = 6;

	/** @var IDBConnection */
	private $dbConnection;

	/** @var AddressHandler */
	private $addressHandler;

	/** @var Notifications */
	private $notifications;

	/** @var TokenHandler */
	private $tokenHandler;

	/** @var IUser */
	private $user;

	/** @var IL10N */
	private $l;

	/** @var ILogger */
	private $logger;

	/** @var IRootFolder */
	private $rootFolder;

	/**
	 * DefaultShareProvider constructor.
	 *
	 * @param IDBConnection $connection
	 * @param AddressHandler $addressHandler
	 * @param Notifications $notifications
	 * @param TokenHandler $tokenHandler
	 * @param IUser $user
	 * @param IL10N $l10n
	 * @param ILogger $logger
	 * @param IRootFolder $rootFolder
	 */
	public function __construct(
			IDBConnection $connection,
			AddressHandler $addressHandler,
			Notifications $notifications,
			TokenHandler $tokenHandler,
			IUser $user,
			IL10N $l10n,
			ILogger $logger,
			IRootFolder $rootFolder
	) {
		$this->dbConnection = $connection;
		$this->addressHandler = $addressHandler;
		$this->notifications = $notifications;
		$this->tokenHandler = $tokenHandler;
		$this->user = $user;
		$this->l = $l10n;
		$this->logger = $logger;
		$this->rootFolder = $rootFolder;
	}

	/**
	 * Return the identifier of this provider.
	 *
	 * @return string Containing only [a-zA-Z0-9]
	 */
	public function identifier() {
		return 'ocFederatedSharing';
	}

	/**
	 * Share a path
	 *
	 * @param IShare $share
	 * @return IShare The share object
	 * @throws ShareNotFound
	 * @throws \Exception
	 */
	public function create(IShare $share) {

		$shareWith = $share->getSharedWith();
		$itemSource = $share->getNode()->getId();
		$uidOwner = $share->getShareOwner();
		$permissions = $share->getPermissions();

		/*
		 * Check if file is not already shared with the remote user
		 */
		$alreadyShared = $this->getSharesBy($this->user, self::SHARE_TYPE_REMOTE, $share->getNode(), true, 1, 0);
		if (!empty($alreadyShared)) {
			$message = 'Sharing %s failed, because this item is already shared with %s';
			$message_t = $this->l->t('Sharing %s failed, because this item is already shared with %s', array($share->getNode()->getName(), $shareWith));
			$this->logger->debug(sprintf($message, $share->getNode()->getName(), $shareWith), ['app' => 'Federated File Sharing']);
			throw new \Exception($message_t);
		}


		// don't allow federated shares if source and target server are the same
		list($user, $remote) = $this->addressHandler->splitUserRemote($shareWith);
		$currentServer = $this->addressHandler->generateRemoteURL();
		$currentUser = $this->user->getUID();
		if ($this->addressHandler->compareAddresses($user, $remote, $currentUser, $currentServer)) {
			$message = 'Not allowed to create a federated share with the same user.';
			$message_t = $this->l->t('Not allowed to create a federated share with the same user');
			$this->logger->debug($message, ['app' => 'Federated File Sharing']);
			throw new \Exception($message_t);
		}

		$token = $this->tokenHandler->generateToken();

		$shareWith = $user . '@' . $remote;

		$shareId = $this->addShareToDB($itemSource, $shareWith, $uidOwner, $permissions, $token);

		$send = $this->notifications->sendRemoteShare(
			$token,
			$shareWith,
			$share->getNode()->getName(),
			$shareId,
			$share->getSharedBy()->getUID()
		);

		$data = $this->getRawShare($shareId);
		$share = $this->createShare($data);

		if ($send === false) {
			$this->delete($share);
			$message_t = $this->l->t('Sharing %s failed, could not find %s, maybe the server is currently unreachable.',
				[$share->getNode()->getName(), $shareWith]);
			throw new \Exception($message_t);
		}

		return $share;
	}

	/**
	 * add share to the database and return the ID
	 *
	 * @param int $itemSource
	 * @param string $shareWith
	 * @param string $uidOwner
	 * @param int $permissions
	 * @param string $token
	 * @return int
	 */
	private function addShareToDB($itemSource, $shareWith, $uidOwner, $permissions, $token) {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->insert('share')
			->setValue('share_type', $qb->createNamedParameter(self::SHARE_TYPE_REMOTE))
			->setValue('item_type', $qb->createNamedParameter('file'))
			->setValue('item_source', $qb->createNamedParameter($itemSource))
			->setValue('share_with', $qb->createNamedParameter($shareWith))
			->setValue('uid_owner', $qb->createNamedParameter($uidOwner))
			->setValue('uid_initiator', $qb->createNamedParameter($this->user->getUID()))
			->setValue('permissions', $qb->createNamedParameter($permissions))
			->setValue('token', $qb->createNamedParameter($token));

		$qb->execute();
		$id = $qb->getLastInsertId();

		return (int)$id;
	}

	/**
	 * Update a share
	 *
	 * @param IShare $share
	 * @return IShare The share object
	 */
	public function update(IShare $share) {
		/*
		 * We allow updating the permissions of federated shares
		 */
		$qb = $this->dbConnection->getQueryBuilder();
			$qb->update('share')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($share->getId())))
				->set('permissions', $qb->createNamedParameter($share->getPermissions()))
				->execute();

		return $share;
	}

	/**
	 * Get all children of this share
	 *
	 * @param IShare $parent
	 * @return IShare[]
	 */
	public function getChildren(IShare $parent) {
		$children = [];

		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select('*')
			->from('share')
			->where($qb->expr()->eq('parent', $qb->createNamedParameter($parent->getId())))
			->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(self::SHARE_TYPE_REMOTE)))
			->orderBy('id');

		$cursor = $qb->execute();
		while($data = $cursor->fetch()) {
			$children[] = $this->createShare($data);
		}
		$cursor->closeCursor();

		return $children;
	}

	/**
	 * Delete a share
	 *
	 * @param IShare $share
	 */
	public function delete(IShare $share) {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->delete('share')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($share->getId())));
		$qb->execute();

		list(, $remote) = $this->addressHandler->splitUserRemote($share->getSharedWith());
		$this->notifications->sendRemoteUnShare($remote, $share->getId(), $share->getToken());
	}

	/**
	 * Unshare a share from the recipient. If this is a group share
	 * this means we need a special entry in the share db.
	 *
	 * @param IShare $share
	 * @param IUser $recipient
	 * @throws BackendError
	 * @throws ProviderException
	 */
	public function deleteFromSelf(IShare $share, IUser $recipient) {
		// nothing to do here. Technically deleteFromSelf in the context of federated
		// shares is a umount of a external storage. This is handled here
		// apps/files_sharing/lib/external/manager.php
		// TODO move this code over to this app
		return;
	}

	/**
	 * Get all shares by the given user. Sharetype and path can be used to filter.
	 *
	 * @param IUser $user
	 * @param int $shareType
	 * @param \OCP\Files\File|\OCP\Files\Folder $node
	 * @param bool $reshares Also get the shares where $user is the owner instead of just the shares where $user is the initiator
	 * @param int $limit The maximum number of shares to be returned, -1 for all shares
	 * @param int $offset
	 * @return IShare[]
	 */
	public function getSharesBy(IUser $user, $shareType, $node, $reshares, $limit, $offset) {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select('*')
			->from('share');

		$qb->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(self::SHARE_TYPE_REMOTE)));

		/**
		 * Reshares for this user are shares where they are the owner.
		 */
		if ($reshares === false) {
			//Special case for old shares created via the web UI
			$or1 = $qb->expr()->andX(
				$qb->expr()->eq('uid_owner', $qb->createNamedParameter($user->getUID())),
				$qb->expr()->isNull('uid_initiator')
			);

			$qb->andWhere(
				$qb->expr()->orX(
					$qb->expr()->eq('uid_initiator', $qb->createNamedParameter($user->getUID())),
					$or1
				)
			);
		} else {
			$qb->andWhere(
				$qb->expr()->orX(
					$qb->expr()->eq('uid_owner', $qb->createNamedParameter($user->getUID())),
					$qb->expr()->eq('uid_initiator', $qb->createNamedParameter($user->getUID()))
				)
			);
		}

		if ($node !== null) {
			$qb->andWhere($qb->expr()->eq('file_source', $qb->createNamedParameter($node->getId())));
		}

		if ($limit !== -1) {
			$qb->setMaxResults($limit);
		}

		$qb->setFirstResult($offset);
		$qb->orderBy('id');

		$cursor = $qb->execute();
		$shares = [];
		while($data = $cursor->fetch()) {
			$shares[] = $this->createShare($data);
		}
		$cursor->closeCursor();

		return $shares;
	}

	/**
	 * Get share by id
	 *
	 * @param int $id
	 * @return IShare
	 * @throws ShareNotFound
	 */
	public function getShareById($id) {
		$qb = $this->dbConnection->getQueryBuilder();

		$qb->select('*')
			->from('share')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(self::SHARE_TYPE_REMOTE)));
		
		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ShareNotFound();
		}

		try {
			$share = $this->createShare($data);
		} catch (InvalidShare $e) {
			throw new ShareNotFound();
		}

		return $share;
	}

	/**
	 * Get shares for a given path
	 *
	 * @param \OCP\Files\Node $path
	 * @return IShare[]
	 */
	public function getSharesByPath(Node $path) {
		$qb = $this->dbConnection->getQueryBuilder();

		$cursor = $qb->select('*')
			->from('share')
			->andWhere($qb->expr()->eq('file_source', $qb->createNamedParameter($path->getId())))
			->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(self::SHARE_TYPE_REMOTE)))
			->execute();

		$shares = [];
		while($data = $cursor->fetch()) {
			$shares[] = $this->createShare($data);
		}
		$cursor->closeCursor();

		return $shares;
	}

	/**
	 * @inheritdoc
	 */
	public function getSharedWith(IUser $user, $shareType, $node, $limit, $offset) {
		/** @var IShare[] $shares */
		$shares = [];

		//Get shares directly with this user
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select('*')
			->from('share');

		// Order by id
		$qb->orderBy('id');

		// Set limit and offset
		if ($limit !== -1) {
			$qb->setMaxResults($limit);
		}
		$qb->setFirstResult($offset);

		$qb->where($qb->expr()->eq('share_type', $qb->createNamedParameter(self::SHARE_TYPE_REMOTE)));
		$qb->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($user->getUID())));

		// Filter by node if provided
		if ($node !== null) {
			$qb->andWhere($qb->expr()->eq('file_source', $qb->createNamedParameter($node->getId())));
		}

		$cursor = $qb->execute();

		while($data = $cursor->fetch()) {
			$shares[] = $this->createShare($data);
		}
		$cursor->closeCursor();


		return $shares;
	}

	/**
	 * Get a share by token
	 *
	 * @param string $token
	 * @return IShare
	 * @throws ShareNotFound
	 */
	public function getShareByToken($token) {
		$qb = $this->dbConnection->getQueryBuilder();

		$cursor = $qb->select('*')
			->from('share')
			->where($qb->expr()->eq('share_type', $qb->createNamedParameter(self::SHARE_TYPE_REMOTE)))
			->andWhere($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->execute();

		$data = $cursor->fetch();

		if ($data === false) {
			throw new ShareNotFound();
		}

		try {
			$share = $this->createShare($data);
		} catch (InvalidShare $e) {
			throw new ShareNotFound();
		}

		return $share;
	}

	/**
	 * get database row of a give share
	 *
	 * @param $id
	 * @return array
	 * @throws ShareNotFound
	 */
	private function getRawShare($id) {

		// Now fetch the inserted share and create a complete share object
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select('*')
			->from('share')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ShareNotFound;
		}

		return $data;
	}

	/**
	 * Create a share object from an database row
	 *
	 * @param array $data
	 * @return IShare
	 * @throws InvalidShare
	 * @throws ShareNotFound
	 */
	private function createShare($data) {

		$share = new Share();
		$share->setId((int)$data['id'])
			->setShareType((int)$data['share_type'])
			->setPermissions((int)$data['permissions'])
			->setTarget($data['file_target'])
			->setMailSend((bool)$data['mail_send']);

		$shareTime = new \DateTime();
		$shareTime->setTimestamp((int)$data['stime']);
		$share->setShareTime($shareTime);
		$share->setSharedWith($data['share_with']);
		$share->setShareOwner($data['uid_owner']);
		$share->setSharedBy($data['uid_initiator']);

		$path = $this->getNode($share->getShareOwner(), (int)$data['file_source']);
		$share->setNode($path);

		$share->setProviderId($this->identifier());

		return $share;
	}

	/**
	 * Get the node with file $id for $user
	 *
	 * @param IUser $user
	 * @param int $id
	 * @return \OCP\Files\File|\OCP\Files\Folder
	 * @throws InvalidShare
	 */
	private function getNode(IUser $user, $id) {
		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		} catch (NotFoundException $e) {
			throw new InvalidShare();
		}

		$nodes = $userFolder->getById($id);

		if (empty($nodes)) {
			throw new InvalidShare();
		}

		return $nodes[0];
	}

}
