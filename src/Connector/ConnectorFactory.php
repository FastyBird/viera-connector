<?php declare(strict_types = 1);

/**
 * ConnectorFactory.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Connector
 * @since          1.0.0
 *
 * @date           21.06.23
 */

namespace FastyBird\Connector\Viera\Connector;

use FastyBird\Connector\Viera\Connector;
use FastyBird\Module\Devices\Connectors as DevicesConnectors;
use FastyBird\Module\Devices\Documents as DevicesDocuments;

/**
 * Connector service executor factory
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Connector
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
interface ConnectorFactory extends DevicesConnectors\ConnectorFactory
{

	public function create(
		DevicesDocuments\Connectors\Connector $connector,
	): Connector\Connector;

}
