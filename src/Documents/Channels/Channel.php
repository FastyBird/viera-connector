<?php declare(strict_types = 1);

/**
 * Channel.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Documents
 * @since          1.0.0
 *
 * @date           10.02.24
 */

namespace FastyBird\Connector\Viera\Documents\Channels;

use FastyBird\Connector\Viera\Entities;
use FastyBird\Library\Metadata\Documents\Mapping as DOC;
use FastyBird\Module\Devices\Documents as DevicesDocuments;

#[DOC\Document(entity: Entities\Channels\Channel::class)]
#[DOC\DiscriminatorEntry(name: Entities\Channels\Channel::TYPE)]
class Channel extends DevicesDocuments\Channels\Channel
{

	public static function getType(): string
	{
		return Entities\Channels\Channel::TYPE;
	}

}
