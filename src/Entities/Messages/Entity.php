<?php declare(strict_types = 1);

/**
 * Transformer.php
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

namespace FastyBird\Connector\Viera\Entities\Messages;

use Orisai\ObjectMapper;

/**
 * Viera base message data entity interface
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
interface Entity extends ObjectMapper\MappedObject
{

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array;

}
