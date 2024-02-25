<?php declare(strict_types = 1);

/**
 * DeviceVectorInfo.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 * @since          1.0.0
 *
 * @date           27.06.23
 */

namespace FastyBird\Connector\Viera\API\Messages\Response;

use FastyBird\Connector\Viera\API;
use Orisai\ObjectMapper;

/**
 * Device vector info message
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
readonly class DeviceVectorInfo implements API\Messages\Message
{

	public function __construct(
		#[ObjectMapper\Rules\IntValue(unsigned: true)]
		private int $port,
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
