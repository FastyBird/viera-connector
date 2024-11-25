<?php declare(strict_types = 1);

/**
 * Discovery.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Clients
 * @since          1.0.0
 *
 * @date           27.08.22
 */

namespace FastyBird\Connector\Viera\Clients;

use FastyBird\Connector\Viera;
use FastyBird\Connector\Viera\API;
use FastyBird\Connector\Viera\Documents;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Connector\Viera\Queue;
use FastyBird\Connector\Viera\Services;
use FastyBird\Connector\Viera\ValueObjects;
use FastyBird\Core\Tools\Helpers as ToolsHelpers;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Events as DevicesEvents;
use Nette;
use Orisai\ObjectMapper;
use Psr\EventDispatcher as PsrEventDispatcher;
use React\Datagram;
use React\EventLoop;
use RuntimeException;
use Throwable;
use function array_key_exists;
use function array_map;
use function is_array;
use function parse_url;
use function preg_match;
use function React\Async\async;
use function React\Async\await;
use function serialize;
use function sprintf;
use function strval;
use function trim;

/**
 * Devices discovery client
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Clients
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class Discovery
{

	use Nette\SmartObject;

	private const MCAST_HOST = '239.255.255.250';

	private const MCAST_PORT = 1_900;

	private const SEARCH_TIMEOUT = 5;

	private const PROCESS_RESULTS_TIMER = 0.1;

	private const MATCH_DEVICE_LOCATION = '/LOCATION:\s(?<location>[\da-zA-Z:\/.]+)/';

	private const MATCH_DEVICE_ID = '/USN:\suuid:(?<usn>[\da-zA-Z-]+)::urn/';

	/** @var array<string, ValueObjects\LocalDevice> */
	private array $searchResult = [];

	/** @var array<string, ValueObjects\LocalDevice>  */
	private array $processedItems = [];

	private EventLoop\TimerInterface|null $handlerTimer = null;

	private Datagram\SocketInterface|null $sender = null;

	public function __construct(
		private readonly Documents\Connectors\Connector $connector,
		private readonly API\TelevisionApiFactory $televisionApiFactory,
		private readonly Queue\Queue $queue,
		private readonly Helpers\MessageBuilder $messageBuilder,
		private readonly Viera\Logger $logger,
		private readonly Services\MulticastFactory $multicastFactory,
		private readonly EventLoop\LoopInterface $eventLoop,
		private readonly ObjectMapper\Processing\Processor $objectMapper,
		private readonly PsrEventDispatcher\EventDispatcherInterface|null $dispatcher = null,
	)
	{
	}

	/**
	 * @throws RuntimeException
	 */
	public function discover(): void
	{
		$this->logger->debug(
			'Starting devices discovery',
			[
				'source' => MetadataTypes\Sources\Connector::VIERA->value,
				'type' => 'discovery-client',
			],
		);

		try {
			$this->sender = $this->multicastFactory->create();

		} catch (Throwable $ex) {
			$this->logger->error(
				'Could not create discovery server',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'discovery-client',
					'exception' => ToolsHelpers\Logger::buildException($ex),
				],
			);

			return;
		}

		$this->sender->on('message', function (string $data): void {
			if (preg_match(self::MATCH_DEVICE_LOCATION, $data, $matches) === 1) {
				$urlParts = parse_url($matches['location']);

				if (
					is_array($urlParts)
					&& array_key_exists('host', $urlParts)
					&& preg_match(self::MATCH_DEVICE_ID, $data, $matches) === 1
				) {
					try {
						$searchResult = $this->objectMapper->process(
							[
								'id' => $matches['usn'],
								'host' => $urlParts['host'],
								'port' => array_key_exists(
									'port',
									$urlParts,
								) ? $urlParts['port'] : Entities\Devices\Device::DEFAULT_PORT,
							],
							ValueObjects\LocalDevice::class,
						);

						if (!array_key_exists(serialize($searchResult), $this->searchResult)) {
							$this->searchResult[serialize($searchResult)] = $searchResult;
						}
					} catch (Throwable $ex) {
						$this->logger->error(
							'Received data could not be transformed to message',
							[
								'source' => MetadataTypes\Sources\Connector::VIERA->value,
								'type' => 'discovery-client',
								'exception' => ToolsHelpers\Logger::buildException($ex),
							],
						);
					}
				}
			}
		});

		// Processing handler
		$this->eventLoop->addPeriodicTimer(
			self::PROCESS_RESULTS_TIMER,
			async(function (): void {
				foreach ($this->searchResult as $item) {
					if (array_key_exists(serialize($item), $this->processedItems)) {
						continue;
					}

					$this->processedItems[serialize($item)] = $item;

					$this->handleDiscoveredDevice(
						$item->getId(),
						$item->getHost(),
						$item->getPort(),
					);
				}
			}),
		);

		// Searching timeout
		$this->handlerTimer = $this->eventLoop->addTimer(
			self::SEARCH_TIMEOUT,
			async(function (): void {
				$this->sender?->close();

				$this->dispatcher?->dispatch(
					new DevicesEvents\TerminateConnector(
						MetadataTypes\Sources\Connector::VIERA,
						'Devices discovery finished',
					),
				);
			}),
		);

		$data = "M-SEARCH * HTTP/1.1\r\n";
		$data .= 'HOST: ' . sprintf('%s:%d', self::MCAST_HOST, self::MCAST_PORT) . "\r\n";
		$data .= "MAN: \"ssdp:discover\"\r\n";
		$data .= "ST: urn:panasonic-com:service:p00NetworkControl:1\r\n";
		$data .= "MX: 1\r\n";
		$data .= "\r\n";

		$this->sender?->send($data, sprintf('%s:%d', self::MCAST_HOST, self::MCAST_PORT));
	}

	public function disconnect(): void
	{
		if ($this->handlerTimer !== null) {
			$this->eventLoop->cancelTimer($this->handlerTimer);
			$this->handlerTimer = null;
		}

		$this->sender?->close();
	}

	/**
	 * @throws Exceptions\Runtime
	 * @throws RuntimeException
	 */
	private function handleDiscoveredDevice(string $id, string $host, int $port): void
	{
		try {
			$televisionApi = $this->televisionApiFactory->create(
				$id,
				$host,
				$port,
			);
			$televisionApi->connect();

			try {
				$isOnline = await($televisionApi->livenessProbe());
			} catch (Exceptions\TelevisionApiError $ex) {
				$this->logger->error(
					'Checking TV status failed',
					[
						'source' => MetadataTypes\Sources\Connector::VIERA->value,
						'type' => 'discovery-client',
						'exception' => ToolsHelpers\Logger::buildException($ex),
						'device' => [
							'id' => $id,
							'host' => $host,
							'port' => $port,
						],
					],
				);

				return;
			}

			if ($isOnline === false) {
				$this->logger->error(
					sprintf('The provided IP: %s:%d address is unreachable.', $host, $port),
					[
						'source' => MetadataTypes\Sources\Connector::VIERA->value,
						'type' => 'discovery-client',
						'device' => [
							'id' => $id,
							'host' => $host,
							'port' => $port,
						],
					],
				);

				return;
			}

			$specs = $televisionApi->getSpecs(false);

			$needsAuthorization = false;
			$apps = null;

			if ($specs->isRequiresEncryption()) {
				$needsAuthorization = true;
			} else {
				$isTurnedOn = await($televisionApi->isTurnedOn());

				// Apps could be loaded only if TV is turned on
				if ($isTurnedOn === true) {
					$apps = $televisionApi->getApps(false);
				}
			}
		} catch (Exceptions\TelevisionApiError $ex) {
			$this->logger->error(
				'Preparing api request failed',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'discovery-client',
					'exception' => ToolsHelpers\Logger::buildException($ex),
				],
			);

			return;
		} catch (Exceptions\TelevisionApiCall $ex) {
			$this->logger->error(
				'Calling device api failed',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'discovery-client',
					'exception' => ToolsHelpers\Logger::buildException($ex),
					'request' => [
						'method' => $ex->getRequest()?->getMethod(),
						'url' => $ex->getRequest() !== null ? strval($ex->getRequest()->getUri()) : null,
						'body' => $ex->getRequest()?->getBody()->getContents(),
					],
					'response' => [
						'body' => $ex->getResponse()?->getBody()->getContents(),
					],
				],
			);

			return;
		} catch (Throwable $ex) {
			$this->logger->error(
				'Unhandled error occur',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'discovery-client',
					'exception' => ToolsHelpers\Logger::buildException($ex),
				],
			);

			return;
		}

		$this->queue->append(
			$this->messageBuilder->create(
				Queue\Messages\StoreDevice::class,
				[
					'connector' => $this->connector->getId(),
					'identifier' => $id,
					'ip_address' => $host,
					'port' => $port,
					'name' => $specs->getFriendlyName() ?? $specs->getModelName(),
					'model' => trim(sprintf('%s %s', $specs->getModelName(), $specs->getModelNumber())),
					'manufacturer' => $specs->getManufacturer(),
					'serial_number' => $specs->getSerialNumber(),
					'mac_address' => null,
					'encrypted' => $needsAuthorization,
					'app_id' => null,
					'encryption_key' => null,
					'hdmi' => [],
					'applications' => $apps !== null
						? array_map(
							static fn (API\Messages\Response\Application $application): array => [
								'id' => $application->getId(),
								'name' => $application->getName(),
							],
							$apps->getApps(),
						)
						: [],
				],
			),
		);
	}

}
