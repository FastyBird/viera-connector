<?php declare(strict_types = 1);

/**
 * FindChannels.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Queries
 * @since          1.0.0
 *
 * @date           07.01.24
 */

namespace FastyBird\Connector\Viera\Queries\Entities;

use FastyBird\Connector\Viera\Entities;
use FastyBird\Module\Devices\Queries as DevicesQueries;

/**
 * Find device channels entities query
 *
 * @template T of Entities\VieraChannel
 * @extends  DevicesQueries\Entities\FindChannels<T>
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Queries
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class FindChannels extends DevicesQueries\Entities\FindChannels
{

}
