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
	public const IDENTIFIER_IP_ADDRESS = MetadataTypes\DevicePropertyIdentifier::IDENTIFIER_IP_ADDRESS;

	public const IDENTIFIER_STATE = MetadataTypes\DevicePropertyIdentifier::IDENTIFIER_STATE;

	public const IDENTIFIER_HARDWARE_MODEL = MetadataTypes\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MODEL;

	public const IDENTIFIER_HARDWARE_MANUFACTURER = MetadataTypes\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MANUFACTURER;

	public const IDENTIFIER_HARDWARE_MAC_ADDRESS = MetadataTypes\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MAC_ADDRESS;

	public const IDENTIFIER_SERIAL_NUMBER = MetadataTypes\DevicePropertyIdentifier::IDENTIFIER_SERIAL_NUMBER;

	public const IDENTIFIER_PORT = 'port';

	public const IDENTIFIER_ENCRYPTED = 'encrypted';

	public const IDENTIFIER_APP_ID = 'app_id';

	public const IDENTIFIER_ENCRYPTION_KEY = 'encryption_key';

	public const IDENTIFIER_STATUS_READING_DELAY = 'status_reading_delay';

	public function __toString(): string
	{
		return strval(self::getValue());
	}

}
