<?php declare(strict_types = 1);

/**
 * FindChannels.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Queries
 * @since          1.0.0
 *
 * @date           07.01.24
 */

namespace FastyBird\Connector\Viera\Queries\Entities;

use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Types;
use FastyBird\Module\Devices\Queries as DevicesQueries;
use function sprintf;

/**
 * Find device channels entities query
 *
 * @template T of Entities\Channels\Channel
 * @extends  DevicesQueries\Entities\FindChannels<T>
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Queries
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class FindChannels extends DevicesQueries\Entities\FindChannels
{

	/**
	 * @phpstan-param Types\ChannelType $identifier
	 *
	 * @throws Exceptions\InvalidArgument
	 */
	public function byIdentifier(Types\ChannelType|string $identifier): void
	{
		if (!$identifier instanceof Types\ChannelType) {
			throw new Exceptions\InvalidArgument(
				sprintf('Only instances of: %s are allowed', Types\ChannelType::class),
			);
		}

		parent::byIdentifier($identifier->value);
	}

}
