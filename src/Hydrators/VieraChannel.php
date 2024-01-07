<?php declare(strict_types = 1);

/**
 * VieraChannel.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Hydrators
 * @since          1.0.0
 *
 * @date           07.01.24
 */

namespace FastyBird\Connector\Viera\Hydrators;

use FastyBird\Connector\Viera\Entities;
use FastyBird\Module\Devices\Hydrators as DevicesHydrators;

/**
 * Viera channel entity hydrator
 *
 * @extends DevicesHydrators\Channels\Channel<Entities\VieraChannel>
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Hydrators
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class VieraChannel extends DevicesHydrators\Channels\Channel
{

	public function getEntityName(): string
	{
		return Entities\VieraChannel::class;
	}

}
