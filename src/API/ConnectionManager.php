<?php declare(strict_types = 1);

/**
 * ConnectionManager.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 * @since          1.0.0
 *
 * @date           01.07.23
 */

namespace FastyBird\Connector\Viera\API;

use FastyBird\Connector\Viera\Documents;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Library\Metadata\Exceptions as MetadataExceptions;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use Nette;
use Throwable;
use TypeError;
use ValueError;
use function array_key_exists;
use function assert;
use function is_string;

/**
 * API connections manager
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class ConnectionManager
{

	use Nette\SmartObject;

	/** @var array<string, TelevisionApi> */
	private array $connections = [];

	public function __construct(
		private readonly TelevisionApiFactory $televisionApiFactory,
		private readonly Helpers\Device $deviceHelper,
	)
	{
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws TypeError
	 * @throws ValueError
	 */
	public function getConnection(Documents\Devices\Device $device): TelevisionApi
	{
		if (!array_key_exists($device->getId()->toString(), $this->connections)) {
			assert(is_string($this->deviceHelper->getIpAddress($device)));

			$connection = $this->televisionApiFactory->create(
				$device->getIdentifier(),
				$this->deviceHelper->getIpAddress($device),
				$this->deviceHelper->getPort($device),
				$this->deviceHelper->getAppId($device),
				$this->deviceHelper->getEncryptionKey($device),
				$this->deviceHelper->getMacAddress($device),
			);

			$this->connections[$device->getId()->toString()] = $connection;
		}

		return $this->connections[$device->getId()->toString()];
	}

	public function __destruct()
	{
		foreach ($this->connections as $key => $client) {
			try {
				$client->disconnect();
			} catch (Throwable) {
				// Just ignore
			}

			unset($this->connections[$key]);
		}
	}

}
