<?php declare(strict_types = 1);

/**
 * DeviceSpecs.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 * @since          1.0.0
 *
 * @date           21.06.23
 */

namespace FastyBird\Connector\Viera\API\Messages\Response;

use FastyBird\Connector\Viera\API;
use Orisai\ObjectMapper;

/**
 * Device specs info message
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class DeviceSpecs implements API\Messages\Message
{

	public function __construct(
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('device_type')]
		private readonly string|null $deviceType,
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('friendly_name')]
		private readonly string|null $friendlyName,
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		private readonly string|null $manufacturer,
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('model_name')]
		private readonly string|null $modelName,
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('model_number')]
		private readonly string|null $modelNumber,
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('serial_number')]
		private readonly string $serialNumber,
		#[ObjectMapper\Rules\BoolValue()]
		#[ObjectMapper\Modifiers\FieldName('requires_encryption')]
		private bool $requiresEncryption = false,
	)
	{
	}

	public function getDeviceType(): string|null
	{
		return $this->deviceType;
	}

	public function getFriendlyName(): string|null
	{
		return $this->friendlyName;
	}

	public function getManufacturer(): string|null
	{
		return $this->manufacturer;
	}

	public function getModelName(): string|null
	{
		return $this->modelName;
	}

	public function getModelNumber(): string|null
	{
		return $this->modelNumber;
	}

	public function setRequiresEncryption(bool $requiresEncryption): void
	{
		$this->requiresEncryption = $requiresEncryption;
	}

	public function isRequiresEncryption(): bool
	{
		return $this->requiresEncryption;
	}

	public function getSerialNumber(): string
	{
		return $this->serialNumber;
	}

	/**
	 * {@inheritDoc}
	 */
	public function toArray(): array
	{
		return [
			'device_type' => $this->getDeviceType(),
			'friendly_name' => $this->getFriendlyName(),
			'manufacturer' => $this->getManufacturer(),
			'model_name' => $this->getModelName(),
			'model_number' => $this->getModelNumber(),
			'requires_encryption' => $this->isRequiresEncryption(),
			'serial_number' => $this->getSerialNumber(),
		];
	}

}
