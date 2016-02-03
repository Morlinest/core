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


use OCP\Http\Client\IClientService;

class Notifications {

	const BASE_PATH_TO_SHARE_API = '/ocs/v1.php/cloud/shares';
	const RESPONSE_FORMAT = 'json'; // default response format for ocs calls

	/** @var AddressHandler */
	private $addressHandler;

	/** @var IClientService */
	private $httpClientService;

	/**
	 * Notifications constructor.
	 *
	 * @param AddressHandler $addressHandler
	 * @param IClientService $httpClientService
	 */
	public function __construct(
		AddressHandler $addressHandler,
		IClientService $httpClientService
	) {
		$this->addressHandler = $addressHandler;
		$this->httpClientService = $httpClientService;
	}

	/**
	 * send server-to-server share to remote server
	 *
	 * @param string $token
	 * @param string $shareWith
	 * @param string $name
	 * @param int $remote_id
	 * @param string $owner
	 * @return bool
	 */
	public function sendRemoteShare($token, $shareWith, $name, $remote_id, $owner) {

		list($user, $remote) = $this->addressHandler->splitUserRemote($shareWith);

		if ($user && $remote) {
			$url = $remote . self::BASE_PATH_TO_SHARE_API . '?format=' . self::RESPONSE_FORMAT;
			$local = $this->addressHandler->generateRemoteURL();

			$fields = array(
				'shareWith' => $user,
				'token' => $token,
				'name' => $name,
				'remoteId' => $remote_id,
				'owner' => $owner,
				'remote' => $local,
			);

			$url = $this->addressHandler->removeProtocolFromUrl($url);
			$result = $this->tryHttpPost($url, $fields);
			$status = json_decode($result['result'], true);

			if ($result['success'] && $status['ocs']['meta']['statuscode'] === 100) {
				\OC_Hook::emit('OCP\Share', 'federated_share_added', ['server' => $remote]);
				return true;
			}

		}

		return false;
	}

	/**
	 * send server-to-server unshare to remote server
	 *
	 * @param string $remote url
	 * @param int $id share id
	 * @param string $token
	 * @return bool
	 */
	public function sendRemoteUnShare($remote, $id, $token) {
		$url = rtrim($remote, '/') . self::BASE_PATH_TO_SHARE_API . '/' . $id . '/unshare?format=' . self::RESPONSE_FORMAT;
		$fields = array('token' => $token, 'format' => 'json');
		$url = $this->addressHandler->removeProtocolFromUrl($url);
		$result = $this->tryHttpPost($url, $fields);
		$status = json_decode($result['result'], true);

		return ($result['success'] && $status['ocs']['meta']['statuscode'] === 100);
	}

	/**
	 * try http post first with https and then with http as a fallback
	 *
	 * @param string $url
	 * @param array $fields post parameters
	 * @return array
	 */
	private function tryHttpPost($url, $fields) {
		$client = $this->httpClientService->newClient();
		$protocol = 'https://';
		$result = [
			'success' => false,
			'result' => '',
		];
		$try = 0;
		while ($result['success'] === false && $try < 2) {
			$result = $client->post($protocol . $url, $fields);
			$try++;
			$protocol = 'http://';
		}

		return $result;
	}

}
