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
use FastyBird\Connector\Viera\API;
use FastyBird\Connector\Viera\Consumers;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Library\Bootstrap\Helpers as BootstrapHelpers;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use Nette;
use Psr\Log;
use React\Datagram;
use React\EventLoop;
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

	private Multicast\Factory $serverFactory;

	public function __construct(
		private readonly Entities\VieraConnector $connector,
		private readonly API\TelevisionApiFactory $televisionApiFactory,
		private readonly Consumers\Messages $consumer,
		private readonly EventLoop\LoopInterface $eventLoop,
		private readonly Log\LoggerInterface $logger = new Log\NullLogger(),
	)
	{
		$this->serverFactory = new Multicast\Factory($this->eventLoop);

		$this->discoveredLocalDevices = new SplObjectStorage();
	}

	public function discover(): void
	{
		$this->discoveredLocalDevices = new SplObjectStorage();

		$this->logger->debug(
			'Starting televisions discovery',
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
			async(function (): void {
				$this->sender?->close();

				$this->discoveredLocalDevices->rewind();

				$devices = [];

				foreach ($this->discoveredLocalDevices as $device) {
					$devices[] = $device;
				}

				$this->discoveredLocalDevices = new SplObjectStorage();

				if (count($devices) > 0) {
					$devices = $this->handleFoundLocalDevices($devices);
				}

				$this->emit('finished', [$devices]);
			}),
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
		} catch (Exceptions\TelevisionApiCall | Exceptions\Encrypt | Exceptions\Decrypt $ex) {
			$this->logger->error(
				'Calling television api failed',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'discovery-client',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
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

		$this->discoveredLocalDevices->attach(new Entities\Clients\DiscoveredDevice(
			$id,
			$host,
			$port,
			$specs->getFriendlyName() ?? $specs->getModelName(),
			trim(sprintf('%s %s', $specs->getModelName(), $specs->getModelNumber())),
			$specs->getManufacturer(),
			$specs->getSerialNumber(),
			$needsAuthorization,
			$apps !== null ? array_map(
			// phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
				static fn (Entities\API\Application $application): Entities\Clients\DeviceApplication => new Entities\Clients\DeviceApplication(
					$application->getId(),
					$application->getName(),
				),
				$apps->getApps(),
			) : [],
		));
	}

	/**
	 * @param array<Entities\Clients\DiscoveredDevice> $devices
	 *
	 * @return array<Entities\Messages\ConfigureDevice>
	 */
	private function handleFoundLocalDevices(array $devices): array
	{
		$processedDevices = [];

		foreach ($devices as $device) {
			$message = new Entities\Messages\ConfigureDevice(
				$this->connector->getId(),
				$device->getIdentifier(),
				$device->getIpAddress(),
				$device->getPort(),
				$device->getName(),
				$device->getModel(),
				$device->getManufacturer(),
				$device->getSerialNumber(),
				null,
				$device->isEncrypted(),
				null,
				null,
				[],
				array_map(
				// phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
					static fn (Entities\Clients\DeviceApplication $application): Entities\Messages\DeviceApplication => new Entities\Messages\DeviceApplication(
						$application->getId(),
						$application->getName(),
					),
					$device->getApplications(),
				),
			);

			$processedDevices[] = $message;

			$this->consumer->append($message);
		}

		return $processedDevices;
	}

}
