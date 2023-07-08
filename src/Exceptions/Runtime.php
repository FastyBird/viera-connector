<?php declare(strict_types = 1);

/**
 * Runtime.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Exceptions
 * @since          1.0.0
 *
 * @date           18.06.23
 */

namespace FastyBird\Connector\Viera\Exceptions;

use RuntimeException as PHPRuntimeException;

class Runtime extends PHPRuntimeException implements Exception
{

}
