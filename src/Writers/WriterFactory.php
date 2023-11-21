<?php declare(strict_types = 1);

/**
 * WriterFactory.php
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

use FastyBird\Library\Metadata\Documents as MetadataDocuments;

/**
 * Device state writer interface factory
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Writers
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
interface WriterFactory
{

	public function create(MetadataDocuments\DevicesModule\Connector $connector): Writer;

}
