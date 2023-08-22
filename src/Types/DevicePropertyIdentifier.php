<?php declare(strict_types = 1);

/**
 * DevicePropertyIdentifier.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Types
 * @since          1.0.0
 *
 * @date           18.06.23
 */

namespace FastyBird\Connector\Viera\Types;

use Consistence;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use function strval;

/**
 * Device property identifier types
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Types
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class DevicePropertyIdentifier extends Consistence\Enum\Enum
{

	/**
	 * Define device states
	 */
	public const IP_ADDRESS = MetadataTypes\DevicePropertyIdentifier::IDENTIFIER_IP_ADDRESS;

	public const STATE = MetadataTypes\DevicePropertyIdentifier::IDENTIFIER_STATE;

	public const MODEL = MetadataTypes\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MODEL;

	public const MANUFACTURER = MetadataTypes\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MANUFACTURER;

	public const MAC_ADDRESS = MetadataTypes\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MAC_ADDRESS;

	public const SERIAL_NUMBER = MetadataTypes\DevicePropertyIdentifier::IDENTIFIER_SERIAL_NUMBER;

	public const PORT = 'port';

	public const ENCRYPTED = 'encrypted';

	public const APP_ID = 'app_id';

	public const ENCRYPTION_KEY = 'encryption_key';

	public const STATE_READING_DELAY = 'state_reading_delay';

	public function getValue(): string
	{
		return strval(parent::getValue());
	}

	public function __toString(): string
	{
		return strval(self::getValue());
	}

}
