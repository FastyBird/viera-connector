<?php declare(strict_types = 1);

/**
 * FindConnectorProperties.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Queries
 * @since          1.0.0
 *
 * @date           18.02.24
 */

namespace FastyBird\Connector\Viera\Queries\Entities;

use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Types;
use FastyBird\Module\Devices\Entities as DevicesEntities;
use FastyBird\Module\Devices\Queries as DevicesQueries;
use function sprintf;

/**
 * Find connector properties entities query
 *
 * @template T of DevicesEntities\Channels\Properties\Property
 * @extends  DevicesQueries\Entities\FindChannelProperties<T>
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Queries
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class FindChannelProperties extends DevicesQueries\Entities\FindChannelProperties
{

	/**
	 * @phpstan-param Types\ChannelPropertyIdentifier $identifier
	 *
	 * @throws Exceptions\InvalidArgument
	 */
	public function byIdentifier(Types\ChannelPropertyIdentifier|string $identifier): void
	{
		if (!$identifier instanceof Types\ChannelPropertyIdentifier) {
			throw new Exceptions\InvalidArgument(
				sprintf('Only instances of: %s are allowed', Types\ChannelPropertyIdentifier::class),
			);
		}

		parent::byIdentifier($identifier->value);
	}

}
