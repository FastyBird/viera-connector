<?php declare(strict_types = 1);

/**
 * StoreDevice.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 * @since          1.0.0
 *
 * @date           28.06.23
 */

namespace FastyBird\Connector\Viera\Entities\Messages;

use FastyBird\Library\Bootstrap\ObjectMapper as BootstrapObjectMapper;
use Orisai\ObjectMapper;
use Ramsey\Uuid;
use function array_map;

/**
 * Newly created device entity
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class StoreDevice implements Entity
{

	/**
	 * @param array<DeviceHdmi> $hdmi
	 * @param array<DeviceApplication> $applications
	 */
	public function __construct(
		#[BootstrapObjectMapper\Rules\UuidValue()]
		private readonly Uuid\UuidInterface $connector,
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
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('mac_address')]
		private readonly string|null $macAddress,
		#[ObjectMapper\Rules\BoolValue()]
		private readonly bool $encrypted,
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('app_id')]
		private readonly string|null $appId,
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('encryption_key')]
		private readonly string|null $encryptionKey,
		#[ObjectMapper\Rules\ArrayOf(
			new ObjectMapper\Rules\MappedObjectValue(DeviceHdmi::class),
		)]
		private readonly array $hdmi = [],
		#[ObjectMapper\Rules\ArrayOf(
			new ObjectMapper\Rules\MappedObjectValue(DeviceApplication::class),
		)]
		private readonly array $applications = [],
	)
	{
	}

	public function getConnector(): Uuid\UuidInterface
	{
		return $this->connector;
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

	public function getMacAddress(): string|null
	{
		return $this->macAddress;
	}

	public function isEncrypted(): bool
	{
		return $this->encrypted;
	}

	public function getAppId(): string|null
	{
		return $this->appId;
	}

	public function getEncryptionKey(): string|null
	{
		return $this->encryptionKey;
	}

	/**
	 * @return array<DeviceHdmi>
	 */
	public function getHdmi(): array
	{
		return $this->hdmi;
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
			'connector' => $this->getConnector(),
			'identifier' => $this->getIdentifier(),
			'ip_address' => $this->getIpAddress(),
			'port' => $this->getPort(),
			'name' => $this->getName(),
			'model' => $this->getModel(),
			'manufacturer' => $this->getManufacturer(),
			'serial_number' => $this->getSerialNumber(),
			'mac_address' => $this->getMacAddress(),
			'is_encrypted' => $this->isEncrypted(),
			'app_id' => $this->getAppId(),
			'encryption_key' => $this->getEncryptionKey(),
			'hdmi' => array_map(
				static fn (DeviceHdmi $item): array => $item->toArray(),
				$this->getHdmi(),
			),
			'applications' => array_map(
				static fn (DeviceApplication $item): array => $item->toArray(),
				$this->getApplications(),
			),
		];
	}

}
