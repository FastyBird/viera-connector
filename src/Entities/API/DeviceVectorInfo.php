<?php declare(strict_types = 1);

/**
 * DeviceVectorInfo.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 * @since          1.0.0
 *
 * @date           27.06.23
 */

namespace FastyBird\Connector\Viera\Entities\API;

use Orisai\ObjectMapper;

/**
 * Device vector info entity
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class DeviceVectorInfo implements Entity
{

	public function __construct(
		#[ObjectMapper\Rules\IntValue(unsigned: true)]
		private readonly int $port,
	)
	{
	}

	public function getPort(): int
	{
		return $this->port;
	}

	/**
	 * {@inheritDoc}
	 */
	public function toArray(): array
	{
		return [
			'port' => $this->getPort(),
		];
	}

}
