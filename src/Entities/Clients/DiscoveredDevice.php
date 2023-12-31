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

use Orisai\ObjectMapper;
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

	/**
	 * @param array<DeviceApplication> $applications
	 */
	public function __construct(
		#[ObjectMapper\Rules\StringValue(notEmpty: true)]
		private readonly string $identifier,
		#[ObjectMapper\Rules\StringValue(notEmpty: true)]
		#[ObjectMapper\Modifiers\FieldName('ip_address')]
		private readonly string $ipAddress,
		#[ObjectMapper\Rules\IntValue(unsigned: true)]
		private readonly int $port,
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		private readonly string|null $name,
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		private readonly string|null $model,
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		private readonly string|null $manufacturer,
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('serial_number')]
		private readonly string|null $serialNumber,
		#[ObjectMapper\Rules\BoolValue()]
		private readonly bool $encrypted,
		#[ObjectMapper\Rules\ArrayOf(
			new ObjectMapper\Rules\MappedObjectValue(DeviceApplication::class),
		)]
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
