<?php declare(strict_types = 1);

/**
 * Network.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Helpers
 * @since          1.0.0
 *
 * @date           23.06.23
 */

namespace FastyBird\Connector\Viera\Helpers;

use Socket;
use Throwable;
use function socket_close;
use function socket_connect;
use function socket_create;
use function socket_getsockname;
use const AF_INET;
use const SOCK_DGRAM;
use const SOL_UDP;

/**
 * Useful network helpers
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Helpers
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class Network
{

	public static function getLocalAddress(): string|null
	{
		$address = $sock = null;

		try {
			$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

			if ($sock === false) {
				return null;
			}

			socket_connect($sock, '8.8.8.8', 53);
			socket_getsockname($sock, $address);

		} catch (Throwable) {
			return null;
		} finally {
			if ($sock instanceof Socket) {
				socket_close($sock);
			}
		}

		return $address;
	}

}
