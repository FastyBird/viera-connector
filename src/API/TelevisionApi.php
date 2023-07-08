<?php declare(strict_types = 1);

/**
 * TelevisionApi.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 * @since          1.0.0
 *
 * @date           18.06.23
 */

namespace FastyBird\Connector\Viera\API;

use Evenement;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Connector\Viera\Types;
use FastyBird\Library\Bootstrap\Helpers as BootstrapHelpers;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use GuzzleHttp;
use InvalidArgumentException;
use Nette;
use Nette\Utils;
use Psr\Http\Message;
use Psr\Log;
use React\Datagram;
use React\EventLoop;
use React\Promise;
use React\Socket;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use function array_combine;
use function array_fill;
use function array_key_exists;
use function array_merge;
use function array_pop;
use function array_values;
use function base64_decode;
use function boolval;
use function chr;
use function count;
use function explode;
use function hexdec;
use function http_build_query;
use function implode;
use function intval;
use function is_array;
use function is_string;
use function pack;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_split;
use function property_exists;
use function simplexml_load_string;
use function sprintf;
use function str_repeat;
use function str_replace;
use function str_split;
use function strlen;
use function strpos;
use function strtoupper;
use function strval;
use function substr;
use function unpack;

/**
 * Television api interface
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class TelevisionApi implements Evenement\EventEmitterInterface
{

	use Nette\SmartObject;
	use Evenement\EventEmitterTrait;

	private const EVENTS_TIMEOUT = 10;

	private const URN_RENDERING_CONTROL = 'schemas-upnp-org:service:RenderingControl:1';

	private const URN_REMOTE_CONTROL = 'panasonic-com:service:p00NetworkControl:1';

	private const URL_CONTROL_DMR = '/dmr/control_0';

	private const URL_CONTROL_NRC = '/nrc/control_0';

	private const URL_EVENT_NRC = '/nrc/event_0';

	private const URL_CONTROL_NRC_DDD = '/nrc/ddd.xml';

	private const URL_CONTROL_NRC_DEF = '/nrc/sdd_0.xml';

	private bool $isEncrypted;

	private bool $isConnected = false;

	private string|null $subscriptionId = null;

	private bool $subscriptionCreated = false;

	private bool|null $screenState = null;

	private Socket\ServerInterface|null $eventsServer = null;

	private Entities\API\Session|null $session = null;

	private Log\LoggerInterface $logger;

	public function __construct(
		private readonly string $identifier,
		private readonly string $ipAddress,
		private readonly int $port,
		private readonly string|null $appId,
		private readonly string|null $encryptionKey,
		private readonly string|null $macAddress,
		private readonly HttpClientFactory $httpClientFactory,
		private readonly EventLoop\LoopInterface $eventLoop,
		Log\LoggerInterface|null $logger = null,
	)
	{
		$this->isEncrypted = $this->appId !== null && $this->encryptionKey !== null;

		$this->logger = $logger ?? new Log\NullLogger();
	}

	/**
	 * @throws Exceptions\Encrypt
	 * @throws Exceptions\InvalidState
	 * @throws Exceptions\Decrypt
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 */
	public function connect(bool $subscribe = false): void
	{
		if ($this->encryptionKey !== null) {
			$this->deriveSessionKeys();
			$this->requestSessionId(false);
		}

		if ($subscribe) {
			$this->subscribeEvents();
		}

		$this->isConnected = true;
	}

	public function disconnect(): void
	{
		$this->session = null;
		$this->isConnected = false;

		$this->unsubscribeEvents();
	}

	public function isConnected(): bool
	{
		return $this->isConnected;
	}

	/**
	 * @return ($async is true ? Promise\ExtendedPromiseInterface|Promise\PromiseInterface : Entities\API\Session)
	 *
	 * @throws Exceptions\Encrypt
	 * @throws Exceptions\InvalidState
	 * @throws Exceptions\Decrypt
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 */
	public function requestSessionId(
		bool $async = true,
	): Promise\ExtendedPromiseInterface|Promise\PromiseInterface|Entities\API\Session
	{
		$deferred = new Promise\Deferred();

		if ($this->session === null) {
			if ($async) {
				return Promise\reject(new Exceptions\InvalidState('Session is not created'));
			}

			throw new Exceptions\InvalidState('Session is not created');
		}

		$encInfo = Transformer::encryptPayload(
			'<X_ApplicationId>' . $this->appId . '</X_ApplicationId>',
			$this->session->getKey(),
			$this->session->getIv(),
			$this->session->getHmacKey(),
		);

		$parameters = '';
		$parameters .= '<X_ApplicationId>' . $this->appId . '</X_ApplicationId>';
		$parameters .= '<X_EncInfo>' . $encInfo . '</X_EncInfo>';

		$result = $this->callXmlRequest(
			self::URL_CONTROL_NRC,
			self::URN_REMOTE_CONTROL,
			'X_GetEncryptSessionId',
			$parameters,
			'u',
			$async,
		);

		if ($result instanceof Promise\PromiseInterface) {
			$result
				->then(function (Message\ResponseInterface $response) use ($deferred): void {
					try {
						$body = $this->sanitizeReceivedPayload($response->getBody()->getContents());

						preg_match('/<X_EncResult>(?<encrypted>.*?)<\/X_EncResult>/', $body, $matches);
					} catch (Throwable $ex) {
						$deferred->reject(
							new Exceptions\TelevisionApiCall('Received response is not valid', $ex->getCode(), $ex),
						);

						return;
					}

					if (!array_key_exists('encrypted', $matches)) {
						$deferred->reject(new Exceptions\TelevisionApiCall('Could not parse received response'));

						return;
					}

					if ($this->session === null) {
						$deferred->reject(new Exceptions\TelevisionApiCall('Something went wrong. Session was lost'));

						return;
					}

					$payload = Transformer::decryptPayload(
						$matches['encrypted'],
						$this->session->getKey(),
						$this->session->getIv(),
						$this->session->getHmacKey(),
					);

					preg_match('/<X_SessionId>(?<session_id>.*?)<\/X_SessionId>/', $payload, $matches);

					if (array_key_exists('session_id', $matches)) {
						$this->session->setId($matches['session_id']);
					}

					$this->session->setSeqNum(1);

					$deferred->resolve($this->session);
				})
				->otherwise(static function (Throwable $ex) use ($deferred): void {
					$deferred->reject($ex);
				});

			return $deferred->promise();
		}

		if ($result === false) {
			throw new Exceptions\TelevisionApiCall('Could send data to television');
		}

		try {
			$body = $this->sanitizeReceivedPayload($result->getBody()->getContents());

			preg_match('/<X_EncResult>(?<encrypted>.*?)<\/X_EncResult>/', $body, $matches);
		} catch (Throwable $ex) {
			throw new Exceptions\TelevisionApiCall('Received response is not valid', $ex->getCode(), $ex);
		}

		if (!array_key_exists('encrypted', $matches)) {
			throw new Exceptions\TelevisionApiCall('Could not parse received response');
		}

		$payload = Transformer::decryptPayload(
			$matches['encrypted'],
			$this->session->getKey(),
			$this->session->getIv(),
			$this->session->getHmacKey(),
		);

		preg_match('/<X_SessionId>(?<session_id>.*?)<\/X_SessionId>/', $payload, $matches);

		if (array_key_exists('session_id', $matches)) {
			$this->session->setId($matches['session_id']);
		}

		$this->session->setSeqNum(1);

		return $this->session;
	}

	/**
	 * @return ($async is true ? Promise\ExtendedPromiseInterface|Promise\PromiseInterface : Entities\API\DeviceSpecs)
	 *
	 * @throws Exceptions\TelevisionApiCall
	 */
	public function getSpecs(
		bool $async = true,
	): Promise\ExtendedPromiseInterface|Promise\PromiseInterface|Entities\API\DeviceSpecs
	{
		$deferred = new Promise\Deferred();

		$result = $this->callRequest(
			'GET',
			$this->ipAddress . ':' . $this->port . self::URL_CONTROL_NRC_DDD,
			[],
			[],
			null,
			$async,
		);

		if ($result instanceof Promise\PromiseInterface) {
			$result
				->then(function (Message\ResponseInterface $response) use ($deferred): void {
					try {
						$specsResponse = simplexml_load_string(
							$this->sanitizeReceivedPayload($response->getBody()->getContents()),
						);

						if (
							!$specsResponse instanceof SimpleXMLElement
							|| !property_exists($specsResponse, 'device')
							|| !$specsResponse->device instanceof SimpleXMLElement
						) {
							$deferred->reject(new Exceptions\TelevisionApiCall('Received response is not valid'));
						} else {
							$device = EntityFactory::build(
								Entities\API\DeviceSpecs::class,
								$specsResponse->device,
							);

							$this->needsCrypto()
								->then(static function (bool $needsCrypto) use ($deferred, $device): void {
									$device->setRequiresEncryption($needsCrypto);

									$deferred->resolve($device);
								})
								->otherwise(static function (Throwable $ex) use ($deferred): void {
									$deferred->reject($ex);
								});
						}
					} catch (Throwable $ex) {
						$deferred->reject(
							new Exceptions\TelevisionApiCall('Received response is not valid', $ex->getCode(), $ex),
						);
					}
				})
				->otherwise(static function (Throwable $ex) use ($deferred): void {
					$deferred->reject($ex);
				});

			return $deferred->promise();
		}

		if ($result === false) {
			throw new Exceptions\TelevisionApiCall('Could send data to television');
		}

		try {
			$specsResponse = simplexml_load_string($this->sanitizeReceivedPayload($result->getBody()->getContents()));
		} catch (Throwable $ex) {
			throw new Exceptions\TelevisionApiCall('Received response is not valid', $ex->getCode(), $ex);
		}

		if (
			!$specsResponse instanceof SimpleXMLElement
			|| !property_exists($specsResponse, 'device')
			|| !$specsResponse->device instanceof SimpleXMLElement
		) {
			throw new Exceptions\TelevisionApiCall('Received response is not valid');
		}

		try {
			$device = EntityFactory::build(Entities\API\DeviceSpecs::class, $specsResponse->device);
		} catch (Exceptions\InvalidState $ex) {
			throw new Exceptions\TelevisionApiCall('Received response is not valid', $ex->getCode(), $ex);
		}

		$device->setRequiresEncryption($this->needsCrypto(false));

		return $device;
	}

	/**
	 * @return ($async is true ? Promise\ExtendedPromiseInterface|Promise\PromiseInterface : Entities\API\DeviceApps)
	 *
	 * @throws Exceptions\Decrypt
	 * @throws Exceptions\InvalidState
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 */
	public function getApps(
		bool $async = true,
	): Promise\ExtendedPromiseInterface|Promise\PromiseInterface|Entities\API\DeviceApps
	{
		$deferred = new Promise\Deferred();

		if ($this->isEncrypted && $this->session === null) {
			if ($async) {
				return Promise\reject(new Exceptions\InvalidState('Session is not created'));
			}

			throw new Exceptions\InvalidState('Session is not created');
		}

		$result = $this->callXmlRequest(
			self::URL_CONTROL_NRC,
			self::URN_REMOTE_CONTROL,
			'X_GetAppList',
			'None',
			'u',
			$async,
		);

		if ($result instanceof Promise\PromiseInterface) {
			$result
				->then(function (Message\ResponseInterface $response) use ($deferred): void {
					try {
						$body = $this->sanitizeReceivedPayload($response->getBody()->getContents());

						if (
							$this->session !== null
							&& preg_match('/<X_EncResult>(?<encrypted>.*?)<\/X_EncResult>/', $body, $matches) === 1
						) {
							if (!array_key_exists('encrypted', $matches)) {
								$deferred->reject(
									new Exceptions\TelevisionApiCall('Could not parse received response'),
								);

								return;
							}

							$payload = Transformer::decryptPayload(
								$matches['encrypted'],
								$this->session->getKey(),
								$this->session->getIv(),
								$this->session->getHmacKey(),
							);

							$appsResponse = simplexml_load_string(
								$this->sanitizeReceivedPayload($payload),
							);

							if (
								!$appsResponse instanceof SimpleXMLElement
								|| !property_exists($appsResponse, 'X_GetAppListResponse')
								|| !$appsResponse->X_GetAppListResponse instanceof SimpleXMLElement
								|| !property_exists($appsResponse->X_GetAppListResponse, 'X_AppList')
							) {
								$deferred->reject(new Exceptions\TelevisionApiCall('Received response is not valid'));

								return;
							}

							$appsRaw = strval($appsResponse->X_GetAppListResponse->X_AppList);

						} else {
							$appsResponse = simplexml_load_string(
								$this->sanitizeReceivedPayload($body),
							);

							if (
								!$appsResponse instanceof SimpleXMLElement
								|| !property_exists($appsResponse, 'Body')
								|| !$appsResponse->Body instanceof SimpleXMLElement
								|| !property_exists($appsResponse->Body, 'X_GetAppListResponse')
								|| !$appsResponse->Body->X_GetAppListResponse instanceof SimpleXMLElement
								|| !property_exists($appsResponse->Body->X_GetAppListResponse, 'X_AppList')
							) {
								$deferred->reject(new Exceptions\TelevisionApiCall('Received response is not valid'));

								return;
							}

							$appsRaw = strval($appsResponse->Body->X_GetAppListResponse->X_AppList);
						}

						if ($appsRaw === '') {
							$deferred->reject(
								new Exceptions\TelevisionApiCall('Television is turned off. Apps could not be loaded'),
							);

							return;
						}

						if (preg_match_all(
							"/'product_id=(?<id>[\dA-Z]+)'(?<name>[^']+)/u",
							$appsRaw,
							$matches,
						) === false) {
							$deferred->reject(new Exceptions\TelevisionApiCall('Received response is not valid'));

							return;
						}

						$apps = [];

						foreach (array_combine($matches['id'], $matches['name']) as $appId => $appName) {
							$apps[] = new Entities\API\Application($appId, $appName);
						}

						$deferred->resolve(new Entities\API\DeviceApps($apps));
					} catch (Throwable $ex) {
						$deferred->reject(
							new Exceptions\TelevisionApiCall('Received response is not valid', $ex->getCode(), $ex),
						);
					}
				})
				->otherwise(static function (Throwable $ex) use ($deferred): void {
					$deferred->reject($ex);
				});

			return $deferred->promise();
		}

		if ($result === false) {
			throw new Exceptions\TelevisionApiCall('Could send data to television');
		}

		try {
			$body = $this->sanitizeReceivedPayload($result->getBody()->getContents());
		} catch (Throwable $ex) {
			throw new Exceptions\TelevisionApiCall('Received response is not valid', $ex->getCode(), $ex);
		}

		if (
			$this->session !== null
			&& preg_match('/<X_EncResult>(?<encrypted>.*?)<\/X_EncResult>/', $body, $matches) === 1
		) {
			if (!array_key_exists('encrypted', $matches)) {
				throw new Exceptions\TelevisionApiCall('Could not parse received response');
			}

			$payload = Transformer::decryptPayload(
				$matches['encrypted'],
				$this->session->getKey(),
				$this->session->getIv(),
				$this->session->getHmacKey(),
			);

			$appsResponse = simplexml_load_string(
				$this->sanitizeReceivedPayload($payload),
			);

			if (
				!$appsResponse instanceof SimpleXMLElement
				|| !property_exists($appsResponse, 'X_GetAppListResponse')
				|| !$appsResponse->X_GetAppListResponse instanceof SimpleXMLElement
				|| !property_exists($appsResponse->X_GetAppListResponse, 'X_AppList')
			) {
				throw new Exceptions\TelevisionApiCall('Received response is not valid');
			}

			$appsRaw = strval($appsResponse->X_GetAppListResponse->X_AppList);

		} else {
			$appsResponse = simplexml_load_string(
				$this->sanitizeReceivedPayload($body),
			);

			if (
				!$appsResponse instanceof SimpleXMLElement
				|| !property_exists($appsResponse, 'Body')
				|| !$appsResponse->Body instanceof SimpleXMLElement
				|| !property_exists($appsResponse->Body, 'X_GetAppListResponse')
				|| !$appsResponse->Body->X_GetAppListResponse instanceof SimpleXMLElement
				|| !property_exists($appsResponse->Body->X_GetAppListResponse, 'X_AppList')
			) {
				throw new Exceptions\TelevisionApiCall('Received response is not valid');
			}

			$appsRaw = strval($appsResponse->Body->X_GetAppListResponse->X_AppList);
		}

		if ($appsRaw === '') {
			throw new Exceptions\TelevisionApiCall('Television is turned off. Apps could not be loaded');
		}

		if (preg_match_all("/'product_id=(?<id>[\dA-Z]+)'(?<name>[^']+)/u", $appsRaw, $matches) === false) {
			throw new Exceptions\TelevisionApiCall('Received response is not valid');
		}

		$apps = [];

		foreach (array_combine($matches['id'], $matches['name']) as $appId => $appName) {
			$apps[] = new Entities\API\Application($appId, $appName);
		}

		return new Entities\API\DeviceApps($apps);
	}

	/**
	 * @return ($async is true ? Promise\ExtendedPromiseInterface|Promise\PromiseInterface : Entities\API\DeviceVectorInfo)
	 *
	 * @throws Exceptions\Decrypt
	 * @throws Exceptions\InvalidState
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 */
	public function getVectorInfo(
		bool $async = true,
	): Promise\ExtendedPromiseInterface|Promise\PromiseInterface|Entities\API\DeviceVectorInfo
	{
		$deferred = new Promise\Deferred();

		if ($this->isEncrypted && $this->session === null) {
			if ($async) {
				return Promise\reject(new Exceptions\InvalidState('Session is not created'));
			}

			throw new Exceptions\InvalidState('Session is not created');
		}

		$result = $this->callXmlRequest(
			self::URL_CONTROL_NRC,
			self::URN_REMOTE_CONTROL,
			'X_GetVectorInfo',
			'None',
			'u',
			$async,
		);

		if ($result instanceof Promise\PromiseInterface) {
			$result
				->then(function (Message\ResponseInterface $response) use ($deferred): void {
					try {
						$body = $this->sanitizeReceivedPayload($response->getBody()->getContents());

						if (
							$this->session !== null
							&& preg_match('/<X_EncResult>(?<encrypted>.*?)<\/X_EncResult>/', $body, $matches) === 1
						) {
							if (!array_key_exists('encrypted', $matches)) {
								$deferred->reject(
									new Exceptions\TelevisionApiCall('Could not parse received response'),
								);

								return;
							}

							$payload = Transformer::decryptPayload(
								$matches['encrypted'],
								$this->session->getKey(),
								$this->session->getIv(),
								$this->session->getHmacKey(),
							);

							$vectorInfoResponse = simplexml_load_string(
								$this->sanitizeReceivedPayload($payload),
							);

							if (
								!$vectorInfoResponse instanceof SimpleXMLElement
								|| !property_exists($vectorInfoResponse, 'X_GetVectorInfoResponse')
								|| !$vectorInfoResponse->X_GetVectorInfoResponse instanceof SimpleXMLElement
								|| !property_exists($vectorInfoResponse->X_GetVectorInfoResponse, 'X_PortNumber')
							) {
								$deferred->reject(new Exceptions\TelevisionApiCall('Received response is not valid'));

								return;
							}

							$devicePort = intval($vectorInfoResponse->X_GetVectorInfoResponse->X_PortNumber);

						} else {
							$vectorInfoResponse = simplexml_load_string(
								$this->sanitizeReceivedPayload($body),
							);

							if (
								!$vectorInfoResponse instanceof SimpleXMLElement
								|| !property_exists($vectorInfoResponse, 'Body')
								|| !$vectorInfoResponse->Body instanceof SimpleXMLElement
								|| !property_exists($vectorInfoResponse->Body, 'X_GetVectorInfoResponse')
								|| !$vectorInfoResponse->Body->X_GetVectorInfoResponse instanceof SimpleXMLElement
								|| !property_exists($vectorInfoResponse->Body->X_GetVectorInfoResponse, 'X_PortNumber')
							) {
								$deferred->reject(new Exceptions\TelevisionApiCall('Received response is not valid'));

								return;
							}

							$devicePort = intval($vectorInfoResponse->Body->X_GetVectorInfoResponse->X_PortNumber);
						}

						$deferred->resolve(new Entities\API\DeviceVectorInfo($devicePort));
					} catch (Throwable $ex) {
						$deferred->reject(
							new Exceptions\TelevisionApiCall('Received response is not valid', $ex->getCode(), $ex),
						);
					}
				})
				->otherwise(static function (Throwable $ex) use ($deferred): void {
					$deferred->reject($ex);
				});

			return $deferred->promise();
		}

		if ($result === false) {
			throw new Exceptions\TelevisionApiCall('Could send data to television');
		}

		try {
			$body = $this->sanitizeReceivedPayload($result->getBody()->getContents());
		} catch (Throwable $ex) {
			throw new Exceptions\TelevisionApiCall('Received response is not valid', $ex->getCode(), $ex);
		}

		if (
			$this->session !== null
			&& preg_match('/<X_EncResult>(?<encrypted>.*?)<\/X_EncResult>/', $body, $matches) === 1
		) {
			if (!array_key_exists('encrypted', $matches)) {
				throw new Exceptions\TelevisionApiCall('Could not parse received response');
			}

			$payload = Transformer::decryptPayload(
				$matches['encrypted'],
				$this->session->getKey(),
				$this->session->getIv(),
				$this->session->getHmacKey(),
			);

			$vectorInfoResponse = simplexml_load_string(
				$this->sanitizeReceivedPayload($payload),
			);

			if (
				!$vectorInfoResponse instanceof SimpleXMLElement
				|| !property_exists($vectorInfoResponse, 'X_GetVectorInfoResponse')
				|| !$vectorInfoResponse->X_GetVectorInfoResponse instanceof SimpleXMLElement
				|| !property_exists($vectorInfoResponse->X_GetVectorInfoResponse, 'X_PortNumber')
			) {
				throw new Exceptions\TelevisionApiCall('Received response is not valid');
			}

			$devicePort = intval($vectorInfoResponse->X_GetVectorInfoResponse->X_PortNumber);

		} else {
			$vectorInfoResponse = simplexml_load_string(
				$this->sanitizeReceivedPayload($body),
			);

			if (
				!$vectorInfoResponse instanceof SimpleXMLElement
				|| !property_exists($vectorInfoResponse, 'Body')
				|| !$vectorInfoResponse->Body instanceof SimpleXMLElement
				|| !property_exists($vectorInfoResponse->Body, 'X_GetVectorInfoResponse')
				|| !$vectorInfoResponse->Body->X_GetVectorInfoResponse instanceof SimpleXMLElement
				|| !property_exists($vectorInfoResponse->Body->X_GetVectorInfoResponse, 'X_PortNumber')
			) {
				throw new Exceptions\TelevisionApiCall('Received response is not valid');
			}

			$devicePort = intval($vectorInfoResponse->Body->X_GetVectorInfoResponse->X_PortNumber);
		}

		return new Entities\API\DeviceVectorInfo($devicePort);
	}

	/**
	 * @return ($async is true ? Promise\ExtendedPromiseInterface|Promise\PromiseInterface : int)
	 *
	 * @throws Exceptions\Decrypt
	 * @throws Exceptions\InvalidState
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 */
	public function getVolume(
		bool $async = true,
	): Promise\ExtendedPromiseInterface|Promise\PromiseInterface|int
	{
		$deferred = new Promise\Deferred();

		if ($this->isEncrypted && $this->session === null) {
			if ($async) {
				return Promise\reject(new Exceptions\InvalidState('Session is not created'));
			}

			throw new Exceptions\InvalidState('Session is not created');
		}

		$result = $this->callXmlRequest(
			self::URL_CONTROL_DMR,
			self::URN_RENDERING_CONTROL,
			'GetVolume',
			'<InstanceID>0</InstanceID><Channel>Master</Channel>',
			'u',
			$async,
		);

		if ($result instanceof Promise\PromiseInterface) {
			$result
				->then(function (Message\ResponseInterface $response) use ($deferred): void {
					try {
						$body = $this->sanitizeReceivedPayload($response->getBody()->getContents());

						if (
							$this->session !== null
							&& preg_match('/<X_EncResult>(?<encrypted>.*?)<\/X_EncResult>/', $body, $matches) === 1
						) {
							if (!array_key_exists('encrypted', $matches)) {
								$deferred->reject(
									new Exceptions\TelevisionApiCall('Could not parse received response'),
								);

								return;
							}

							$payload = Transformer::decryptPayload(
								$matches['encrypted'],
								$this->session->getKey(),
								$this->session->getIv(),
								$this->session->getHmacKey(),
							);

							if (
								preg_match('/<CurrentVolume>(?<volume>.*?)<\/CurrentVolume>/', $payload, $matches) !== 1
								|| !array_key_exists('volume', $matches)
							) {
								$deferred->reject(new Exceptions\TelevisionApiCall('Received response is not valid'));

								return;
							}

							$deferred->resolve(intval($matches['volume']));
						} else {
							if (
								preg_match('/<CurrentVolume>(?<volume>.*?)<\/CurrentVolume>/', $body, $matches) !== 1
								|| !array_key_exists('volume', $matches)
							) {
								$deferred->reject(new Exceptions\TelevisionApiCall('Received response is not valid'));

								return;
							}

							$deferred->resolve(intval($matches['volume']));
						}
					} catch (Throwable $ex) {
						$deferred->reject(
							new Exceptions\TelevisionApiCall('Received response is not valid', $ex->getCode(), $ex),
						);
					}
				})
				->otherwise(static function (Throwable $ex) use ($deferred): void {
					$deferred->reject($ex);
				});

			return $deferred->promise();
		}

		if ($result === false) {
			throw new Exceptions\TelevisionApiCall('Could send data to television');
		}

		try {
			$body = $this->sanitizeReceivedPayload($result->getBody()->getContents());
		} catch (Throwable $ex) {
			throw new Exceptions\TelevisionApiCall('Received response is not valid', $ex->getCode(), $ex);
		}

		if (
			$this->session !== null
			&& preg_match('/<X_EncResult>(?<encrypted>.*?)<\/X_EncResult>/', $body, $matches) === 1
		) {
			if (!array_key_exists('encrypted', $matches)) {
				throw new Exceptions\TelevisionApiCall('Could not parse received response');
			}

			$payload = Transformer::decryptPayload(
				$matches['encrypted'],
				$this->session->getKey(),
				$this->session->getIv(),
				$this->session->getHmacKey(),
			);

			if (
				preg_match('/<CurrentVolume>(?<volume>.*?)<\/CurrentVolume>/', $payload, $matches) !== 1
				|| !array_key_exists('volume', $matches)
			) {
				throw new Exceptions\TelevisionApiCall('Received response is not valid');
			}

			return intval($matches['volume']);
		}

		if (
			preg_match('/<CurrentVolume>(?<volume>.*?)<\/CurrentVolume>/', $body, $matches) !== 1
			|| !array_key_exists('volume', $matches)
		) {
			throw new Exceptions\TelevisionApiCall('Received response is not valid');
		}

		return intval($matches['volume']);
	}

	/**
	 * @return ($async is true ? Promise\ExtendedPromiseInterface|Promise\PromiseInterface : bool)
	 *
	 * @throws Exceptions\Decrypt
	 * @throws Exceptions\InvalidState
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 */
	public function setVolume(
		int $volume,
		bool $async = true,
	): Promise\ExtendedPromiseInterface|Promise\PromiseInterface|bool
	{
		$deferred = new Promise\Deferred();

		if ($volume < 0 || $volume > 100) {
			if ($async) {
				return Promise\reject(
					new Exceptions\InvalidState('Bad request to volume control. Volume must be between 0 and 100'),
				);
			}

			throw new Exceptions\InvalidState('Bad request to volume control. Volume must be between 0 and 100');
		}

		if ($this->isEncrypted && $this->session === null) {
			if ($async) {
				return Promise\reject(new Exceptions\InvalidState('Session is not created'));
			}

			throw new Exceptions\InvalidState('Session is not created');
		}

		$result = $this->callXmlRequest(
			self::URL_CONTROL_DMR,
			self::URN_RENDERING_CONTROL,
			'SetVolume',
			sprintf('<InstanceID>0</InstanceID><Channel>Master</Channel><DesiredVolume>%d</DesiredVolume>', $volume),
			'u',
			$async,
		);

		if ($result instanceof Promise\PromiseInterface) {
			$result
				->then(static function () use ($deferred): void {
					$deferred->resolve(true);
				})
				->otherwise(static function (Throwable $ex) use ($deferred): void {
					$deferred->reject($ex);
				});

			return $deferred->promise();
		}

		if ($result === false) {
			throw new Exceptions\TelevisionApiCall('Could send data to television');
		}

		return true;
	}

	/**
	 * @return ($async is true ? Promise\ExtendedPromiseInterface|Promise\PromiseInterface : bool)
	 *
	 * @throws Exceptions\Decrypt
	 * @throws Exceptions\InvalidState
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 */
	public function getMute(
		bool $async = true,
	): Promise\ExtendedPromiseInterface|Promise\PromiseInterface|bool
	{
		$deferred = new Promise\Deferred();

		if ($this->isEncrypted && $this->session === null) {
			if ($async) {
				return Promise\reject(new Exceptions\InvalidState('Session is not created'));
			}

			throw new Exceptions\InvalidState('Session is not created');
		}

		$result = $this->callXmlRequest(
			self::URL_CONTROL_DMR,
			self::URN_RENDERING_CONTROL,
			'GetMute',
			'<InstanceID>0</InstanceID><Channel>Master</Channel>',
			'u',
			$async,
		);

		if ($result instanceof Promise\PromiseInterface) {
			$result
				->then(function (Message\ResponseInterface $response) use ($deferred): void {
					try {
						$body = $this->sanitizeReceivedPayload($response->getBody()->getContents());

						if (
							$this->session !== null
							&& preg_match('/<X_EncResult>(?<encrypted>.*?)<\/X_EncResult>/', $body, $matches) === 1
						) {
							if (!array_key_exists('encrypted', $matches)) {
								$deferred->reject(
									new Exceptions\TelevisionApiCall('Could not parse received response'),
								);

								return;
							}

							$payload = Transformer::decryptPayload(
								$matches['encrypted'],
								$this->session->getKey(),
								$this->session->getIv(),
								$this->session->getHmacKey(),
							);

							if (
								preg_match('/<CurrentMute>(?<mute>.*?)<\/CurrentMute>/', $payload, $matches) !== 1
								|| !array_key_exists('mute', $matches)
							) {
								$deferred->reject(new Exceptions\TelevisionApiCall('Received response is not valid'));

								return;
							}

							$deferred->resolve(boolval($matches['mute']));
						} else {
							if (
								preg_match('/<CurrentMute>(?<mute>.*?)<\/CurrentMute>/', $body, $matches) !== 1
								|| !array_key_exists('mute', $matches)
							) {
								$deferred->reject(new Exceptions\TelevisionApiCall('Received response is not valid'));

								return;
							}

							$deferred->resolve(boolval($matches['mute']));
						}
					} catch (Throwable $ex) {
						$deferred->reject(
							new Exceptions\TelevisionApiCall('Received response is not valid', $ex->getCode(), $ex),
						);
					}
				})
				->otherwise(static function (Throwable $ex) use ($deferred): void {
					$deferred->reject($ex);
				});

			return $deferred->promise();
		}

		if ($result === false) {
			throw new Exceptions\TelevisionApiCall('Could send data to television');
		}

		try {
			$body = $this->sanitizeReceivedPayload($result->getBody()->getContents());
		} catch (Throwable $ex) {
			throw new Exceptions\TelevisionApiCall('Received response is not valid', $ex->getCode(), $ex);
		}

		if (
			$this->session !== null
			&& preg_match('/<X_EncResult>(?<encrypted>.*?)<\/X_EncResult>/', $body, $matches) === 1
		) {
			if (!array_key_exists('encrypted', $matches)) {
				throw new Exceptions\TelevisionApiCall('Could not parse received response');
			}

			$payload = Transformer::decryptPayload(
				$matches['encrypted'],
				$this->session->getKey(),
				$this->session->getIv(),
				$this->session->getHmacKey(),
			);

			if (
				preg_match('/<CurrentMute>(?<mute>.*?)<\/CurrentMute>/', $payload, $matches) !== 1
				|| !array_key_exists('mute', $matches)
			) {
				throw new Exceptions\TelevisionApiCall('Received response is not valid');
			}

			return boolval($matches['mute']);
		}

		if (
			preg_match('/<CurrentMute>(?<mute>.*?)<\/CurrentMute>/', $body, $matches) !== 1
			|| !array_key_exists('mute', $matches)
		) {
			throw new Exceptions\TelevisionApiCall('Received response is not valid');
		}

		return boolval($matches['mute']);
	}

	/**
	 * @return ($async is true ? Promise\ExtendedPromiseInterface|Promise\PromiseInterface : bool)
	 *
	 * @throws Exceptions\Decrypt
	 * @throws Exceptions\InvalidState
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 */
	public function setMute(
		bool $status,
		bool $async = true,
	): Promise\ExtendedPromiseInterface|Promise\PromiseInterface|bool
	{
		$deferred = new Promise\Deferred();

		if ($this->isEncrypted && $this->session === null) {
			if ($async) {
				return Promise\reject(new Exceptions\InvalidState('Session is not created'));
			}

			throw new Exceptions\InvalidState('Session is not created');
		}

		$result = $this->callXmlRequest(
			self::URL_CONTROL_DMR,
			self::URN_RENDERING_CONTROL,
			'SetMute',
			sprintf(
				'<InstanceID>0</InstanceID><Channel>Master</Channel><DesiredMute>%d</DesiredMute>',
				$status ? 1 : 0,
			),
			'u',
			$async,
		);

		if ($result instanceof Promise\PromiseInterface) {
			$result
				->then(static function () use ($deferred): void {
					$deferred->resolve(true);
				})
				->otherwise(static function (Throwable $ex) use ($deferred): void {
					$deferred->reject($ex);
				});

			return $deferred->promise();
		}

		if ($result === false) {
			throw new Exceptions\TelevisionApiCall('Could send data to television');
		}

		return true;
	}

	/**
	 * @return ($async is true ? Promise\ExtendedPromiseInterface|Promise\PromiseInterface : bool)
	 *
	 * @throws Exceptions\Decrypt
	 * @throws Exceptions\InvalidState
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 */
	public function sendKey(
		Types\ActionKey|string $key,
		bool $async = true,
	): Promise\ExtendedPromiseInterface|Promise\PromiseInterface|bool
	{
		$deferred = new Promise\Deferred();

		if ($this->isEncrypted && $this->session === null) {
			if ($async) {
				return Promise\reject(new Exceptions\InvalidState('Session is not created'));
			}

			throw new Exceptions\InvalidState('Session is not created');
		}

		$result = $this->callXmlRequest(
			self::URL_CONTROL_NRC,
			self::URN_REMOTE_CONTROL,
			'X_SendKey',
			sprintf('<X_KeyEvent>%s</X_KeyEvent>', is_string($key) ? $key : strval($key->getValue())),
			'u',
			$async,
		);

		if ($result instanceof Promise\PromiseInterface) {
			$result
				->then(static function () use ($deferred): void {
					$deferred->resolve(true);
				})
				->otherwise(static function (Throwable $ex) use ($deferred): void {
					$deferred->reject($ex);
				});

			return $deferred->promise();
		}

		if ($result === false) {
			throw new Exceptions\TelevisionApiCall('Could send data to television');
		}

		return true;
	}

	/**
	 * @return ($async is true ? Promise\ExtendedPromiseInterface|Promise\PromiseInterface : bool)
	 *
	 * @throws Exceptions\Decrypt
	 * @throws Exceptions\InvalidState
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 */
	public function launchApplication(
		string $application,
		bool $async = true,
	): Promise\ExtendedPromiseInterface|Promise\PromiseInterface|bool
	{
		$deferred = new Promise\Deferred();

		if ($this->isEncrypted && $this->session === null) {
			if ($async) {
				return Promise\reject(new Exceptions\InvalidState('Session is not created'));
			}

			throw new Exceptions\InvalidState('Session is not created');
		}

		$result = $this->callXmlRequest(
			self::URL_CONTROL_NRC,
			self::URN_REMOTE_CONTROL,
			'X_LaunchApp',
			sprintf(
				'<X_AppType>vc_app</X_AppType><X_LaunchKeyword>%s_id=%s</X_LaunchKeyword>',
				strlen($application) === 16 ? 'product' : 'resource',
				$application,
			),
			'u',
			$async,
		);

		if ($result instanceof Promise\PromiseInterface) {
			$result
				->then(static function () use ($deferred): void {
					$deferred->resolve(true);
				})
				->otherwise(static function (Throwable $ex) use ($deferred): void {
					$deferred->reject($ex);
				});

			return $deferred->promise();
		}

		if ($result === false) {
			throw new Exceptions\TelevisionApiCall('Could send data to television');
		}

		return true;
	}

	public function turnOn(): Promise\ExtendedPromiseInterface|Promise\PromiseInterface
	{
		$deferred = new Promise\Deferred();

		try {
			$this->isTurnedOn()
				->then(function (bool $status) use ($deferred): void {
					if ($status !== false) {
						$deferred->resolve(true);
					} else {
						if ($this->macAddress !== null) {
							$this->wakeOnLan()
								->then(static function () use ($deferred): void {
									$deferred->resolve(true);
								})
								->otherwise(static function (Throwable $ex) use ($deferred): void {
									$deferred->reject($ex);
								});

						} else {
							$this->sendKey(Types\ActionKey::get(Types\ActionKey::POWER))
								->then(static function () use ($deferred): void {
									$deferred->resolve(true);
								})
								->otherwise(static function (Throwable $ex) use ($deferred): void {
									$deferred->reject($ex);
								});
						}
					}
				})
				->otherwise(static function (Throwable $ex) use ($deferred): void {
					$deferred->reject($ex);
				});

			return $deferred->promise();
		} catch (Throwable $ex) {
			return Promise\reject($ex);
		}
	}

	public function turnOff(): Promise\ExtendedPromiseInterface|Promise\PromiseInterface
	{
		$deferred = new Promise\Deferred();

		try {
			$this->isTurnedOn()
				->then(function (bool $status) use ($deferred): void {
					if ($status === false) {
						$deferred->resolve(true);
					} else {
						$this->sendKey(Types\ActionKey::get(Types\ActionKey::POWER))
							->then(static function () use ($deferred): void {
								$deferred->resolve(true);
							})
							->otherwise(static function (Throwable $ex) use ($deferred): void {
								$deferred->reject($ex);
							});
					}
				})
				->otherwise(static function (Throwable $ex) use ($deferred): void {
					$deferred->reject($ex);
				});

			return $deferred->promise();
		} catch (Throwable $ex) {
			return Promise\reject($ex);
		}
	}

	/**
	 * @return ($async is true ? Promise\ExtendedPromiseInterface|Promise\PromiseInterface : bool)
	 *
	 * @throws Exceptions\TelevisionApiCall
	 */
	public function needsCrypto(
		bool $async = true,
	): Promise\ExtendedPromiseInterface|Promise\PromiseInterface|bool
	{
		$deferred = new Promise\Deferred();

		$result = $this->callRequest(
			'GET',
			$this->ipAddress . ':' . $this->port . self::URL_CONTROL_NRC_DEF,
			[],
			[],
			null,
			$async,
		);

		if ($result instanceof Promise\PromiseInterface) {
			$result
				->then(static function (Message\ResponseInterface $response) use ($deferred): void {
					$deferred->resolve(
						preg_match('/X_GetEncryptSessionId/u', $response->getBody()->getContents()) === 1,
					);
				})
				->otherwise(static function (Throwable $ex) use ($deferred): void {
					$deferred->reject($ex);
				});

			return $deferred->promise();
		}

		if ($result === false) {
			throw new Exceptions\TelevisionApiCall('Could send data to television');
		}

		try {
			return preg_match('/X_GetEncryptSessionId/u', $result->getBody()->getContents()) === 1;
		} catch (Throwable $ex) {
			throw new Exceptions\TelevisionApiCall('Received response is not valid', $ex->getCode(), $ex);
		}
	}

	/**
	 * @return ($runLoop is false ? Promise\ExtendedPromiseInterface|Promise\PromiseInterface : bool)
	 *
	 * @throws InvalidArgumentException
	 */
	public function livenessProbe(
		float $timeout = 1.5,
		bool $runLoop = false,
	): Promise\ExtendedPromiseInterface|Promise\PromiseInterface|bool
	{
		$deferred = new Promise\Deferred();

		$connector = new Socket\Connector([
			'dns' => '8.8.8.8',
			'timeout' => 10,
			'tls' => [
				'verify_peer' => false,
				'verify_peer_name' => false,
				'check_hostname' => false,
			],
		]);

		$result = false;

		$timeoutTimer = $this->eventLoop->addTimer($timeout, function () use ($deferred, $runLoop, &$result): void {
			$deferred->resolve(false);
			$result = false;

			if ($runLoop) {
				$this->eventLoop->stop();
			}
		});

		$connector->connect($this->ipAddress . ':' . $this->port)
			->then(function () use ($deferred, $timeoutTimer, $runLoop, &$result): void {
				$this->eventLoop->cancelTimer($timeoutTimer);

				$deferred->resolve(true);
				$result = true;

				if ($runLoop) {
					$this->eventLoop->stop();
				}
			})
			->otherwise(function () use ($deferred, $runLoop, &$result): void {
				$deferred->resolve(false);
				$result = false;

				if ($runLoop) {
					$this->eventLoop->stop();
				}
			});

		if ($runLoop) {
			$this->eventLoop->run();

			return $result;
		} else {
			return $deferred->promise();
		}
	}

	/**
	 * @return ($runLoop is false ? Promise\ExtendedPromiseInterface|Promise\PromiseInterface : bool)
	 */
	public function isTurnedOn(bool $runLoop = false): Promise\ExtendedPromiseInterface|Promise\PromiseInterface|bool
	{
		if ($this->subscriptionCreated && $this->screenState !== null) {
			if ($runLoop) {
				return $this->screenState;
			}

			return Promise\resolve($this->screenState);
		}

		$deferred = new Promise\Deferred();

		$result = false;

		$this->on('event-data', function (Entities\API\Event $event) use ($deferred, $runLoop, &$result): void {
			if ($event->getScreenState() !== null) {
				$deferred->resolve($event->getScreenState());
				$result = $event->getScreenState();
			} else {
				$deferred->resolve(false);
				$result = false;
			}

			if ($runLoop) {
				$this->eventLoop->stop();
			}
		});

		$this->on('event-error', function () use ($deferred, $runLoop, &$result): void {
			$deferred->resolve(false);
			$result = false;

			if ($runLoop) {
				$this->eventLoop->stop();
			}
		});

		$doUnsubscribe = false;

		if (!$this->subscriptionCreated) {
			$doUnsubscribe = true;

			$subscribeResult = $this->subscribeEvents();

			if ($subscribeResult === false) {
				if ($runLoop) {
					return false;
				}

				return Promise\resolve(false);
			}
		}

		$this->eventLoop->addTimer(1.5, function () use ($deferred, $runLoop, &$result, $doUnsubscribe): void {
			if ($doUnsubscribe) {
				$this->unsubscribeEvents();
			}

			$deferred->resolve(false);
			$result = false;

			if ($runLoop) {
				$this->eventLoop->stop();
			}
		});

		if ($runLoop) {
			$this->eventLoop->run();

			return $result;
		}

		return $deferred->promise();
	}

	/**
	 * @return ($async is true ? Promise\ExtendedPromiseInterface|Promise\PromiseInterface : string)
	 *
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 */
	public function requestPinCode(
		string $name,
		bool $async = true,
	): Promise\ExtendedPromiseInterface|Promise\PromiseInterface|string
	{
		$deferred = new Promise\Deferred();

		// First let's ask for a pin code and get a challenge key back
		$parameters = '<X_DeviceName>' . $name . '</X_DeviceName>';

		$result = $this->callXmlRequest(
			self::URL_CONTROL_NRC,
			self::URN_REMOTE_CONTROL,
			'X_DisplayPinCode',
			$parameters,
			'u',
			$async,
		);

		if ($result instanceof Promise\PromiseInterface) {
			$result
				->then(function (Message\ResponseInterface $response) use ($deferred): void {
					try {
						$pinCodeResponse = simplexml_load_string(
							$this->sanitizeReceivedPayload($response->getBody()->getContents()),
						);

						if (
							!$pinCodeResponse instanceof SimpleXMLElement
							|| !property_exists($pinCodeResponse, 'Body')
							|| !$pinCodeResponse->Body instanceof SimpleXMLElement
							|| !property_exists($pinCodeResponse->Body, 'X_DisplayPinCodeResponse')
							|| !$pinCodeResponse->Body->X_DisplayPinCodeResponse instanceof SimpleXMLElement
							|| !property_exists($pinCodeResponse->Body->X_DisplayPinCodeResponse, 'X_ChallengeKey')
						) {
							$deferred->reject(new Exceptions\TelevisionApiCall('Received response is not valid'));
						} else {
							$deferred->resolve(
								strval($pinCodeResponse->Body->X_DisplayPinCodeResponse->X_ChallengeKey),
							);
						}
					} catch (Throwable $ex) {
						$deferred->reject(
							new Exceptions\TelevisionApiCall('Received response is not valid', $ex->getCode(), $ex),
						);
					}
				})
				->otherwise(static function (Throwable $ex) use ($deferred): void {
					$deferred->reject($ex);
				});

			return $deferred->promise();
		}

		if ($result === false) {
			throw new Exceptions\TelevisionApiCall('Could not send request to television');
		}

		try {
			$pinCodeResponse = simplexml_load_string($this->sanitizeReceivedPayload($result->getBody()->getContents()));
		} catch (Throwable $ex) {
			throw new Exceptions\TelevisionApiCall('Received response is not valid', $ex->getCode(), $ex);
		}

		if (
			!$pinCodeResponse instanceof SimpleXMLElement
			|| !property_exists($pinCodeResponse, 'Body')
			|| !$pinCodeResponse->Body instanceof SimpleXMLElement
			|| !property_exists($pinCodeResponse->Body, 'X_DisplayPinCodeResponse')
			|| !$pinCodeResponse->Body->X_DisplayPinCodeResponse instanceof SimpleXMLElement
			|| !property_exists($pinCodeResponse->Body->X_DisplayPinCodeResponse, 'X_ChallengeKey')
		) {
			throw new Exceptions\TelevisionApiCall('Received response is not valid');
		}

		return strval($pinCodeResponse->Body->X_DisplayPinCodeResponse->X_ChallengeKey);
	}

	/**
	 * @return ($async is true ? Promise\ExtendedPromiseInterface|Promise\PromiseInterface : Entities\API\AuthorizePinCode)
	 *
	 * @throws Exceptions\Encrypt
	 * @throws Exceptions\Decrypt
	 * @throws Exceptions\TelevisionApiCall
	 * @throws RuntimeException
	 */
	public function authorizePinCode(
		string $pinCode,
		string $challengeKey,
		bool $async = true,
	): Promise\ExtendedPromiseInterface|Promise\PromiseInterface|Entities\API\AuthorizePinCode
	{
		$deferred = new Promise\Deferred();

		$iv = unpack('C*', strval(base64_decode($challengeKey, true)));

		if ($iv === false) {
			if ($async) {
				return Promise\reject(new Exceptions\TelevisionApiCall('Pairing challenge key could not be parsed'));
			}

			throw new Exceptions\TelevisionApiCall('Pairing challenge key could not be parsed');
		}

		/** @var array<int> $iv */
		$iv = array_values($iv);

		/** @var array<int> $key */
		$key = array_fill(0, 16, 0);

		$i = 0;

		while ($i < 16) {
			$key[$i] = ~$iv[$i + 3] & 0xFF;
			$key[$i + 1] = ~$iv[$i + 2] & 0xFF;
			$key[$i + 2] = ~$iv[$i + 1] & 0xFF;
			$key[$i + 3] = ~$iv[$i] & 0xFF;

			$i += 4;
		}

		// Derive HMAC key from IV & HMAC key mask (taken from libtvconnect.so)
		$hmacKeyMaskValues = [
			0x15,0xC9,0x5A,0xC2,0xB0,0x8A,0xA7,0xEB,0x4E,0x22,0x8F,0x81,0x1E,
			0x34,0xD0,0x4F,0xA5,0x4B,0xA7,0xDC,0xAC,0x98,0x79,0xFA,0x8A,0xCD,
			0xA3,0xFC,0x24,0x4F,0x38,0x54,
		];

		/** @var array<int> $hmacKey */
		$hmacKey = array_fill(0, Transformer::SIGNATURE_BYTES_LENGTH, 0);

		$i = 0;

		while ($i < Transformer::SIGNATURE_BYTES_LENGTH) {
			$hmacKey[$i] = $hmacKeyMaskValues[$i] ^ $iv[$i + 2 & 0xF];
			$hmacKey[$i + 1] = $hmacKeyMaskValues[$i + 1] ^ $iv[$i + 3 & 0xF];
			$hmacKey[$i + 2] = $hmacKeyMaskValues[$i + 2] ^ $iv[$i & 0xF];
			$hmacKey[$i + 3] = $hmacKeyMaskValues[$i + 3] ^ $iv[$i + 1 & 0xF];

			$i += 4;
		}

		// Encrypt X_PinCode argument and send it within an X_AuthInfo tag
		$payload = Transformer::encryptPayload(
			'<X_PinCode>' . $pinCode . '</X_PinCode>',
			pack('C*', ...$key),
			pack('C*', ...$iv),
			pack('C*', ...$hmacKey),
		);

		// First let's ask for a pin code and get a challenge key back
		$parameters = '<X_AuthInfo>' . $payload . '</X_AuthInfo>';

		$result = $this->callXmlRequest(
			self::URL_CONTROL_NRC,
			self::URN_REMOTE_CONTROL,
			'X_RequestAuth',
			$parameters,
			'u',
			$async,
		);

		if ($result instanceof Promise\PromiseInterface) {
			$result
				->then(
					function (Message\ResponseInterface $response) use ($deferred, $key, $iv, $hmacKey): void {
						try {
							$body = $this->sanitizeReceivedPayload($response->getBody()->getContents());

							preg_match('/<X_AuthResult>(?<encrypted>.*?)<\/X_AuthResult>/', $body, $matches);
						} catch (Throwable $ex) {
							$deferred->reject(
								new Exceptions\TelevisionApiCall('Received response is not valid', $ex->getCode(), $ex),
							);

							return;
						}

						if (!array_key_exists('encrypted', $matches)) {
							$deferred->reject(new Exceptions\TelevisionApiCall('Could not parse received response'));

							return;
						}

						$payload = Transformer::decryptPayload(
							$matches['encrypted'],
							pack('C*', ...$key),
							pack('C*', ...$iv),
							pack('C*', ...$hmacKey),
						);

						$appId = $encryptionKey = null;

						preg_match('/<X_ApplicationId>(?<app_id>.*?)<\/X_ApplicationId>/', $payload, $matches);

						if (array_key_exists('app_id', $matches)) {
							$appId = $matches['app_id'];
						}

						preg_match('/<X_Keyword>(?<encryption_key>.*?)<\/X_Keyword>/', $payload, $matches);

						if (array_key_exists('encryption_key', $matches)) {
							$encryptionKey = $matches['encryption_key'];
						}

						$deferred->resolve(new Entities\API\AuthorizePinCode(
							$appId,
							$encryptionKey,
						));
					},
				)
				->otherwise(static function (Throwable $ex) use ($deferred): void {
					$deferred->reject($ex);
				});

			return $deferred->promise();
		}

		if ($result === false) {
			throw new Exceptions\TelevisionApiCall('Could not send request to television');
		}

		try {
			$body = $this->sanitizeReceivedPayload($result->getBody()->getContents());

			preg_match('/<X_AuthResult>(?<encrypted>.*?)<\/X_AuthResult>/', $body, $matches);
		} catch (Throwable $ex) {
			throw new Exceptions\TelevisionApiCall('Received response is not valid', $ex->getCode(), $ex);
		}

		if (!array_key_exists('encrypted', $matches)) {
			throw new Exceptions\TelevisionApiCall('Could not parse received response');
		}

		$payload = Transformer::decryptPayload(
			$matches['encrypted'],
			pack('C*', ...$key),
			pack('C*', ...$iv),
			pack('C*', ...$hmacKey),
		);

		$appId = $encryptionKey = null;

		preg_match('/<X_ApplicationId>(?<app_id>.*?)<\/X_ApplicationId>/', $payload, $matches);

		if (array_key_exists('app_id', $matches)) {
			$appId = $matches['app_id'];
		}

		preg_match('/<X_Keyword>(?<encryption_key>.*?)<\/X_Keyword>/', $payload, $matches);

		if (array_key_exists('encryption_key', $matches)) {
			$encryptionKey = $matches['encryption_key'];
		}

		return new Entities\API\AuthorizePinCode($appId, $encryptionKey);
	}

	public function wakeOnLan(): Promise\ExtendedPromiseInterface|Promise\PromiseInterface
	{
		$deferred = new Promise\Deferred();

		$mac = $this->macAddress;

		if ($mac === null) {
			return Promise\reject(new Exceptions\InvalidArgument('Television MAC address have to be configured'));
		}

		$datagramFactory = new Datagram\Factory($this->eventLoop);

		$datagramFactory->createClient(sprintf('%s:%d', $this->ipAddress, 9))
			->then(static function (Datagram\SocketInterface $socket) use ($mac, $deferred): void {
				$socket->pause();

				if (strlen($mac) === 12) {
					// No separators => add colons in between
					$mac = implode(':', str_split($mac, 2));
				} elseif (strpos($mac, '-') !== false) {
					// Hyphen separators => replace with colons
					$mac = str_replace('-', ':', $mac);
				}

				$mac = strtoupper($mac);

				if (preg_match('/^(?:[A-F0-9]{2}\:){5}[A-F0-9]{2}$/', $mac) === false) {
					$deferred->reject(new Exceptions\InvalidArgument('Invalid mac address given'));

					return;
				}

				$address = '';

				foreach (explode(':', $mac) as $part) {
					$address .= chr(intval(hexdec($part)));
				}

				$socket->send("\xFF\xFF\xFF\xFF\xFF\xFF" . str_repeat($address, 16));

				$deferred->resolve(true);
			})
			->otherwise(static function (Throwable $ex) use ($deferred): void {
				$deferred->reject($ex);
			});

		return $deferred->promise();
	}

	private function subscribeEvents(): bool
	{
		if ($this->eventsServer !== null) {
			return true;
		}

		try {
			$this->eventsServer = new Socket\SocketServer('0.0.0.0:0');
		} catch (RuntimeException | InvalidArgumentException) {
			return false;
		}

		$this->subscriptionCreated = true;

		$this->eventsServer->on(
			'connection',
			function (Socket\ConnectionInterface $connection): void {
				$connection->on('data', function (string $data) use ($connection): void {
					$parts = preg_split('/\r?\n\r?\n/', $data);

					$inputMode = null;

					if (is_array($parts) && count($parts) === 2) {
						preg_match(
							'/<X_ScreenState>(?<screen_state>\w+)<\/X_ScreenState>/',
							strval($parts[1]),
							$matches,
						);

						if (array_key_exists('screen_state', $matches)) {
							$this->screenState = Utils\Strings::lower($matches['screen_state']) === 'on';
						}

						preg_match(
							'/<X_InputMode>(?<input_mode>\w+)<\/X_InputMode>/',
							strval($parts[1]),
							$matches,
						);

						if (array_key_exists('input_mode', $matches)) {
							$inputMode = Utils\Strings::lower($matches['input_mode']);
						}
					}

					$this->emit('event-data', [new Entities\API\Event($this->screenState, $inputMode)]);

					$connection->write(
						"HTTP/1.1 200 OK\r\nContent-Type: text/xml; charset=\"utf-8\"\r\nContent-Length: 0\r\n\r\n",
					);
				});

				$connection->on('error', function (Throwable $ex): void {
					$this->logger->error('Something went wrong with subscription socket', [
						'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
						'type' => 'television-api',
						'exception' => BootstrapHelpers\Logger::buildException($ex),
						'connector' => [
							'identifier' => $this->identifier,
						],
					]);

					$this->emit('event-error', [$ex]);
				});
			},
		);

		preg_match(
			'/(?<protocol>tcp):\/\/(?<ip_address>[0-9]+.[0-9]+.[0-9]+.[0-9]+)?:(?<port>[0-9]+)?/',
			strval($this->eventsServer->getAddress()),
			$matches,
		);

		try {
			$client = $this->httpClientFactory->createClient(false);
		} catch (InvalidArgumentException $ex) {
			$this->logger->error('Could not get http client', [
				'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
				'type' => 'television-api',
				'exception' => BootstrapHelpers\Logger::buildException($ex),
				'connector' => [
					'identifier' => $this->identifier,
				],
			]);

			return false;
		}

		$localIpAddress = Helpers\Network::getLocalAddress();

		if ($localIpAddress === null) {
			$this->logger->error('Could not get connector local address', [
				'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
				'type' => 'television-api',
				'connector' => [
					'identifier' => $this->identifier,
				],
			]);

			return false;
		}

		$localPort = $matches['port'];

		$this->subscriptionId = null;

		try {
			$response = $client->request(
				'SUBSCRIBE',
				'http://' . $this->ipAddress . ':' . $this->port . self::URL_EVENT_NRC,
				[
					GuzzleHttp\RequestOptions::HEADERS => [
						'CALLBACK' => '<http://' . $localIpAddress . ':' . $localPort . '>',
						'NT' => 'upnp:event',
						'TIMEOUT' => 'Second-' . self::EVENTS_TIMEOUT,
					],
				],
			);

			$sidHeader = $response->getHeader('SID');

			if ($sidHeader !== []) {
				$this->subscriptionId = array_pop($sidHeader);
			}
		} catch (GuzzleHttp\Exception\GuzzleException) {
			$this->eventsServer->close();

			$this->subscriptionCreated = false;
		}

		return true;
	}

	private function unsubscribeEvents(): void
	{
		$this->subscriptionCreated = false;

		if ($this->subscriptionId === null) {
			return;
		}

		try {
			$client = $this->httpClientFactory->createClient(false);
		} catch (InvalidArgumentException $ex) {
			$this->logger->error('Could not get http client', [
				'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
				'type' => 'television-api',
				'exception' => BootstrapHelpers\Logger::buildException($ex),
				'connector' => [
					'identifier' => $this->identifier,
				],
			]);

			$this->eventsServer?->close();

			return;
		}

		try {
			$client->request(
				'UNSUBSCRIBE',
				'http://' . $this->ipAddress . ':' . $this->port . self::URL_EVENT_NRC,
				[
					GuzzleHttp\RequestOptions::HEADERS => [
						'SID' => $this->subscriptionId,
					],
					GuzzleHttp\RequestOptions::TIMEOUT => 1,
				],
			);
		} catch (GuzzleHttp\Exception\GuzzleException) {
			// Error could be ignored
		} finally {
			$this->eventsServer?->close();
		}
	}

	/**
	 * @param array<string, mixed> $headers
	 * @param array<string, mixed> $params
	 *
	 * @return ($async is true ? Promise\ExtendedPromiseInterface|Promise\PromiseInterface : Message\ResponseInterface|false)
	 */
	private function callRequest(
		string $method,
		string $requestPath,
		array $headers = [],
		array $params = [],
		string|null $body = null,
		bool $async = true,
	): Promise\ExtendedPromiseInterface|Promise\PromiseInterface|Message\ResponseInterface|false
	{
		$deferred = new Promise\Deferred();

		$this->logger->debug(sprintf(
			'Request: method = %s url = %s',
			$method,
			$requestPath,
		), [
			'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
			'type' => 'television-api',
			'request' => [
				'method' => $method,
				'url' => $requestPath,
				'headers' => $headers,
				'params' => $params,
				'body' => $body,
			],
			'connector' => [
				'identifier' => $this->identifier,
			],
		]);

		if (count($params) > 0) {
			$requestPath .= '?';
			$requestPath .= http_build_query($params);
		}

		if ($async) {
			try {
				$request = $this->httpClientFactory->createClient($async)->request(
					$method,
					$requestPath,
					$headers,
					$body ?? '',
				);

				$request
					->then(
						function (Message\ResponseInterface $response) use ($deferred, $method, $requestPath, $headers, $params, $body): void {
							try {
								$responseBody = $response->getBody()->getContents();

								$response->getBody()->rewind();
							} catch (RuntimeException $ex) {
								throw new Exceptions\TelevisionApiCall(
									'Could not get content from response body',
									$ex->getCode(),
									$ex,
								);
							}

							$this->logger->debug('Received response', [
								'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
								'type' => 'television-api',
								'request' => [
									'method' => $method,
									'url' => $requestPath,
									'headers' => $headers,
									'params' => $params,
									'body' => $body,
								],
								'response' => [
									'status_code' => $response->getStatusCode(),
									'body' => $responseBody,
								],
								'connector' => [
									'identifier' => $this->identifier,
								],
							]);

							$deferred->resolve($response);
						},
						function (Throwable $ex) use ($deferred, $method, $requestPath, $params, $body): void {
							$this->logger->error('Calling api endpoint failed', [
								'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
								'type' => 'television-api',
								'exception' => BootstrapHelpers\Logger::buildException($ex),
								'request' => [
									'method' => $method,
									'url' => $requestPath,
									'params' => $params,
									'body' => $body,
								],
								'connector' => [
									'identifier' => $this->identifier,
								],
							]);

							$deferred->reject($ex);
						},
					);
			} catch (Throwable $ex) {
				return Promise\reject($ex);
			}

			return $deferred->promise();
		} else {
			try {
				$response = $this->httpClientFactory->createClient(false)->request(
					$method,
					$requestPath,
					[
						'headers' => $headers,
						'body' => $body ?? '',
					],
				);

				try {
					$responseBody = $response->getBody()->getContents();

					$response->getBody()->rewind();
				} catch (RuntimeException $ex) {
					throw new Exceptions\TelevisionApiCall(
						'Could not get content from response body',
						$ex->getCode(),
						$ex,
					);
				}

				$this->logger->debug('Received response', [
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'television-api',
					'request' => [
						'method' => $method,
						'url' => $requestPath,
						'headers' => $headers,
						'params' => $params,
						'body' => $body,
					],
					'response' => [
						'status_code' => $response->getStatusCode(),
						'body' => $responseBody,
					],
					'connector' => [
						'identifier' => $this->identifier,
					],
				]);

				return $response;
			} catch (GuzzleHttp\Exception\GuzzleException | InvalidArgumentException $ex) {
				$this->logger->error('Calling api endpoint failed', [
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'television-api',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
					'request' => [
						'method' => $method,
						'url' => $requestPath,
						'params' => $params,
						'body' => $body,
					],
					'connector' => [
						'identifier' => $this->identifier,
					],
				]);

				return false;
			} catch (Exceptions\TelevisionApiCall $ex) {
				$this->logger->error('Received payload is not valid', [
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'television-api',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
					'request' => [
						'method' => $method,
						'url' => $requestPath,
						'params' => $params,
						'body' => $body,
					],
					'connector' => [
						'identifier' => $this->identifier,
					],
				]);

				return false;
			}
		}
	}

	/**
	 * @return ($async is true ? Promise\ExtendedPromiseInterface|Promise\PromiseInterface : Message\ResponseInterface|false)
	 *
	 * @throws Exceptions\Encrypt
	 * @throws RuntimeException
	 */
	private function callXmlRequest(
		string $url,
		string $urn,
		string $action,
		string $parameters,
		string $bodyElement,
		bool $async = true,
	): Promise\ExtendedPromiseInterface|Promise\PromiseInterface|Message\ResponseInterface|false
	{
		$deferred = new Promise\Deferred();

		$this->logger->debug(sprintf(
			'Request: url = %s urn = %s',
			$url,
			$urn,
		), [
			'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
			'type' => 'television-api',
			'request' => [
				'url' => $url,
				'urn' => $urn,
				'action' => $action,
				'parameters' => $parameters,
			],
			'connector' => [
				'identifier' => $this->identifier,
			],
		]);

		if (
			$this->isEncrypted
			&& $urn === self::URN_REMOTE_CONTROL
			&& (
				$action !== 'X_GetEncryptSessionId' && $action !== 'X_DisplayPinCode' && $action !== 'X_RequestAuth'
			)
			&& $this->session !== null
		) {
			$this->session->incrementSeqNum();

			$command = '';
			$command .= '<X_SessionId>' . $this->session->getId() . '</X_SessionId>';
			$command .= '<X_SequenceNumber>';
			$command .= substr('00000000' . $this->session->getSeqNum(), -8);
			$command .= '</X_SequenceNumber>';
			$command .= '<X_OriginalCommand>';
			$command .= '<' . $bodyElement . ':' . $action . ' xmlns:' . $bodyElement . '="urn:' . $urn . '">';
			$command .= $parameters;
			$command .= '</' . $bodyElement . ':' . $action . '>';
			$command .= '</X_OriginalCommand>';

			$encryptedCommand = Transformer::encryptPayload(
				$command,
				$this->session->getKey(),
				$this->session->getIv(),
				$this->session->getHmacKey(),
			);

			$action = 'X_EncryptedCommand';

			$parameters = '';
			$parameters .= '<X_ApplicationId>' . $this->appId . '</X_ApplicationId>';
			$parameters .= '<X_EncInfo>' . $encryptedCommand . '</X_EncInfo>';
		}

		$body = '';
		$body .= '<?xml version="1.0" encoding="utf-8"?>';
		$body .= '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">';
		$body .= '<s:Body>';
		$body .= '<' . $bodyElement . ':' . $action . ' xmlns:' . $bodyElement . '="urn:' . $urn . '">';
		$body .= $parameters;
		$body .= '</' . $bodyElement . ':' . $action . '>';
		$body .= '</s:Body>';
		$body .= '</s:Envelope>';

		$headers = [
			'Content-Length' => strlen($body),
			'Content-Type' => 'text/xml; charset="utf-8"',
			'SOAPAction' => '"urn:' . $urn . '#' . $action . '"',
			'Cache-Control' => 'no-cache',
			'Pragma' => 'no-cache',
			'Accept' => 'text/xml',
		];

		if ($async) {
			try {
				$request = $this->httpClientFactory->createClient()->post(
					'http://' . $this->ipAddress . ':' . $this->port . $url,
					$headers,
					$body,
				);

				$request
					->then(
						function (Message\ResponseInterface $response) use ($deferred, $url, $urn, $action, $parameters): void {
							try {
								$responseBody = $response->getBody()->getContents();

								$response->getBody()->rewind();
							} catch (RuntimeException $ex) {
								throw new Exceptions\TelevisionApiCall(
									'Could not get content from response body',
									$ex->getCode(),
									$ex,
								);
							}

							$this->logger->debug('Received response', [
								'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
								'type' => 'television-api',
								'request' => [
									'url' => $url,
									'urn' => $urn,
									'action' => $action,
									'parameters' => $parameters,
								],
								'response' => [
									'status_code' => $response->getStatusCode(),
									'body' => $responseBody,
								],
								'connector' => [
									'identifier' => $this->identifier,
								],
							]);

							$deferred->resolve($response);
						},
						function (Throwable $ex) use ($deferred, $url, $urn, $action, $parameters): void {
							$this->logger->error('Calling api endpoint failed', [
								'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
								'type' => 'television-api',
								'exception' => BootstrapHelpers\Logger::buildException($ex),
								'request' => [
									'url' => $url,
									'urn' => $urn,
									'action' => $action,
									'parameters' => $parameters,
								],
								'connector' => [
									'identifier' => $this->identifier,
								],
							]);

							$deferred->reject($ex);
						},
					);
			} catch (Throwable $ex) {
				return Promise\reject($ex);
			}

			return $deferred->promise();
		} else {
			try {
				$response = $this->httpClientFactory->createClient(false)->post(
					$this->ipAddress . ':' . $this->port . $url,
					[
						'headers' => $headers,
						'body' => $body,
					],
				);

				try {
					$responseBody = $response->getBody()->getContents();

					$response->getBody()->rewind();
				} catch (RuntimeException $ex) {
					throw new Exceptions\TelevisionApiCall(
						'Could not get content from response body',
						$ex->getCode(),
						$ex,
					);
				}

				$this->logger->debug('Received response', [
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'television-api',
					'request' => [
						'url' => $url,
						'urn' => $urn,
						'action' => $action,
						'parameters' => $parameters,
					],
					'response' => [
						'status_code' => $response->getStatusCode(),
						'body' => $responseBody,
					],
					'connector' => [
						'identifier' => $this->identifier,
					],
				]);

				return $response;
			} catch (GuzzleHttp\Exception\GuzzleException | InvalidArgumentException $ex) {
				$this->logger->error('Calling api endpoint failed', [
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'television-api',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
					'request' => [
						'url' => $url,
						'urn' => $urn,
						'action' => $action,
						'parameters' => $parameters,
					],
					'connector' => [
						'identifier' => $this->identifier,
					],
				]);

				return false;
			} catch (Exceptions\TelevisionApiCall $ex) {
				$this->logger->error('Received payload is not valid', [
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'television-api',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
					'request' => [
						'url' => $url,
						'urn' => $urn,
						'action' => $action,
						'parameters' => $parameters,
					],
					'connector' => [
						'identifier' => $this->identifier,
					],
				]);

				return false;
			}
		}
	}

	private function deriveSessionKeys(): void
	{
		if ($this->encryptionKey === null) {
			return;
		}

		$iv = unpack('C*', strval(base64_decode($this->encryptionKey, true)));

		if ($iv === false) {
			return;
		}

		/** @var array<int> $iv */
		$iv = array_values($iv);

		/** @var array<int> $sessionKey */
		$sessionKey = array_fill(0, 16, 0);

		$i = 0;

		while ($i < 16) {
			$sessionKey[$i] = $iv[$i + 2];
			$sessionKey[$i + 1] = $iv[$i + 3];
			$sessionKey[$i + 2] = $iv[$i];
			$sessionKey[$i + 3] = $iv[$i + 1];

			$i += 4;
		}

		$this->session = new Entities\API\Session(
			pack('C*', ...$sessionKey),
			// Derive key from IV
			pack('C*', ...$iv),
			// HMAC key for comms is just the IV repeated twice
			pack('C*', ...array_merge($iv, $iv)),
		);
	}

	private function sanitizeReceivedPayload(string $payload): string
	{
		$sanitized = preg_replace('/<(\/?)\w+:(\w+\/?) ?(\w+:\w+.*)?>/', '<$1$2>', $payload);

		if (!is_string($sanitized)) {
			return $payload;
		}

		return $sanitized;
	}

}
