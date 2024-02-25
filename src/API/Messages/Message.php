<?php declare(strict_types = 1);

/**
 * Message.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 * @since          1.0.0
 *
 * @date           06.05.23
 */

namespace FastyBird\Connector\Viera\API\Messages;

use Orisai\ObjectMapper;

/**
 * Viera base message interface
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
interface Message extends ObjectMapper\MappedObject
{

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array;

}
