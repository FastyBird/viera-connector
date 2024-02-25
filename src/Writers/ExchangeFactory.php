<?php declare(strict_types = 1);

/**
 * ExchangeFactory.php
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
 * Event bus exchange device state periodic writer factory
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Writers
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
interface ExchangeFactory extends WriterFactory
{

	public function create(Documents\Connectors\Connector $connector): Exchange;

}
