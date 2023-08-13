<?php declare(strict_types = 1);

/**
 * DeviceApps.php
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

use FastyBird\Connector\Viera\Entities;
use Orisai\ObjectMapper;
use function array_map;

/**
 * Device apps info entity
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class DeviceApps implements Entities\API\Entity
{

	/**
	 * @param array<Application> $apps
	 */
	public function __construct(
		#[ObjectMapper\Rules\ArrayOf(
			new ObjectMapper\Rules\MappedObjectValue(Application::class),
		)]
		private readonly array $apps = [],
	)
	{
	}

	/**
	 * @return array<Application>
	 */
	public function getApps(): array
	{
		return $this->apps;
	}

	/**
	 * {@inheritDoc}
	 */
	public function toArray(): array
	{
		return [
			'applications' => array_map(static fn (Application $app): array => $app->toArray(), $this->getApps()),
		];
	}

}
