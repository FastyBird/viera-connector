<?php declare(strict_types = 1);

/**
 * Connector.php
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

namespace FastyBird\Connector\Viera\Schemas\Connectors;

use FastyBird\Connector\Viera\Entities;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Schemas as DevicesSchemas;

/**
 * Viera connector entity schema
 *
 * @extends DevicesSchemas\Connectors\Connector<Entities\Connectors\Connector>
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Schemas
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class Connector extends DevicesSchemas\Connectors\Connector
{

	/**
	 * Define entity schema type string
	 */
	public const SCHEMA_TYPE = MetadataTypes\Sources\Connector::VIERA->value . '/connector/' . Entities\Connectors\Connector::TYPE;

	public function getEntityClass(): string
	{
		return Entities\Connectors\Connector::class;
	}

	public function getType(): string
	{
		return self::SCHEMA_TYPE;
	}

}
