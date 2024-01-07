<?php declare(strict_types = 1);

/**
 * VieraChannel.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Schemas
 * @since          1.0.0
 *
 * @date           07.01.24
 */

namespace FastyBird\Connector\Viera\Schemas;

use FastyBird\Connector\Viera\Entities;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Schemas as DevicesSchemas;

/**
 * Viera device channel entity schema
 *
 * @extends DevicesSchemas\Channels\Channel<Entities\VieraChannel>
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Schemas
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class VieraChannel extends DevicesSchemas\Channels\Channel
{

	/**
	 * Define entity schema type string
	 */
	public const SCHEMA_TYPE = MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA . '/channel/' . Entities\VieraChannel::TYPE;

	public function getEntityClass(): string
	{
		return Entities\VieraChannel::class;
	}

	public function getType(): string
	{
		return self::SCHEMA_TYPE;
	}

}
