<?php declare(strict_types = 1);

/**
 * VieraChannel.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 * @since          1.0.0
 *
 * @date           07.01.23
 */

namespace FastyBird\Connector\Viera\Entities;

use Doctrine\ORM\Mapping as ORM;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Entities as DevicesEntities;

/**
 * @ORM\Entity
 */
class VieraChannel extends DevicesEntities\Channels\Channel
{

	public const TYPE = 'viera-connector';

	public function getType(): string
	{
		return self::TYPE;
	}

	public function getDiscriminatorName(): string
	{
		return self::TYPE;
	}

	public function getSource(): MetadataTypes\ConnectorSource
	{
		return MetadataTypes\ConnectorSource::get(MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_TUYA);
	}

}
