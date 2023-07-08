<?php declare(strict_types = 1);

/**
 * Viera.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Hydrators
 * @since          1.0.0
 *
 * @date           18.06.23
 */

namespace FastyBird\Connector\Viera\Hydrators;

use FastyBird\Connector\Viera\Entities;
use FastyBird\Module\Devices\Hydrators as DevicesHydrators;

/**
 * Viera connector entity hydrator
 *
 * @extends DevicesHydrators\Connectors\Connector<Entities\VieraConnector>
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Hydrators
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class VieraConnector extends DevicesHydrators\Connectors\Connector
{

	public function getEntityName(): string
	{
		return Entities\VieraConnector::class;
	}

}
