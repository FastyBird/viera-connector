<?php declare(strict_types = 1);

namespace FastyBird\Connector\Viera\Tests\Cases\Unit\API;

use Error;
use FastyBird\Connector\Viera\API;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Services;
use FastyBird\Connector\Viera\Tests;
use FastyBird\Core\Application\Exceptions as ApplicationExceptions;
use Fig\Http\Message\RequestMethodInterface;
use GuzzleHttp;
use Nette\DI;
use Nette\Utils;
use Psr\Http;
use RuntimeException;
use function strval;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TelevisionApiTest extends Tests\Cases\Unit\DbTestCase
{

	/**
	 * @throws ApplicationExceptions\InvalidArgument
	 * @throws DI\MissingServiceException
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 * @throws Error
	 */
	public function testGetSpecs(): void
	{
		$httpClient = $this->createMock(GuzzleHttp\Client::class);
		$httpClient
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
								Utils\FileSystem::read(__DIR__ . '/../../../fixtures/API/response/get_specs.xml'),
							);
					} elseif (strval($request->getUri()) === 'http://10.10.0.10:55000/nrc/sdd_0.xml') {
						$responseBody
							->method('getContents')
							->willReturn(
								Utils\FileSystem::read(
									__DIR__ . '/../../../fixtures/API/response/crypto_check.xml',
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

		$httpClientFactory = $this->createMock(Services\HttpClientFactory::class);
		$httpClientFactory
			->method('create')
			->willReturnCallback(
				static function (bool $async) use ($httpClient) {
					self::assertFalse($async);

					return $httpClient;
				},
			);

		$this->mockContainerService(
			Services\HttpClientFactory::class,
			$httpClientFactory,
		);

		$televisionApiFactory = $this->getContainer()->getByType(API\TelevisionApiFactory::class);

		$televisionApi = $televisionApiFactory->create(
			'testing-television',
			'10.10.0.10',
			55_000,
		);

		$televisionSpec = $televisionApi->getSpecs(false);

		self::assertSame('4D454930-0200-1000-8001-A81374B30314', $televisionSpec->getSerialNumber());
		self::assertSame('TX-49DX600EA', $televisionSpec->getModelNumber());
		self::assertSame('Panasonic VIErA', $televisionSpec->getModelName());
		self::assertSame('49DX600_Series', $televisionSpec->getFriendlyName());
		self::assertSame('Panasonic', $televisionSpec->getManufacturer());
		self::assertSame('urn:panasonic-com:device:p00RemoteController:1', $televisionSpec->getDeviceType());
	}

	/**
	 * @throws ApplicationExceptions\InvalidArgument
	 * @throws DI\MissingServiceException
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 * @throws Error
	 */
	public function testGetApps(): void
	{
		$httpClient = $this->createMock(GuzzleHttp\Client::class);
		$httpClient
			->method('send')
			->willReturnCallback(
				function (Http\Message\RequestInterface $request): Http\Message\ResponseInterface {
					self::assertSame('http://10.10.0.10:55000/nrc/control_0', strval($request->getUri()));
					self::assertSame(RequestMethodInterface::METHOD_POST, $request->getMethod());
					self::assertXmlStringEqualsXmlString(
						Utils\FileSystem::read(__DIR__ . '/../../../fixtures/API/request/get_apps.xml'),
						$request->getBody()->getContents(),
					);
					self::assertSame([
						'Host' => [
							'10.10.0.10:55000',
						],
						'Content-Length' => [
							'285',
						],
						'Content-Type' => [
							'text/xml; charset="utf-8"',
						],
						'SOAPAction' => [
							'"urn:panasonic-com:service:p00NetworkControl:1#X_GetAppList"',
						],
						'Cache-Control' => [
							'no-cache',
						],
						'Pragma' => [
							'no-cache',
						],
						'Accept' => [
							'text/xml',
						],
					], $request->getHeaders());

					$responseBody = $this->createMock(Http\Message\StreamInterface::class);
					$responseBody
						->method('rewind');

					$responseBody
						->method('getContents')
						->willReturn(
							Utils\FileSystem::read(__DIR__ . '/../../../fixtures/API/response/get_apps.xml'),
						);

					$response = $this->createMock(Http\Message\ResponseInterface::class);
					$response
						->method('getBody')
						->willReturn($responseBody);

					return $response;
				},
			);

		$httpClientFactory = $this->createMock(Services\HttpClientFactory::class);
		$httpClientFactory
			->method('create')
			->willReturnCallback(
				static function (bool $async) use ($httpClient) {
					self::assertFalse($async);

					return $httpClient;
				},
			);

		$this->mockContainerService(
			Services\HttpClientFactory::class,
			$httpClientFactory,
		);

		$televisionApiFactory = $this->getContainer()->getByType(API\TelevisionApiFactory::class);

		$televisionApi = $televisionApiFactory->create(
			'testing-television',
			'10.10.0.10',
			55_000,
		);

		$televisionApps = $televisionApi->getApps(false);

		self::assertCount(17, $televisionApps->getApps());
		self::assertSame([
			'applications' => [
				[
					'id' => '0387878700000102',
					'name,' => 'Apps Market',
				],
				[
					'id' => '0010000200000001',
					'name,' => 'Netflix',
				],
				[
					'id' => '0070000200000001',
					'name,' => 'YouTube',
				],
				[
					'id' => '0020007100000001',
					'name,' => 'Meteonews TV',
				],
				[
					'id' => '0387878700000016',
					'name,' => 'VIERA Link',
				],
				[
					'id' => '0387878700000003',
					'name,' => 'TV průvodce',
				],
				[
					'id' => '0077777700160002',
					'name,' => 'Browser',
				],
				[
					'id' => '0387878700000013',
					'name,' => 'TV záznam',
				],
				[
					'id' => '0020000400000003',
					'name,' => 'Voyo.cz',
				],
				[
					'id' => '0020000600000001',
					'name,' => 'ARTE',
				],
				[
					'id' => '0020001000000001',
					'name,' => 'euronews',
				],
				[
					'id' => '0020001200000001',
					'name,' => 'CineTrailer',
				],
				[
					'id' => '0020001900000001',
					'name,' => 'Viewster - Finest Movies On Demand',
				],
				[
					'id' => '0387878700150020',
					'name,' => 'Kalendář',
				],
				[
					'id' => '0076006407000001',
					'name,' => 'SledovaniTV',
				],
				[
					'id' => '0076002307000001',
					'name,' => 'Digital Concert Hall',
				],
				[
					'id' => '0020002A00000003',
					'name,' => 'Cinema',
				],
			],
		], $televisionApps->toArray());
	}

	/**
	 * @throws ApplicationExceptions\InvalidArgument
	 * @throws DI\MissingServiceException
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 * @throws Error
	 */
	public function testGetVectorInfo(): void
	{
		$httpClient = $this->createMock(GuzzleHttp\Client::class);
		$httpClient
			->method('send')
			->willReturnCallback(
				function (Http\Message\RequestInterface $request): Http\Message\ResponseInterface {
					self::assertSame('http://10.10.0.10:55000/nrc/control_0', strval($request->getUri()));
					self::assertSame(RequestMethodInterface::METHOD_POST, $request->getMethod());
					self::assertXmlStringEqualsXmlString(
						Utils\FileSystem::read(__DIR__ . '/../../../fixtures/API/request/get_vector_info.xml'),
						$request->getBody()->getContents(),
					);
					self::assertSame([
						'Host' => [
							'10.10.0.10:55000',
						],
						'Content-Length' => [
							'291',
						],
						'Content-Type' => [
							'text/xml; charset="utf-8"',
						],
						'SOAPAction' => [
							'"urn:panasonic-com:service:p00NetworkControl:1#X_GetVectorInfo"',
						],
						'Cache-Control' => [
							'no-cache',
						],
						'Pragma' => [
							'no-cache',
						],
						'Accept' => [
							'text/xml',
						],
					], $request->getHeaders());

					$responseBody = $this->createMock(Http\Message\StreamInterface::class);
					$responseBody
						->method('rewind');

					$responseBody
						->method('getContents')
						->willReturn(
							Utils\FileSystem::read(__DIR__ . '/../../../fixtures/API/response/get_vector_info.xml'),
						);

					$response = $this->createMock(Http\Message\ResponseInterface::class);
					$response
						->method('getBody')
						->willReturn($responseBody);

					return $response;
				},
			);

		$httpClientFactory = $this->createMock(Services\HttpClientFactory::class);
		$httpClientFactory
			->method('create')
			->willReturnCallback(
				static function (bool $async) use ($httpClient) {
					self::assertFalse($async);

					return $httpClient;
				},
			);

		$this->mockContainerService(
			Services\HttpClientFactory::class,
			$httpClientFactory,
		);

		$televisionApiFactory = $this->getContainer()->getByType(API\TelevisionApiFactory::class);

		$televisionApi = $televisionApiFactory->create(
			'testing-television',
			'10.10.0.10',
			55_000,
		);

		$vectorInfo = $televisionApi->getVectorInfo(false);

		self::assertSame(55_000, $vectorInfo->getPort());
	}

	/**
	 * @throws ApplicationExceptions\InvalidArgument
	 * @throws DI\MissingServiceException
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 * @throws Error
	 */
	public function testGetVolume(): void
	{
		$httpClient = $this->createMock(GuzzleHttp\Client::class);
		$httpClient
			->method('send')
			->willReturnCallback(
				function (Http\Message\RequestInterface $request): Http\Message\ResponseInterface {
					self::assertSame('http://10.10.0.10:55000/dmr/control_0', strval($request->getUri()));
					self::assertSame(RequestMethodInterface::METHOD_POST, $request->getMethod());
					self::assertXmlStringEqualsXmlString(
						Utils\FileSystem::read(__DIR__ . '/../../../fixtures/API/request/get_volume.xml'),
						$request->getBody()->getContents(),
					);
					self::assertSame([
						'Host' => [
							'10.10.0.10:55000',
						],
						'Content-Length' => [
							'328',
						],
						'Content-Type' => [
							'text/xml; charset="utf-8"',
						],
						'SOAPAction' => [
							'"urn:schemas-upnp-org:service:RenderingControl:1#GetVolume"',
						],
						'Cache-Control' => [
							'no-cache',
						],
						'Pragma' => [
							'no-cache',
						],
						'Accept' => [

							'text/xml',
						],
					], $request->getHeaders());

					$responseBody = $this->createMock(Http\Message\StreamInterface::class);
					$responseBody
						->method('rewind');

					$responseBody
						->method('getContents')
						->willReturn(
							Utils\FileSystem::read(__DIR__ . '/../../../fixtures/API/response/get_volume.xml'),
						);

					$response = $this->createMock(Http\Message\ResponseInterface::class);
					$response
						->method('getBody')
						->willReturn($responseBody);

					return $response;
				},
			);

		$httpClientFactory = $this->createMock(Services\HttpClientFactory::class);
		$httpClientFactory
			->method('create')
			->willReturnCallback(
				static function (bool $async) use ($httpClient) {
					self::assertFalse($async);

					return $httpClient;
				},
			);

		$this->mockContainerService(
			Services\HttpClientFactory::class,
			$httpClientFactory,
		);

		$televisionApiFactory = $this->getContainer()->getByType(API\TelevisionApiFactory::class);

		$televisionApi = $televisionApiFactory->create(
			'testing-television',
			'10.10.0.10',
			55_000,
		);

		$volume = $televisionApi->getVolume(false);

		self::assertSame(20, $volume);
	}

	/**
	 * @throws ApplicationExceptions\InvalidArgument
	 * @throws DI\MissingServiceException
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 * @throws Error
	 */
	public function testSetVolume(): void
	{
		$httpClient = $this->createMock(GuzzleHttp\Client::class);
		$httpClient
			->method('send')
			->willReturnCallback(
				function (Http\Message\RequestInterface $request): Http\Message\ResponseInterface {
					self::assertSame('http://10.10.0.10:55000/dmr/control_0', strval($request->getUri()));
					self::assertSame(RequestMethodInterface::METHOD_POST, $request->getMethod());
					self::assertXmlStringEqualsXmlString(
						Utils\FileSystem::read(__DIR__ . '/../../../fixtures/API/request/set_volume.xml'),
						$request->getBody()->getContents(),
					);
					self::assertSame([
						'Host' => [
							'10.10.0.10:55000',
						],
						'Content-Length' => [
							'361',
						],
						'Content-Type' => [
							'text/xml; charset="utf-8"',
						],
						'SOAPAction' => [
							'"urn:schemas-upnp-org:service:RenderingControl:1#SetVolume"',
						],
						'Cache-Control' => [
							'no-cache',
						],
						'Pragma' => [
							'no-cache',
						],
						'Accept' => [

							'text/xml',
						],
					], $request->getHeaders());

					$responseBody = $this->createMock(Http\Message\StreamInterface::class);
					$responseBody
						->method('rewind');

					$responseBody
						->method('getContents')
						->willReturn(
							Utils\FileSystem::read(__DIR__ . '/../../../fixtures/API/response/set_volume.xml'),
						);

					$response = $this->createMock(Http\Message\ResponseInterface::class);
					$response
						->method('getBody')
						->willReturn($responseBody);

					return $response;
				},
			);

		$httpClientFactory = $this->createMock(Services\HttpClientFactory::class);
		$httpClientFactory
			->method('create')
			->willReturnCallback(
				static function (bool $async) use ($httpClient) {
					self::assertFalse($async);

					return $httpClient;
				},
			);

		$this->mockContainerService(
			Services\HttpClientFactory::class,
			$httpClientFactory,
		);

		$televisionApiFactory = $this->getContainer()->getByType(API\TelevisionApiFactory::class);

		$televisionApi = $televisionApiFactory->create(
			'testing-television',
			'10.10.0.10',
			55_000,
		);

		$televisionApi->setVolume(30, false);
	}

	/**
	 * @throws ApplicationExceptions\InvalidArgument
	 * @throws DI\MissingServiceException
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 * @throws Error
	 */
	public function testGetMute(): void
	{
		$httpClient = $this->createMock(GuzzleHttp\Client::class);
		$httpClient
			->method('send')
			->willReturnCallback(
				function (Http\Message\RequestInterface $request): Http\Message\ResponseInterface {
					self::assertSame('http://10.10.0.10:55000/dmr/control_0', strval($request->getUri()));
					self::assertSame(RequestMethodInterface::METHOD_POST, $request->getMethod());
					self::assertXmlStringEqualsXmlString(
						Utils\FileSystem::read(__DIR__ . '/../../../fixtures/API/request/get_mute.xml'),
						$request->getBody()->getContents(),
					);
					self::assertSame([
						'Host' => [
							'10.10.0.10:55000',
						],
						'Content-Length' => [
							'324',
						],
						'Content-Type' => [
							'text/xml; charset="utf-8"',
						],
						'SOAPAction' => [
							'"urn:schemas-upnp-org:service:RenderingControl:1#GetMute"',
						],
						'Cache-Control' => [
							'no-cache',
						],
						'Pragma' => [
							'no-cache',
						],
						'Accept' => [

							'text/xml',
						],
					], $request->getHeaders());

					$responseBody = $this->createMock(Http\Message\StreamInterface::class);
					$responseBody
						->method('rewind');

					$responseBody
						->method('getContents')
						->willReturn(
							Utils\FileSystem::read(__DIR__ . '/../../../fixtures/API/response/get_mute.xml'),
						);

					$response = $this->createMock(Http\Message\ResponseInterface::class);
					$response
						->method('getBody')
						->willReturn($responseBody);

					return $response;
				},
			);

		$httpClientFactory = $this->createMock(Services\HttpClientFactory::class);
		$httpClientFactory
			->method('create')
			->willReturnCallback(
				static function (bool $async) use ($httpClient) {
					self::assertFalse($async);

					return $httpClient;
				},
			);

		$this->mockContainerService(
			Services\HttpClientFactory::class,
			$httpClientFactory,
		);

		$televisionApiFactory = $this->getContainer()->getByType(API\TelevisionApiFactory::class);

		$televisionApi = $televisionApiFactory->create(
			'testing-television',
			'10.10.0.10',
			55_000,
		);

		$mute = $televisionApi->getMute(false);

		self::assertFalse($mute);
	}

	/**
	 * @throws ApplicationExceptions\InvalidArgument
	 * @throws DI\MissingServiceException
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 * @throws Error
	 */
	public function testSetMute(): void
	{
		$httpClient = $this->createMock(GuzzleHttp\Client::class);
		$httpClient
			->method('send')
			->willReturnCallback(
				function (Http\Message\RequestInterface $request): Http\Message\ResponseInterface {
					self::assertSame('http://10.10.0.10:55000/dmr/control_0', strval($request->getUri()));
					self::assertSame(RequestMethodInterface::METHOD_POST, $request->getMethod());
					self::assertXmlStringEqualsXmlString(
						Utils\FileSystem::read(__DIR__ . '/../../../fixtures/API/request/set_mute.xml'),
						$request->getBody()->getContents(),
					);
					self::assertSame([
						'Host' => [
							'10.10.0.10:55000',
						],
						'Content-Length' => [
							'352',
						],
						'Content-Type' => [
							'text/xml; charset="utf-8"',
						],
						'SOAPAction' => [
							'"urn:schemas-upnp-org:service:RenderingControl:1#SetMute"',
						],
						'Cache-Control' => [
							'no-cache',
						],
						'Pragma' => [
							'no-cache',
						],
						'Accept' => [

							'text/xml',
						],
					], $request->getHeaders());

					$responseBody = $this->createMock(Http\Message\StreamInterface::class);
					$responseBody
						->method('rewind');

					$responseBody
						->method('getContents')
						->willReturn(
							Utils\FileSystem::read(__DIR__ . '/../../../fixtures/API/response/set_mute.xml'),
						);

					$response = $this->createMock(Http\Message\ResponseInterface::class);
					$response
						->method('getBody')
						->willReturn($responseBody);

					return $response;
				},
			);

		$httpClientFactory = $this->createMock(Services\HttpClientFactory::class);
		$httpClientFactory
			->method('create')
			->willReturnCallback(
				static function (bool $async) use ($httpClient) {
					self::assertFalse($async);

					return $httpClient;
				},
			);

		$this->mockContainerService(
			Services\HttpClientFactory::class,
			$httpClientFactory,
		);

		$televisionApiFactory = $this->getContainer()->getByType(API\TelevisionApiFactory::class);

		$televisionApi = $televisionApiFactory->create(
			'testing-television',
			'10.10.0.10',
			55_000,
		);

		$televisionApi->setMute(true, false);
	}

	/**
	 * @throws ApplicationExceptions\InvalidArgument
	 * @throws DI\MissingServiceException
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 * @throws Error
	 */
	public function testSendKey(): void
	{
		$httpClient = $this->createMock(GuzzleHttp\Client::class);
		$httpClient
			->method('send')
			->willReturnCallback(
				function (Http\Message\RequestInterface $request): Http\Message\ResponseInterface {
					self::assertSame('http://10.10.0.10:55000/nrc/control_0', strval($request->getUri()));
					self::assertSame(RequestMethodInterface::METHOD_POST, $request->getMethod());
					self::assertXmlStringEqualsXmlString(
						Utils\FileSystem::read(__DIR__ . '/../../../fixtures/API/request/send_key.xml'),
						$request->getBody()->getContents(),
					);
					self::assertSame([
						'Host' => [
							'10.10.0.10:55000',
						],
						'Content-Length' => [
							'315',
						],
						'Content-Type' => [
							'text/xml; charset="utf-8"',
						],
						'SOAPAction' => [
							'"urn:panasonic-com:service:p00NetworkControl:1#X_SendKey"',
						],
						'Cache-Control' => [
							'no-cache',
						],
						'Pragma' => [
							'no-cache',
						],
						'Accept' => [

							'text/xml',
						],
					], $request->getHeaders());

					$responseBody = $this->createMock(Http\Message\StreamInterface::class);
					$responseBody
						->method('rewind');

					$responseBody
						->method('getContents')
						->willReturn(
							Utils\FileSystem::read(__DIR__ . '/../../../fixtures/API/response/send_key.xml'),
						);

					$response = $this->createMock(Http\Message\ResponseInterface::class);
					$response
						->method('getBody')
						->willReturn($responseBody);

					return $response;
				},
			);

		$httpClientFactory = $this->createMock(Services\HttpClientFactory::class);
		$httpClientFactory
			->method('create')
			->willReturnCallback(
				static function (bool $async) use ($httpClient) {
					self::assertFalse($async);

					return $httpClient;
				},
			);

		$this->mockContainerService(
			Services\HttpClientFactory::class,
			$httpClientFactory,
		);

		$televisionApiFactory = $this->getContainer()->getByType(API\TelevisionApiFactory::class);

		$televisionApi = $televisionApiFactory->create(
			'testing-television',
			'10.10.0.10',
			55_000,
		);

		$televisionApi->sendKey('NRC_HDMI1-ONOFF', false);
	}

	/**
	 * @throws ApplicationExceptions\InvalidArgument
	 * @throws DI\MissingServiceException
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 * @throws Error
	 */
	public function testLaunchApplication(): void
	{
		$httpClient = $this->createMock(GuzzleHttp\Client::class);
		$httpClient
			->method('send')
			->willReturnCallback(
				function (Http\Message\RequestInterface $request): Http\Message\ResponseInterface {
					self::assertSame('http://10.10.0.10:55000/nrc/control_0', strval($request->getUri()));
					self::assertSame(RequestMethodInterface::METHOD_POST, $request->getMethod());
					self::assertXmlStringEqualsXmlString(
						Utils\FileSystem::read(__DIR__ . '/../../../fixtures/API/request/launch_application.xml'),
						$request->getBody()->getContents(),
					);
					self::assertSame([
						'Host' => [
							'10.10.0.10:55000',
						],
						'Content-Length' => [
							'370',
						],
						'Content-Type' => [
							'text/xml; charset="utf-8"',
						],
						'SOAPAction' => [
							'"urn:panasonic-com:service:p00NetworkControl:1#X_LaunchApp"',
						],
						'Cache-Control' => [
							'no-cache',
						],
						'Pragma' => [
							'no-cache',
						],
						'Accept' => [

							'text/xml',
						],
					], $request->getHeaders());

					$responseBody = $this->createMock(Http\Message\StreamInterface::class);
					$responseBody
						->method('rewind');

					$responseBody
						->method('getContents')
						->willReturn(
							Utils\FileSystem::read(__DIR__ . '/../../../fixtures/API/response/launch_application.xml'),
						);

					$response = $this->createMock(Http\Message\ResponseInterface::class);
					$response
						->method('getBody')
						->willReturn($responseBody);

					return $response;
				},
			);

		$httpClientFactory = $this->createMock(Services\HttpClientFactory::class);
		$httpClientFactory
			->method('create')
			->willReturnCallback(
				static function (bool $async) use ($httpClient) {
					self::assertFalse($async);

					return $httpClient;
				},
			);

		$this->mockContainerService(
			Services\HttpClientFactory::class,
			$httpClientFactory,
		);

		$televisionApiFactory = $this->getContainer()->getByType(API\TelevisionApiFactory::class);

		$televisionApi = $televisionApiFactory->create(
			'testing-television',
			'10.10.0.10',
			55_000,
		);

		$televisionApi->launchApplication('0010000200000001', false);
	}

}
