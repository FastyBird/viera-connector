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

use FastyBird\Module\Devices\Types as DevicesTypes;

/**
 * Device property identifier types
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Types
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
enum DevicePropertyIdentifier: string
{

	case IP_ADDRESS = DevicesTypes\DevicePropertyIdentifier::IP_ADDRESS->value;

	case STATE = DevicesTypes\DevicePropertyIdentifier::STATE->value;

	case MODEL = DevicesTypes\DevicePropertyIdentifier::HARDWARE_MODEL->value;

	case MANUFACTURER = DevicesTypes\DevicePropertyIdentifier::HARDWARE_MANUFACTURER->value;

	case MAC_ADDRESS = DevicesTypes\DevicePropertyIdentifier::HARDWARE_MAC_ADDRESS->value;

	case SERIAL_NUMBER = DevicesTypes\DevicePropertyIdentifier::SERIAL_NUMBER->value;

	case PORT = 'port';

	case ENCRYPTED = 'encrypted';

	case APP_ID = 'app_id';

	case ENCRYPTION_KEY = 'encryption_key';

	case STATE_READING_DELAY = 'state_reading_delay';

}
