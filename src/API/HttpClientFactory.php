<?php declare(strict_types = 1);

/**
 * HttpClientFactory.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 * @since          1.0.0
 *
 * @date           01.07.23
 */

namespace FastyBird\Connector\Viera\API;

use GuzzleHttp;
use InvalidArgumentException;
use Nette;
use React\EventLoop;
use React\Http;
use React\Socket\Connector;

/**
 * HTTP client factory
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class HttpClientFactory
{

	use Nette\SmartObject;

	private const CONNECTION_TIMEOUT = 10;

	private GuzzleHttp\Client|null $client = null;

	private Http\Browser|null $asyncClient = null;

	public function __construct(
		private readonly EventLoop\LoopInterface $eventLoop,
	)
	{
	}

	/**
	 * @return ($async is true ? Http\Browser : GuzzleHttp\Client)
	 *
	 * @throws InvalidArgumentException
	 */
	public function createClient(bool $async = true): GuzzleHttp\Client|Http\Browser
	{
		if ($async) {
			if ($this->asyncClient === null) {
				$this->asyncClient = new Http\Browser(
					new Connector(
						[
							'timeout' => self::CONNECTION_TIMEOUT,
						],
						$this->eventLoop,
					),
					$this->eventLoop,
				);
			}

			return $this->asyncClient;
		} else {
			if ($this->client === null) {
				$this->client = new GuzzleHttp\Client();
			}

			return $this->client;
		}
	}

}
