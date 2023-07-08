<?php declare(strict_types = 1);

/**
 * DeviceSpecs.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 * @since          1.0.0
 *
 * @date           21.06.23
 */

namespace FastyBird\Connector\Viera\Entities\API;

use FastyBird\Connector\Viera\Entities;
use Nette\Utils;
use function strval;

/**
 * Device specs info entity
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class DeviceSpecs implements Entities\API\Entity
{

	private string $serialNumber;

	public function __construct(
		private readonly string|null $deviceType = null,
		private readonly string|null $friendlyName = null,
		private readonly string|null $manufacturer = null,
		private readonly string|null $modelName = null,
		private readonly string|null $modelNumber = null,
		private bool $requiresEncryption = false,
		string|null $UDN = null,
	)
	{
		$this->serialNumber = Utils\Strings::substring(strval($UDN), 5);
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
