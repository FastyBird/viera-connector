<?php declare(strict_types = 1);

/**
 * Client.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Clients
 * @since          1.0.0
 *
 * @date           21.06.23
 */

namespace FastyBird\Connector\Viera\Clients;

use FastyBird\Connector\Viera\Entities;
use FastyBird\Library\Metadata\Entities as MetadataEntities;
use FastyBird\Module\Devices\Entities as DevicesEntities;
use React\Promise;

/**
 * Base device client interface
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Clients
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
interface Client
{

	/**
	 * Create servers/clients
	 */
	public function connect(): void;

	/**
	 * Destroy servers/clients
	 */
	public function disconnect(): void;

	/**
	 * Write thing parameter mapped as channel
	 */
	public function writeChannelProperty(
		Entities\VieraDevice $device,
		DevicesEntities\Channels\Channel $channel,
		DevicesEntities\Channels\Properties\Dynamic|MetadataEntities\DevicesModule\ChannelDynamicProperty $property,
	): Promise\PromiseInterface;

}
