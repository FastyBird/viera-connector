<?php declare(strict_types = 1);

/**
 * Writer.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Writers
 * @since          1.0.0
 *
 * @date           21.06.23
 */

namespace FastyBird\Connector\Viera\Writers;

use FastyBird\Connector\Viera\Clients;
use FastyBird\Connector\Viera\Entities;

/**
 * Properties writer interface
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Writers
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
interface Writer
{

	public function connect(
		Entities\VieraConnector $connector,
		Clients\Client $client,
	): void;

	public function disconnect(
		Entities\VieraConnector $connector,
		Clients\Client $client,
	): void;

}
