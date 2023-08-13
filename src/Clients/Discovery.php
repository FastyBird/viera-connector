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

use Clue\React\Multicast;
use Evenement;
use FastyBird\Connector\Viera;
use FastyBird\Connector\Viera\API;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Connector\Viera\Queue;
use FastyBird\Library\Bootstrap\Helpers as BootstrapHelpers;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use Nette;
use React\Datagram;
use React\EventLoop;
use RuntimeException;
use SplObjectStorage;
use Throwable;
use function array_key_exists;
use function array_map;
use function count;
use function is_array;
use function parse_url;
use function preg_match;
use function React\Async\async;
use function React\Async\await;
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
final class Discovery implements Evenement\EventEmitterInterface
{

	use Nette\SmartObject;
	use Evenement\EventEmitterTrait;

	private const MCAST_HOST = '239.255.255.250';

	private const MCAST_PORT = 1_900;

	private const SEARCH_TIMEOUT = 5;

	private const MATCH_DEVICE_LOCATION = '/LOCATION:\s(?<location>[\da-zA-Z:\/.]+)/';

	private const MATCH_DEVICE_ID = '/USN:\suuid:(?<usn>[\da-zA-Z-]+)::urn/';

	/** @var SplObjectStorage<Entities\Clients\DiscoveredDevice, null> */
	private SplObjectStorage $discoveredLocalDevices;

	private EventLoop\TimerInterface|null $handlerTimer = null;

	private Datagram\SocketInterface|null $sender = null;

	public function __construct(
		private readonly Entities\VieraConnector $connector,
		private readonly API\TelevisionApiFactory $televisionApiFactory,
		private readonly Queue\Queue $queue,
		private readonly Helpers\Entity $entityHelper,
		private readonly Viera\Logger $logger,
		private readonly Multicast\Factory $serverFactory,
		private readonly EventLoop\LoopInterface $eventLoop,
	)
	{
		$this->discoveredLocalDevices = new SplObjectStorage();
	}

	public function discover(): void
	{
		$this->discoveredLocalDevices = new SplObjectStorage();

		$this->logger->debug(
			'Starting devices discovery',
			[
				'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
				'type' => 'discovery-client',
			],
		);

		try {
			$this->sender = $this->serverFactory->createSender();

		} catch (Throwable $ex) {
			$this->logger->error(
				'Could not create discovery server',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'discovery-client',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
				],
			);

			return;
		}

		$this->sender->on('message', async(function (string $data): void {
			if (
				preg_match(self::MATCH_DEVICE_LOCATION, $data, $matches) === 1
				&& array_key_exists('location', $matches)
			) {
				$urlParts = parse_url($matches['location']);

				if (
					is_array($urlParts)
					&& array_key_exists('host', $urlParts)
					&& preg_match(self::MATCH_DEVICE_ID, $data, $matches) === 1
					&& array_key_exists('usn', $matches)
				) {
					$this->handleDiscoveredDevice(
						$matches['usn'],
						$urlParts['host'],
						array_key_exists(
							'port',
							$urlParts,
						) ? $urlParts['port'] : Entities\VieraDevice::DEFAULT_PORT,
					);
				}
			}
		}));

		// Searching timeout
		$this->handlerTimer = $this->eventLoop->addTimer(
			self::SEARCH_TIMEOUT,
			function (): void {
				$this->sender?->close();

				$this->discoveredLocalDevices->rewind();

				$devices = [];

				foreach ($this->discoveredLocalDevices as $device) {
					$devices[] = $device;
				}

				$this->discoveredLocalDevices = new SplObjectStorage();

				if (count($devices) > 0) {
					$this->handleFoundLocalDevices($devices);
				}

				$this->emit('finished', [$devices]);
			},
		);

		$data = "M-SEARCH * HTTP/1.1\r\n";
		$data .= 'HOST: ' . sprintf('%s:%d', self::MCAST_HOST, self::MCAST_PORT) . "\r\n";
		$data .= "MAN: \"ssdp:discover\"\r\n";
		$data .= "ST: urn:panasonic-com:service:p00NetworkControl:1\r\n";
		$data .= "MX: 1\r\n";
		$data .= "\r\n";

		$this->sender->send($data, sprintf('%s:%d', self::MCAST_HOST, self::MCAST_PORT));
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
			} catch (Throwable $ex) {
				$this->logger->error(
					'Checking TV status failed',
					[
						'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
						'type' => 'discovery-client',
						'exception' => BootstrapHelpers\Logger::buildException($ex),
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
						'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
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
		} catch (Exceptions\TelevisionApiCall $ex) {
			$this->logger->error(
				'Calling device api failed',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'discovery-client',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
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
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'discovery-client',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
				],
			);

			return;
		}

		$this->discoveredLocalDevices->attach(
			$this->entityHelper->create(
				Entities\Clients\DiscoveredDevice::class,
				[
					'identifier' => $id,
					'ip_address' => $host,
					'port' => $port,
					'name' => $specs->getFriendlyName() ?? $specs->getModelName(),
					'model' => trim(sprintf('%s %s', $specs->getModelName(), $specs->getModelNumber())),
					'manufacturer' => $specs->getManufacturer(),
					'serial_number' => $specs->getSerialNumber(),
					'encrypted' => $needsAuthorization,
					'applications' => $apps !== null ? array_map(
						static fn (Entities\API\Application $application): array => [
							'id' => $application->getId(),
							'name' => $application->getName(),
						],
						$apps->getApps(),
					) : [],
				],
			),
		);
	}

	/**
	 * @param array<Entities\Clients\DiscoveredDevice> $devices
	 *
	 * @throws Exceptions\Runtime
	 */
	private function handleFoundLocalDevices(array $devices): void
	{
		foreach ($devices as $device) {
			$this->queue->append(
				$this->entityHelper->create(
					Entities\Messages\StoreDevice::class,
					[
						'connector' => $this->connector->getId()->toString(),
						'identifier' => $device->getIdentifier(),
						'ip_address' => $device->getIpAddress(),
						'port' => $device->getPort(),
						'name' => $device->getName(),
						'model' => $device->getModel(),
						'manufacturer' => $device->getManufacturer(),
						'serial_number' => $device->getSerialNumber(),
						'mac_address' => null,
						'encrypted' => $device->isEncrypted(),
						'app_id' => null,
						'encryption_key' => null,
						'hdmi' => [],
						'applications' => array_map(
							static fn (Entities\Clients\DeviceApplication $application): array => [
								'id' => $application->getId(),
								'name' => $application->getName(),
							],
							$device->getApplications(),
						),
					],
				),
			);
		}
	}

}
