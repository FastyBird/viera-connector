<?php declare(strict_types = 1);

namespace FastyBird\Connector\Viera\Tests\Cases\Unit\Clients;

use Error;
use FastyBird\Connector\Viera\API;
use FastyBird\Connector\Viera\Clients;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Queries;
use FastyBird\Connector\Viera\Queue;
use FastyBird\Connector\Viera\Tests;
use FastyBird\Library\Bootstrap\Exceptions as BootstrapExceptions;
use FastyBird\Library\Metadata\Exceptions as MetadataExceptions;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use FastyBird\Module\Devices\Models as DevicesModels;
use GuzzleHttp;
use Nette\DI;
use Nette\Utils;
use Psr\Http;
use React;
use React\Datagram;
use React\EventLoop;
use React\Socket;
use RuntimeException;
use function strval;

final class DiscoveryTest extends Tests\Cases\Unit\DbTestCase
{

	/**
	 * @throws BootstrapExceptions\InvalidArgument
	 * @throws DevicesExceptions\InvalidState
	 * @throws DI\MissingServiceException
	 * @throws Exceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws RuntimeException
	 * @throws Error
	 */
	public function testDiscover(): void
	{
		$sender = $this->createMock(Datagram\SocketInterface::class);
		$sender
			->expects(self::once())
			->method('on')
			->with(
				self::callback(static function (string $event): bool {
					self::assertSame('message', $event);

					return true;
				}),
				self::callback(static function ($callback): bool {
					self::assertIsCallable($callback);

					$data = 'HTTP/1.1 200 OK';
					$data .= "CACHE-CONTROL: max-age=1800\n\r";
					$data .= "EXT:\n\r";
					$data .= "LOCATION: http://10.10.0.10:55000/nrc/ddd.xml\n\r";
					$data .= "SERVER: Linux/4.0 UPnP/1.0 Panasonic-MIL-DLNA-SV/1.0\n\r";
					$data .= "ST: urn:panasonic-com:service:p00NetworkControl:1\n\r";
					$data .= "USN: uuid:93e760e1-f011-4a33-a70d-c9629706ccf8::urn:panasonic-com:service:p00NetworkControl:1\n\r";
					$data .= "\n\r";
					$data .= "\n\r";

					$callback($data);

					return true;
				}),
			);

		$sender
			->expects(self::once())
			->method('close');

		$sender
			->expects(self::once())
			->method('send')
			->with(
				self::callback(static function (string $data): bool {
					$expected = "M-SEARCH * HTTP/1.1\r\n";
					$expected .= "HOST: 239.255.255.250:1900\r\n";
					$expected .= "MAN: \"ssdp:discover\"\r\n";
					$expected .= "ST: urn:panasonic-com:service:p00NetworkControl:1\r\n";
					$expected .= "MX: 1\r\n";
					$expected .= "\r\n";

					self::assertSame($expected, $data);

					return true;
				}),
				self::callback(static function (string $destination): bool {
					self::assertSame('239.255.255.250:1900', $destination);

					return true;
				}),
			);

		$multicastFactory = $this->createMock(Clients\MulticastFactory::class);
		$multicastFactory
			->method('create')
			->willReturn($sender);

		$this->mockContainerService(
			Clients\MulticastFactory::class,
			$multicastFactory,
		);

		$responseBody = $this->createMock(Http\Message\StreamInterface::class);
		$responseBody
			->method('rewind');

		$responseBody
			->method('getContents')
			->willReturn('');

		$response = $this->createMock(Http\Message\ResponseInterface::class);
		$response
			->method('getBody')
			->willReturn($responseBody);

		$responsePromise = $this->createMock(React\Promise\PromiseInterface::class);
		$responsePromise
			->method('then')
			->with(
				self::callback(static function (callable $callback) use ($response): bool {
					$callback($response);

					return true;
				}),
				self::callback(static fn (): bool => true),
			);

		$httpAsyncClient = $this->createMock(React\Http\Io\Transaction::class);
		$httpAsyncClient
			->method('send')
			->willReturn($responsePromise);

		$httpSyncClient = $this->createMock(GuzzleHttp\Client::class);
		$httpSyncClient
			->method('send')
			->willReturnCallback(
				function (Http\Message\RequestInterface $request): Http\Message\ResponseInterface {
					$responseBody = $this->createMock(Http\Message\StreamInterface::class);
					$responseBody
						->method('rewind');

					if (strval($request->getUri()) === 'http://10.10.0.10:55000/nrc/ddd.xml') {
						$responseBody
							->method('getContents')
							->willReturn(
								Utils\FileSystem::read(__DIR__ . '/../../../fixtures/Clients/discovery_specs.xml'),
							);
					} elseif (strval($request->getUri()) === 'http://10.10.0.10:55000/nrc/sdd_0.xml') {
						$responseBody
							->method('getContents')
							->willReturn(
								Utils\FileSystem::read(
									__DIR__ . '/../../../fixtures/Clients/discovery_crypto_check.xml',
								),
							);
					}

					$response = $this->createMock(Http\Message\ResponseInterface::class);
					$response
						->method('getBody')
						->willReturn($responseBody);

					return $response;
				},
			);

		$httpClientFactory = $this->createMock(API\HttpClientFactory::class);
		$httpClientFactory
			->method('create')
			->willReturnCallback(
				static function (bool $async) use ($httpAsyncClient, $httpSyncClient) {
					if ($async) {
						return $httpAsyncClient;
					}

					return $httpSyncClient;
				},
			);

		$this->mockContainerService(
			API\HttpClientFactory::class,
			$httpClientFactory,
		);

		$socketConnectorPromise = $this->createMock(React\Promise\ExtendedPromiseInterface::class);
		$socketConnectorPromise
			->method('then')
			->with(
				self::callback(static function (callable $callback): bool {
					$callback();

					return true;
				}),
			)
			->willReturn($socketConnectorPromise);

		$socketConnectorPromise
			->method('otherwise')
			->with(
				self::callback(static function ($callback): bool {
					self::assertIsCallable($callback);

					return true;
				}),
			)
			->willReturn($socketConnectorPromise);

		$socketConnector = $this->createMock(Socket\Connector::class);
		$socketConnector
			->method('connect')
			->with(
				self::callback(static function (string $destination): bool {
					self::assertSame('10.10.0.10:55000', $destination);

					return true;
				}),
			)
			->willReturn($socketConnectorPromise);

		$socketClientFactory = $this->createMock(API\SocketClientFactory::class);
		$socketClientFactory
			->method('create')
			->willReturn($socketConnector);

		$this->mockContainerService(API\SocketClientFactory::class, $socketClientFactory);

		$connectorsRepository = $this->getContainer()->getByType(DevicesModels\Connectors\ConnectorsRepository::class);

		$findConnectorQuery = new Queries\FindConnectors();
		$findConnectorQuery->byIdentifier('viera');

		$connector = $connectorsRepository->findOneBy($findConnectorQuery, Entities\VieraConnector::class);
		self::assertInstanceOf(Entities\VieraConnector::class, $connector);

		$clientFactory = $this->getContainer()->getByType(Clients\DiscoveryFactory::class);

		$client = $clientFactory->create($connector);

		$client->on('finished', static function (array $foundDevices): void {
			self::assertCount(1, $foundDevices);
		});

		$client->discover();

		$eventLoop = $this->getContainer()->getByType(EventLoop\LoopInterface::class);

		$eventLoop->addTimer(6, static function () use ($eventLoop): void {
			$eventLoop->stop();
		});

		$eventLoop->run();

		$queue = $this->getContainer()->getByType(Queue\Queue::class);

		self::assertFalse($queue->isEmpty());

		$consumers = $this->getContainer()->getByType(Queue\Consumers::class);

		$consumers->consume();

		$devicesRepository = $this->getContainer()->getByType(DevicesModels\Devices\DevicesRepository::class);

		$findDeviceQuery = new Queries\FindDevices();
		$findDeviceQuery->forConnector($connector);
		$findDeviceQuery->byIdentifier('93e760e1-f011-4a33-a70d-c9629706ccf8');

		$device = $devicesRepository->findOneBy($findDeviceQuery, Entities\VieraDevice::class);

		self::assertInstanceOf(Entities\VieraDevice::class, $device);
		self::assertSame('4D454930-0200-1000-8001-A81374B30314', $device->getSerialNumber());
		self::assertSame('Panasonic VIErA TX-49DX600EA', $device->getModel());
		self::assertSame('Panasonic', $device->getManufacturer());
	}

}
