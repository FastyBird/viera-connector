<?php declare(strict_types = 1);

/**
 * EventFactory.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Writers
 * @since          1.0.0
 *
 * @date           10.08.23
 */

namespace FastyBird\Connector\Viera\Writers;

use FastyBird\Connector\Viera\Documents;

/**
 * System event device state periodic writer factory
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Writers
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
interface EventFactory extends WriterFactory
{

	public function create(Documents\Connectors\Connector $connector): Event;

}
