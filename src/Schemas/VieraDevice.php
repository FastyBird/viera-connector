<?php declare(strict_types = 1);

/**
 * VieraDevice.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Schemas
 * @since          1.0.0
 *
 * @date           18.06.23
 */

namespace FastyBird\Connector\Viera\Schemas;

use FastyBird\Connector\Viera\Entities;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Schemas as DevicesSchemas;

/**
 * Viera connector entity schema
 *
 * @extends DevicesSchemas\Devices\Device<Entities\VieraDevice>
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Schemas
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class VieraDevice extends DevicesSchemas\Devices\Device
{

	/**
	 * Define entity schema type string
	 */
	public const SCHEMA_TYPE = MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA . '/device/' . Entities\VieraDevice::DEVICE_TYPE;

	public function getEntityClass(): string
	{
		return Entities\VieraDevice::class;
	}

	public function getType(): string
	{
		return self::SCHEMA_TYPE;
	}

}
