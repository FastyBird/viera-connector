<?php declare(strict_types = 1);

/**
 * Property.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Helpers
 * @since          1.0.0
 *
 * @date           21.06.23
 */

namespace FastyBird\Connector\Viera\Helpers;

use FastyBird\Library\Metadata\Exceptions as MetadataExceptions;
use FastyBird\Module\Devices\Entities as DevicesEntities;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use FastyBird\Module\Devices\Utilities as DevicesUtilities;
use Nette;
use Nette\Utils;

/**
 * Useful dynamic property state helpers
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Helpers
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class Property
{

	use Nette\SmartObject;

	public function __construct(
		private readonly DevicesUtilities\DevicePropertiesStates $devicePropertiesStateManager,
		private readonly DevicesUtilities\ChannelPropertiesStates $channelPropertiesStateManager,
	)
	{
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	public function setValue(
		DevicesEntities\Devices\Properties\Dynamic|DevicesEntities\Channels\Properties\Dynamic $property,
		Utils\ArrayHash $data,
	): void
	{
		if ($property instanceof DevicesEntities\Devices\Properties\Dynamic) {
			$this->devicePropertiesStateManager->setValue($property, $data);
		} else {
			$this->channelPropertiesStateManager->setValue($property, $data);
		}
	}

}
