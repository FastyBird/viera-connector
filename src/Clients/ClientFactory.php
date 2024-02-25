<?php declare(strict_types = 1);

/**
 * ClientFactory.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Clients
 * @since          1.0.0
 *
 * @date           21.06.23
 */

namespace FastyBird\Connector\Viera\Clients;

use FastyBird\Connector\Viera\Documents;

/**
 * Base device client factory
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Clients
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
interface ClientFactory
{

	public function create(Documents\Connectors\Connector $connector): Client;

}
