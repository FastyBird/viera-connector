<?php declare(strict_types = 1);

/**
 * TelevisionApiFactory.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 * @since          1.0.0
 *
 * @date           18.06.23
 */

namespace FastyBird\Connector\Viera\API;

/**
 * Television API factory
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
interface TelevisionApiFactory
{

	public function create(
		string $identifier,
		string $ipAddress,
		int $port,
		string|null $appId = null,
		string|null $encryptionKey = null,
		string|null $macAddress = null,
	): TelevisionApi;

}
