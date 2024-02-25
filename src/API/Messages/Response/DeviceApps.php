<?php declare(strict_types = 1);

/**
 * DeviceApps.php
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
use function array_map;

/**
 * Device apps info message
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
readonly class DeviceApps implements API\Messages\Message
{

	/**
	 * @param array<Application> $apps
	 */
	public function __construct(
		#[ObjectMapper\Rules\ArrayOf(
			new ObjectMapper\Rules\MappedObjectValue(Application::class),
		)]
		private array $apps = [],
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
