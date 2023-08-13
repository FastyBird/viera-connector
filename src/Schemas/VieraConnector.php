<?php declare(strict_types = 1);

/**
 * VieraConnector.php
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
 * @extends DevicesSchemas\Connectors\Connector<Entities\VieraConnector>
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Schemas
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class VieraConnector extends DevicesSchemas\Connectors\Connector
{

	/**
	 * Define entity schema type string
	 */
	public const SCHEMA_TYPE = MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA . '/connector/' . Entities\VieraConnector::TYPE;

	public function getEntityClass(): string
	{
		return Entities\VieraConnector::class;
	}

	public function getType(): string
	{
		return self::SCHEMA_TYPE;
	}

}
