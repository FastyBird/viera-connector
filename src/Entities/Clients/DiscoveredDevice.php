<?php declare(strict_types = 1);

/**
 * DiscoveredDevice.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 * @since          1.0.0
 *
 * @date           05.07.23
 */

namespace FastyBird\Connector\Viera\Entities\Clients;

use FastyBird\Connector\Viera\Entities\Messages\Entity;
use Nette;
use function array_map;

/**
 * Newly created device entity
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class DiscoveredDevice implements Entity
{

	use Nette\SmartObject;

	/**
	 * @param array<DeviceApplication> $applications
	 */
	public function __construct(
		private readonly string $identifier,
		private readonly string $ipAddress,
		private readonly int $port,
		private readonly string|null $name,
		private readonly string|null $model,
		private readonly string|null $manufacturer,
		private readonly string|null $serialNumber,
		private readonly bool $encrypted,
		private readonly array $applications,
	)
	{
	}

	public function getIdentifier(): string
	{
		return $this->identifier;
	}

	public function getIpAddress(): string
	{
		return $this->ipAddress;
	}

	public function getPort(): int
	{
		return $this->port;
	}

	public function getName(): string|null
	{
		return $this->name;
	}

	public function getModel(): string|null
	{
		return $this->model;
	}

	public function getManufacturer(): string|null
	{
		return $this->manufacturer;
	}

	public function getSerialNumber(): string|null
	{
		return $this->serialNumber;
	}

	public function isEncrypted(): bool
	{
		return $this->encrypted;
	}

	/**
	 * @return array<DeviceApplication>
	 */
	public function getApplications(): array
	{
		return $this->applications;
	}

	/**
	 * {@inheritDoc}
	 */
	public function toArray(): array
	{
		return [
			'identifier' => $this->getIdentifier(),
			'ip_address' => $this->getIpAddress(),
			'port' => $this->getPort(),
			'name' => $this->getName(),
			'model' => $this->getModel(),
			'manufacturer' => $this->getManufacturer(),
			'serial_number' => $this->getSerialNumber(),
			'is_encrypted' => $this->isEncrypted(),
			'applications' => array_map(
				static fn (DeviceApplication $item): array => $item->toArray(),
				$this->getApplications(),
			),
		];
	}

}
