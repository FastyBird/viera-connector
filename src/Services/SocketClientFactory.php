<?php declare(strict_types = 1);

/**
 * SocketClientFactory.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Services
 * @since          1.0.0
 *
 * @date           01.07.23
 */

namespace FastyBird\Connector\Viera\Services;

use InvalidArgumentException;
use Nette;
use React\EventLoop;
use React\Socket;

/**
 * Socket client factory
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Services
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class SocketClientFactory
{

	use Nette\SmartObject;

	public function __construct(
		private readonly EventLoop\LoopInterface $eventLoop,
	)
	{
	}

	/**
	 * @throws InvalidArgumentException
	 */
	public function create(): Socket\Connector
	{
		return new Socket\Connector(
			[
				'dns' => '8.8.8.8',
				'timeout' => 10,
				'tls' => [
					'verify_peer' => false,
					'verify_peer_name' => false,
					'check_hostname' => false,
					'allow_self_signed' => true,
				],
			],
			$this->eventLoop,
		);
	}

}
