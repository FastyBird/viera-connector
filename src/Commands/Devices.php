<?php declare(strict_types = 1);

/**
 * Devices.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Commands
 * @since          1.0.0
 *
 * @date           18.06.23
 */

namespace FastyBird\Connector\Viera\Commands;

use DateTimeInterface;
use Doctrine\DBAL;
use Doctrine\Persistence;
use FastyBird\Connector\Viera;
use FastyBird\Connector\Viera\API;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Connector\Viera\Queries;
use FastyBird\Connector\Viera\Types;
use FastyBird\DateTimeFactory;
use FastyBird\Library\Bootstrap\Helpers as BootstrapHelpers;
use FastyBird\Library\Metadata\Exceptions as MetadataExceptions;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Entities as DevicesEntities;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use FastyBird\Module\Devices\Models as DevicesModels;
use FastyBird\Module\Devices\Queries as DevicesQueries;
use InvalidArgumentException as InvalidArgumentExceptionAlias;
use Nette\Localization;
use Nette\Utils;
use RuntimeException;
use Symfony\Component\Console;
use Symfony\Component\Console\Input;
use Symfony\Component\Console\Output;
use Symfony\Component\Console\Style;
use Throwable;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_search;
use function array_values;
use function assert;
use function count;
use function intval;
use function preg_match;
use function sprintf;
use function strval;
use function trim;
use function usort;

/**
 * Connector devices management command
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Commands
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class Devices extends Console\Command\Command
{

	public const NAME = 'fb:viera-connector:devices';
	// phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
	private const MATCH_IP_ADDRESS = '/^((?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])[.]){3}(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])$/';

	private const MATCH_MAC_ADDRESS = '/^([0-9a-fA-F][0-9a-fA-F]:){5}([0-9a-fA-F][0-9a-fA-F])$/';

	private string|null $challengeKey = null;

	public function __construct(
		private readonly API\TelevisionApiFactory $televisionApiFactory,
		private readonly Viera\Logger $logger,
		private readonly DevicesModels\Connectors\ConnectorsRepository $connectorsRepository,
		private readonly DevicesModels\Devices\DevicesRepository $devicesRepository,
		private readonly DevicesModels\Devices\DevicesManager $devicesManager,
		private readonly DevicesModels\Devices\Properties\PropertiesRepository $devicesPropertiesRepository,
		private readonly DevicesModels\Devices\Properties\PropertiesManager $devicesPropertiesManager,
		private readonly DevicesModels\Channels\ChannelsRepository $channelsRepository,
		private readonly DevicesModels\Channels\ChannelsManager $channelsManager,
		private readonly DevicesModels\Channels\Properties\PropertiesRepository $channelsPropertiesRepository,
		private readonly DevicesModels\Channels\Properties\PropertiesManager $channelsPropertiesManager,
		private readonly Persistence\ManagerRegistry $managerRegistry,
		private readonly DateTimeFactory\Factory $dateTimeFactory,
		private readonly Localization\Translator $translator,
		string|null $name = null,
	)
	{
		parent::__construct($name);
	}

	/**
	 * @throws Console\Exception\InvalidArgumentException
	 */
	protected function configure(): void
	{
		$this
			->setName(self::NAME)
			->setDescription('Viera connector televisions management');
	}

	/**
	 * @throws Console\Exception\InvalidArgumentException
	 * @throws DBAL\Exception
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\InvalidState
	 * @throws Exceptions\Runtime
	 * @throws InvalidArgumentExceptionAlias
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws RuntimeException
	 */
	protected function execute(Input\InputInterface $input, Output\OutputInterface $output): int
	{
		$io = new Style\SymfonyStyle($input, $output);

		$io->title($this->translator->translate('//viera-connector.cmd.devices.title'));

		$io->note($this->translator->translate('//viera-connector.cmd.devices.subtitle'));

		if ($input->getOption('no-interaction') === false) {
			$question = new Console\Question\ConfirmationQuestion(
				$this->translator->translate('//viera-connector.cmd.base.questions.continue'),
				false,
			);

			$continue = (bool) $io->askQuestion($question);

			if (!$continue) {
				return Console\Command\Command::SUCCESS;
			}
		}

		$connector = $this->askWhichConnector($io);

		if ($connector === null) {
			$io->warning($this->translator->translate('//viera-connector.cmd.base.messages.noConnectors'));

			return Console\Command\Command::SUCCESS;
		}

		$this->askConnectorAction($io, $connector);

		return Console\Command\Command::SUCCESS;
	}

	/**
	 * @throws DBAL\Exception
	 * @throws DevicesExceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws RuntimeException
	 */
	private function createDevice(Style\SymfonyStyle $io, Entities\VieraConnector $connector): void
	{
		$tempIdentifier = 'new-device-' . $this->dateTimeFactory->getNow()->format(DateTimeInterface::ATOM);

		$ipAddress = $this->askIpAddress($io);

		try {
			$televisionApi = $this->televisionApiFactory->create(
				$tempIdentifier,
				$ipAddress,
				Entities\VieraDevice::DEFAULT_PORT,
			);
			$televisionApi->connect();
		} catch (Exceptions\TelevisionApiCall | Exceptions\TelevisionApiError | Exceptions\InvalidState $ex) {
			$io->error(
				$this->translator->translate('//viera-connector.cmd.devices.messages.device.connectionFailed'),
			);

			$this->logger->error(
				'Creating api client failed',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'devices-cmd',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
				],
			);

			return;
		}

		try {
			$isOnline = $televisionApi->livenessProbe(1.5, true);
		} catch (Exceptions\InvalidState | InvalidArgumentExceptionAlias $ex) {
			$io->error(
				$this->translator->translate('//viera-connector.cmd.devices.messages.device.connectionFailed'),
			);

			$this->logger->error(
				'Checking TV status failed',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'devices-cmd',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
				],
			);

			return;
		}

		if ($isOnline === false) {
			$io->error(
				$this->translator->translate(
					'//viera-connector.cmd.devices.messages.device.unreachable',
					['address' => $ipAddress],
				),
			);

			return;
		}

		try {
			$specs = $televisionApi->getSpecs(false);
		} catch (Exceptions\TelevisionApiError $ex) {
			$io->error(
				$this->translator->translate('//viera-connector.cmd.devices.messages.device.loadingSpecsFailed'),
			);

			$this->logger->error(
				'Loading TV specification failed',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'devices-cmd',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
				],
			);

			return;
		} catch (Exceptions\TelevisionApiCall $ex) {
			$io->error(
				$this->translator->translate('//viera-connector.cmd.devices.messages.device.loadingSpecsFailed'),
			);

			$this->logger->error(
				'Loading TV specification failed',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'devices-cmd',
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
		}

		$authorization = null;

		$isTurnedOn = $televisionApi->isTurnedOn(true);

		if ($specs->isRequiresEncryption()) {
			$io->warning(
				$this->translator->translate('//viera-connector.cmd.devices.messages.device.needPairing'),
			);

			if ($isTurnedOn === false) {
				$io->warning(
					$this->translator->translate('//viera-connector.cmd.devices.messages.device.offline'),
				);

				$question = new Console\Question\ConfirmationQuestion(
					$this->translator->translate('//viera-connector.cmd.base.questions.continue'),
					false,
				);

				$continue = (bool) $io->askQuestion($question);

				if (!$continue) {
					return;
				}
			}

			$this->challengeKey = $televisionApi
				->requestPinCode($connector->getName() ?? $connector->getIdentifier(), false)
				->getChallengeKey();

			$authorization = $this->askPinCode($io, $connector, $televisionApi);

			try {
				$televisionApi = $this->televisionApiFactory->create(
					$tempIdentifier,
					$ipAddress,
					Entities\VieraDevice::DEFAULT_PORT,
					$authorization->getAppId(),
					$authorization->getEncryptionKey(),
				);
				$televisionApi->connect();
			} catch (Exceptions\TelevisionApiCall | Exceptions\TelevisionApiError | Exceptions\InvalidState $ex) {
				$io->error(
					$this->translator->translate('//viera-connector.cmd.devices.messages.device.connectionFailed'),
				);

				$this->logger->error(
					'Re-creating api client failed',
					[
						'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
						'type' => 'devices-cmd',
						'exception' => BootstrapHelpers\Logger::buildException($ex),
					],
				);

				return;
			}
		}

		$apps = $isTurnedOn ? $televisionApi->getApps(false) : null;

		$hdmi = [];

		$question = new Console\Question\ConfirmationQuestion(
			$this->translator->translate('//viera-connector.cmd.devices.questions.configure.hdmi'),
			false,
		);

		$configureHdmi = (bool) $io->askQuestion($question);

		if ($configureHdmi) {
			$io->note(
				$this->translator->translate('//viera-connector.cmd.devices.messages.info.hdmi'),
			);

			while (true) {
				$hdmiName = $this->askHdmiName($io);

				$hdmiIndex = $this->askHdmiIndex($io, $hdmiName);

				$hdmi[] = [
					Helpers\Name::sanitizeEnumName($hdmiName),
					$hdmiIndex,
					$hdmiIndex,
				];

				$question = new Console\Question\ConfirmationQuestion(
					$this->translator->translate('//viera-connector.cmd.devices.questions.configure.nextHdmi'),
					false,
				);

				$configureMode = (bool) $io->askQuestion($question);

				if (!$configureMode) {
					break;
				}
			}
		}

		$question = new Console\Question\ConfirmationQuestion(
			$this->translator->translate('//viera-connector.cmd.devices.questions.configure.macAddress'),
			false,
		);

		$configureMacAddress = (bool) $io->askQuestion($question);

		$macAddress = null;

		if ($configureMacAddress) {
			$io->note(
				$this->translator->translate('//viera-connector.cmd.devices.messages.info.macAddress'),
			);

			$macAddress = $this->askMacAddress($io);
		}

		try {
			// Start transaction connection to the database
			$this->getOrmConnection()->beginTransaction();

			$device = $this->devicesManager->create(Utils\ArrayHash::from([
				'entity' => Entities\VieraDevice::class,
				'connector' => $connector,
				'identifier' => $specs->getSerialNumber(),
				'name' => $specs->getFriendlyName() ?? $specs->getModelName(),
			]));
			assert($device instanceof Entities\VieraDevice);

			$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Devices\Properties\Variable::class,
				'identifier' => Types\DevicePropertyIdentifier::IP_ADDRESS,
				'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::IP_ADDRESS),
				'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
				'value' => $ipAddress,
				'device' => $device,
			]));

			$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Devices\Properties\Variable::class,
				'identifier' => Types\DevicePropertyIdentifier::PORT,
				'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::PORT),
				'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_UINT),
				'value' => Entities\VieraDevice::DEFAULT_PORT,
				'device' => $device,
			]));

			$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Devices\Properties\Variable::class,
				'identifier' => Types\DevicePropertyIdentifier::MODEL,
				'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::MODEL),
				'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
				'value' => trim(sprintf('%s %s', $specs->getModelName(), $specs->getModelNumber())),
				'device' => $device,
			]));

			$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Devices\Properties\Variable::class,
				'identifier' => Types\DevicePropertyIdentifier::MANUFACTURER,
				'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::MANUFACTURER),
				'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
				'value' => $specs->getManufacturer(),
				'device' => $device,
			]));

			$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Devices\Properties\Variable::class,
				'identifier' => Types\DevicePropertyIdentifier::SERIAL_NUMBER,
				'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::SERIAL_NUMBER),
				'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
				'value' => $specs->getSerialNumber(),
				'device' => $device,
			]));

			if ($macAddress !== null) {
				$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::MAC_ADDRESS,
					'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::MAC_ADDRESS),
					'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
					'value' => $macAddress,
					'device' => $device,
				]));
			}

			$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Devices\Properties\Variable::class,
				'identifier' => Types\DevicePropertyIdentifier::ENCRYPTED,
				'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::ENCRYPTED),
				'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_BOOLEAN),
				'value' => $specs->isRequiresEncryption(),
				'device' => $device,
			]));

			if ($authorization !== null) {
				$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::APP_ID,
					'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::APP_ID),
					'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
					'value' => $authorization->getAppId(),
					'device' => $device,
				]));

				$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::ENCRYPTION_KEY,
					'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::ENCRYPTION_KEY),
					'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
					'value' => $authorization->getEncryptionKey(),
					'device' => $device,
				]));
			}

			$channel = $this->channelsManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Channels\Channel::class,
				'device' => $device,
				'identifier' => Types\ChannelType::TELEVISION,
			]));

			$this->channelsPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Channels\Properties\Dynamic::class,
				'identifier' => Types\ChannelPropertyIdentifier::STATE,
				'name' => Helpers\Name::createName(Types\ChannelPropertyIdentifier::STATE),
				'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_BOOLEAN),
				'settable' => true,
				'queryable' => true,
				'format' => null,
				'channel' => $channel,
			]));

			$this->channelsPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Channels\Properties\Dynamic::class,
				'identifier' => Types\ChannelPropertyIdentifier::VOLUME,
				'name' => Helpers\Name::createName(Types\ChannelPropertyIdentifier::VOLUME),
				'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_UCHAR),
				'settable' => true,
				'queryable' => true,
				'format' => [
					0,
					100,
				],
				'channel' => $channel,
			]));

			$this->channelsPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Channels\Properties\Dynamic::class,
				'identifier' => Types\ChannelPropertyIdentifier::MUTE,
				'name' => Helpers\Name::createName(Types\ChannelPropertyIdentifier::MUTE),
				'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_BOOLEAN),
				'settable' => true,
				'queryable' => true,
				'format' => null,
				'channel' => $channel,
			]));

			$this->channelsPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Channels\Properties\Dynamic::class,
				'identifier' => Types\ChannelPropertyIdentifier::HDMI,
				'name' => Helpers\Name::createName(Types\ChannelPropertyIdentifier::HDMI),
				'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_ENUM),
				'settable' => true,
				'queryable' => false,
				'format' => $hdmi !== [] ? $hdmi : null,
				'channel' => $channel,
			]));

			$this->channelsPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Channels\Properties\Dynamic::class,
				'identifier' => Types\ChannelPropertyIdentifier::APPLICATION,
				'name' => Helpers\Name::createName(Types\ChannelPropertyIdentifier::APPLICATION),
				'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_ENUM),
				'settable' => true,
				'queryable' => false,
				'format' => $apps !== null ? array_map(
					static fn (Entities\API\Application $item): array => [
						Helpers\Name::sanitizeEnumName($item->getName()),
						$item->getId(),
						$item->getId(),
					],
					$apps->getApps(),
				) : null,
				'channel' => $channel,
			]));

			$this->channelsPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Channels\Properties\Dynamic::class,
				'identifier' => Types\ChannelPropertyIdentifier::INPUT_SOURCE,
				'name' => Helpers\Name::createName(Types\ChannelPropertyIdentifier::INPUT_SOURCE),
				'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_ENUM),
				'settable' => true,
				'queryable' => false,
				'format' => array_merge(
					[
						[
							'TV',
							500,
							500,
						],
					],
					$hdmi !== [] ? $hdmi : [],
					$apps !== null ? array_map(
						static fn (Entities\API\Application $item): array => [
							Helpers\Name::sanitizeEnumName($item->getName()),
							$item->getId(),
							$item->getId(),
						],
						$apps->getApps(),
					) : [],
				),
				'channel' => $channel,
			]));

			// Commit all changes into database
			$this->getOrmConnection()->commit();

			$io->success(
				$this->translator->translate(
					'//viera-connector.cmd.devices.messages.create.success',
					['name' => $device->getName() ?? $device->getIdentifier()],
				),
			);
		} catch (Throwable $ex) {
			// Log caught exception
			$this->logger->error(
				'An unhandled error occurred',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'devices-cmd',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
				],
			);

			$io->error($this->translator->translate('//viera-connector.cmd.devices.messages.create.error'));
		} finally {
			// Revert all changes when error occur
			if ($this->getOrmConnection()->isTransactionActive()) {
				$this->getOrmConnection()->rollBack();
			}
		}
	}

	/**
	 * @throws DBAL\Exception
	 * @throws DevicesExceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws RuntimeException
	 */
	private function editDevice(Style\SymfonyStyle $io, Entities\VieraConnector $connector): void
	{
		$device = $this->askWhichDevice($io, $connector);

		if ($device === null) {
			$io->warning($this->translator->translate('//viera-connector.cmd.devices.messages.noDevices'));

			$question = new Console\Question\ConfirmationQuestion(
				$this->translator->translate('//viera-connector.cmd.devices.questions.create.device'),
				false,
			);

			$continue = (bool) $io->askQuestion($question);

			if ($continue) {
				$this->createDevice($io, $connector);
			}

			return;
		}

		$findChannel = new DevicesQueries\FindChannels();
		$findChannel->forDevice($device);
		$findChannel->byIdentifier(Types\ChannelType::TELEVISION);

		$channel = $this->channelsRepository->findOneBy($findChannel);

		$authorization = null;

		$name = $this->askDeviceName($io, $device);

		$findDevicePropertyQuery = new DevicesQueries\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::IP_ADDRESS);

		$ipAddressProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

		if ($ipAddressProperty === null) {
			$changeIpAddress = true;

		} else {
			$question = new Console\Question\ConfirmationQuestion(
				$this->translator->translate('//viera-connector.cmd.devices.questions.change.ipAddress'),
				false,
			);

			$changeIpAddress = (bool) $io->askQuestion($question);
		}

		$ipAddress = $device->getIpAddress();

		if ($changeIpAddress || $ipAddress === null) {
			$ipAddress = $this->askIpAddress($io, $device);
		}

		$findDevicePropertyQuery = new DevicesQueries\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::PORT);

		$portProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

		if ($portProperty === null) {
			$changePort = true;

		} else {
			$question = new Console\Question\ConfirmationQuestion(
				$this->translator->translate('//viera-connector.cmd.devices.questions.change.port'),
				false,
			);

			$changePort = (bool) $io->askQuestion($question);
		}

		$port = $device->getPort();

		if ($changePort) {
			$port = $this->askPort($io, $device);
		}

		$hdmiProperty = null;

		if ($channel !== null) {
			$findChannelPropertyQuery = new DevicesQueries\FindChannelProperties();
			$findChannelPropertyQuery->forChannel($channel);
			$findChannelPropertyQuery->byIdentifier(Types\ChannelPropertyIdentifier::HDMI);

			$hdmiProperty = $this->channelsPropertiesRepository->findOneBy($findChannelPropertyQuery);
		}

		if ($hdmiProperty === null) {
			$question = new Console\Question\ConfirmationQuestion(
				$this->translator->translate('//viera-connector.cmd.devices.questions.configure.hdmi'),
				false,
			);

			$configureHdmi = (bool) $io->askQuestion($question);

		} else {
			$question = new Console\Question\ConfirmationQuestion(
				$this->translator->translate('//viera-connector.cmd.devices.questions.change.hdmi'),
				false,
			);

			$configureHdmi = (bool) $io->askQuestion($question);
		}

		$hdmi = null;

		if ($configureHdmi) {
			$hdmi = [];

			$io->note(
				$this->translator->translate('//viera-connector.cmd.devices.messages.info.hdmi'),
			);

			while (true) {
				$hdmiName = $this->askHdmiName($io);

				$hdmiIndex = $this->askHdmiIndex($io, $hdmiName);

				$hdmi[$hdmiIndex] = $hdmiName;

				$question = new Console\Question\ConfirmationQuestion(
					$this->translator->translate('//viera-connector.cmd.devices.questions.configure.nextHdmi'),
					false,
				);

				$configureMode = (bool) $io->askQuestion($question);

				if (!$configureMode) {
					break;
				}
			}
		}

		$appsProperty = null;

		if ($channel !== null) {
			$findChannelPropertyQuery = new DevicesQueries\FindChannelProperties();
			$findChannelPropertyQuery->forChannel($channel);
			$findChannelPropertyQuery->byIdentifier(Types\ChannelPropertyIdentifier::APPLICATION);

			$appsProperty = $this->channelsPropertiesRepository->findOneBy($findChannelPropertyQuery);
		}

		$findDevicePropertyQuery = new DevicesQueries\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::APP_ID);

		$appIdProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

		$findDevicePropertyQuery = new DevicesQueries\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::ENCRYPTION_KEY);

		$encryptionKeyProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

		$findDevicePropertyQuery = new DevicesQueries\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::MODEL);

		$hardwareModelProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

		$findDevicePropertyQuery = new DevicesQueries\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::MANUFACTURER);

		$hardwareManufacturerProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

		$findDevicePropertyQuery = new DevicesQueries\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::MAC_ADDRESS);

		$macAddressProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

		if ($macAddressProperty === null) {
			$question = new Console\Question\ConfirmationQuestion(
				$this->translator->translate('//viera-connector.cmd.devices.questions.configure.macAddress'),
				false,
			);

			$changeMacAddress = (bool) $io->askQuestion($question);

		} else {
			$question = new Console\Question\ConfirmationQuestion(
				$this->translator->translate('//viera-connector.cmd.devices.questions.change.macAddress'),
				false,
			);

			$changeMacAddress = (bool) $io->askQuestion($question);
		}

		$macAddress = $device->getMacAddress();

		if ($changeMacAddress) {
			$macAddress = $this->askMacAddress($io, $device);
		}

		try {
			$televisionApi = $this->televisionApiFactory->create(
				$device->getIdentifier(),
				$ipAddress,
				$port,
				$device->getAppId(),
				$device->getEncryptionKey(),
			);
			$televisionApi->connect();
		} catch (Exceptions\TelevisionApiCall | Exceptions\TelevisionApiError | Exceptions\InvalidState $ex) {
			$io->error(
				$this->translator->translate('//viera-connector.cmd.devices.messages.device.connectionFailed'),
			);

			$this->logger->error(
				'Creating api client failed',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'devices-cmd',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
				],
			);

			return;
		}

		try {
			$isOnline = $televisionApi->livenessProbe(1.5, true);
		} catch (Exceptions\InvalidState | InvalidArgumentExceptionAlias $ex) {
			$io->error(
				$this->translator->translate('//viera-connector.cmd.devices.messages.device.connectionFailed'),
			);

			$this->logger->error(
				'Checking TV status failed',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'devices-cmd',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
				],
			);

			return;
		}

		if ($isOnline === false) {
			$io->error(
				$this->translator->translate(
					'//viera-connector.cmd.devices.messages.device.unreachable',
					['address' => $ipAddress],
				),
			);

			return;
		}

		try {
			$specs = $televisionApi->getSpecs(false);
		} catch (Exceptions\TelevisionApiError $ex) {
			$io->error(
				$this->translator->translate('//viera-connector.cmd.devices.messages.device.loadingSpecsFailed'),
			);

			$this->logger->error(
				'Loading TV specification failed',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'devices-cmd',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
				],
			);

			return;
		} catch (Exceptions\TelevisionApiCall $ex) {
			$io->error(
				$this->translator->translate('//viera-connector.cmd.devices.messages.device.loadingSpecsFailed'),
			);

			$this->logger->error(
				'Loading TV specification failed',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'devices-cmd',
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
		}

		$isTurnedOn = $televisionApi->isTurnedOn(true);

		if (!$device->isEncrypted() && $specs->isRequiresEncryption()) {
			$io->warning(
				$this->translator->translate('//viera-connector.cmd.devices.messages.device.needPairing'),
			);

			if ($isTurnedOn === false) {
				$io->warning(
					$this->translator->translate('//viera-connector.cmd.devices.messages.device.offline'),
				);

				$question = new Console\Question\ConfirmationQuestion(
					$this->translator->translate('//viera-connector.cmd.base.questions.continue'),
					false,
				);

				$continue = (bool) $io->askQuestion($question);

				if (!$continue) {
					return;
				}
			}

			$this->challengeKey = $televisionApi
				->requestPinCode($connector->getName() ?? $connector->getIdentifier(), false)
				->getChallengeKey();

			$authorization = $this->askPinCode($io, $connector, $televisionApi);

			try {
				$televisionApi = $this->televisionApiFactory->create(
					$device->getIdentifier(),
					$ipAddress,
					$port,
					$authorization->getAppId(),
					$authorization->getEncryptionKey(),
				);
				$televisionApi->connect();
			} catch (Exceptions\TelevisionApiCall | Exceptions\TelevisionApiError | Exceptions\InvalidState $ex) {
				$io->error(
					$this->translator->translate('//viera-connector.cmd.devices.messages.device.connectionFailed'),
				);

				$this->logger->error(
					'Re-creating api client failed',
					[
						'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
						'type' => 'devices-cmd',
						'exception' => BootstrapHelpers\Logger::buildException($ex),
					],
				);

				return;
			}
		}

		$apps = $isTurnedOn ? $televisionApi->getApps(false) : null;

		try {
			// Start transaction connection to the database
			$this->getOrmConnection()->beginTransaction();

			$device = $this->devicesManager->update($device, Utils\ArrayHash::from([
				'name' => $name,
			]));
			assert($device instanceof Entities\VieraDevice);

			if ($ipAddressProperty === null) {
				$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::IP_ADDRESS,
					'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::IP_ADDRESS),
					'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
					'value' => $ipAddress,
					'device' => $device,
				]));
			} elseif ($ipAddress !== null) {
				$this->devicesPropertiesManager->update($ipAddressProperty, Utils\ArrayHash::from([
					'value' => $ipAddress,
				]));
			}

			if ($portProperty === null) {
				$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::PORT,
					'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::PORT),
					'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_UINT),
					'value' => $port,
					'device' => $device,
				]));
			} else {
				$this->devicesPropertiesManager->update($portProperty, Utils\ArrayHash::from([
					'value' => $port,
				]));
			}

			if ($appIdProperty === null && $authorization !== null) {
				$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::APP_ID,
					'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::APP_ID),
					'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
					'value' => $authorization->getAppId(),
					'device' => $device,
				]));
			} elseif ($appIdProperty !== null && $authorization !== null) {
				$this->devicesPropertiesManager->update($appIdProperty, Utils\ArrayHash::from([
					'value' => $authorization->getAppId(),
				]));
			} elseif ($appIdProperty !== null && $authorization === null) {
				$this->devicesPropertiesManager->delete($appIdProperty);
			}

			if ($encryptionKeyProperty === null && $authorization !== null) {
				$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::ENCRYPTION_KEY,
					'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::ENCRYPTION_KEY),
					'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
					'value' => $authorization->getEncryptionKey(),
					'device' => $device,
				]));
			} elseif ($encryptionKeyProperty !== null && $authorization !== null) {
				$this->devicesPropertiesManager->update($encryptionKeyProperty, Utils\ArrayHash::from([
					'value' => $authorization->getEncryptionKey(),
				]));
			} elseif ($encryptionKeyProperty !== null && $authorization === null) {
				$this->devicesPropertiesManager->delete($encryptionKeyProperty);
			}

			if ($hardwareModelProperty === null) {
				$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::MODEL,
					'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::MODEL),
					'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
					'value' => trim(sprintf('%s %s', $specs->getModelName(), $specs->getModelNumber())),
					'device' => $device,
				]));
			} else {
				$this->devicesPropertiesManager->update($hardwareModelProperty, Utils\ArrayHash::from([
					'value' => trim(sprintf('%s %s', $specs->getModelName(), $specs->getModelNumber())),
				]));
			}

			if ($hardwareManufacturerProperty === null) {
				$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::MODEL,
					'name' => Helpers\Name::createName(
						Types\DevicePropertyIdentifier::MANUFACTURER,
					),
					'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
					'value' => $specs->getManufacturer(),
					'device' => $device,
				]));
			} else {
				$this->devicesPropertiesManager->update($hardwareManufacturerProperty, Utils\ArrayHash::from([
					'value' => $specs->getManufacturer(),
				]));
			}

			if ($macAddressProperty === null) {
				$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::MAC_ADDRESS,
					'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::MAC_ADDRESS),
					'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
					'value' => $macAddress,
					'device' => $device,
				]));
			} elseif ($macAddress !== null) {
				$this->devicesPropertiesManager->update($macAddressProperty, Utils\ArrayHash::from([
					'value' => $macAddress,
				]));
			}

			if ($channel === null) {
				$channel = $this->channelsManager->create(Utils\ArrayHash::from([
					'device' => $device,
					'identifier' => Types\ChannelType::TELEVISION,
				]));
			}

			if ($hdmi !== null) {
				if ($hdmiProperty === null) {
					$this->channelsPropertiesManager->create(Utils\ArrayHash::from([
						'entity' => DevicesEntities\Channels\Properties\Dynamic::class,
						'identifier' => Types\ChannelPropertyIdentifier::HDMI,
						'name' => Helpers\Name::createName(Types\ChannelPropertyIdentifier::HDMI),
						'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_ENUM),
						'settable' => true,
						'queryable' => false,
						'format' => array_map(static fn (string $name, int $index): array => [
							Helpers\Name::sanitizeEnumName($name),
							$index,
							$index,
						], array_values($hdmi), array_keys($hdmi)),
						'channel' => $channel,
					]));
				} elseif ($macAddress !== null) {
					$this->channelsPropertiesManager->update($hdmiProperty, Utils\ArrayHash::from([
						'format' => array_map(static fn (string $name, int $index): array => [
							Helpers\Name::sanitizeEnumName($name),
							$index,
							$index,
						], array_values($hdmi), array_keys($hdmi)),
					]));
				}
			}

			if ($apps !== null) {
				if ($appsProperty === null) {
					$this->channelsPropertiesManager->create(Utils\ArrayHash::from([
						'entity' => DevicesEntities\Channels\Properties\Dynamic::class,
						'identifier' => Types\ChannelPropertyIdentifier::APPLICATION,
						'name' => Helpers\Name::createName(Types\ChannelPropertyIdentifier::APPLICATION),
						'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_ENUM),
						'settable' => true,
						'queryable' => false,
						'format' => $apps->getApps() !== [] ? array_map(
							static fn (Entities\API\Application $application): array => [
								Helpers\Name::sanitizeEnumName($application->getName()),
								$application->getId(),
								$application->getId(),
							],
							$apps->getApps(),
						) : null,
						'channel' => $channel,
					]));
				} elseif ($macAddress !== null) {
					$this->channelsPropertiesManager->update($appsProperty, Utils\ArrayHash::from([
						'format' => $apps->getApps() !== [] ? array_map(
							static fn (Entities\API\Application $application): array => [
								Helpers\Name::sanitizeEnumName($application->getName()),
								$application->getId(),
								$application->getId(),
							],
							$apps->getApps(),
						) : null,
					]));
				}
			}

			// Commit all changes into database
			$this->getOrmConnection()->commit();

			$io->success(
				$this->translator->translate(
					'//viera-connector.cmd.devices.messages.update.success',
					['name' => $device->getName() ?? $device->getIdentifier()],
				),
			);
		} catch (Throwable $ex) {
			// Log caught exception
			$this->logger->error(
				'An unhandled error occurred',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'devices-cmd',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
				],
			);

			$io->error($this->translator->translate('//viera-connector.cmd.devices.messages.update.error'));
		} finally {
			// Revert all changes when error occur
			if ($this->getOrmConnection()->isTransactionActive()) {
				$this->getOrmConnection()->rollBack();
			}
		}
	}

	/**
	 * @throws DBAL\Exception
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\Runtime
	 */
	private function deleteDevice(Style\SymfonyStyle $io, Entities\VieraConnector $connector): void
	{
		$device = $this->askWhichDevice($io, $connector);

		if ($device === null) {
			$io->warning($this->translator->translate('//viera-connector.cmd.devices.messages.noDevices'));

			return;
		}

		$question = new Console\Question\ConfirmationQuestion(
			$this->translator->translate('//viera-connector.cmd.base.questions.continue'),
			false,
		);

		$continue = (bool) $io->askQuestion($question);

		if (!$continue) {
			return;
		}

		try {
			// Start transaction connection to the database
			$this->getOrmConnection()->beginTransaction();

			$this->devicesManager->delete($device);

			// Commit all changes into database
			$this->getOrmConnection()->commit();

			$io->success(
				$this->translator->translate(
					'//viera-connector.cmd.devices.messages.remove.success',
					['name' => $device->getName() ?? $device->getIdentifier()],
				),
			);
		} catch (Throwable $ex) {
			// Log caught exception
			$this->logger->error(
				'An unhandled error occurred',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'devices-cmd',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
				],
			);

			$io->error($this->translator->translate('//viera-connector.cmd.devices.messages.remove.error'));
		} finally {
			// Revert all changes when error occur
			if ($this->getOrmConnection()->isTransactionActive()) {
				$this->getOrmConnection()->rollBack();
			}
		}
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	private function listDevices(Style\SymfonyStyle $io, Entities\VieraConnector $connector): void
	{
		$findDevicesQuery = new Queries\FindDevices();
		$findDevicesQuery->forConnector($connector);

		$devices = $this->devicesRepository->findAllBy($findDevicesQuery, Entities\VieraDevice::class);
		usort(
			$devices,
			static function (Entities\VieraDevice $a, Entities\VieraDevice $b): int {
				if ($a->getIdentifier() === $b->getIdentifier()) {
					return $a->getName() <=> $b->getName();
				}

				return $a->getIdentifier() <=> $b->getIdentifier();
			},
		);

		$table = new Console\Helper\Table($io);
		$table->setHeaders([
			'#',
			$this->translator->translate('//viera-connector.cmd.devices.data.name'),
			$this->translator->translate('//viera-connector.cmd.devices.data.model'),
			$this->translator->translate('//viera-connector.cmd.devices.data.ipAddress'),
			$this->translator->translate('//viera-connector.cmd.devices.data.encryption'),
		]);

		foreach ($devices as $index => $device) {
			$table->addRow([
				$index + 1,
				$device->getName() ?? $device->getIdentifier(),
				$device->getModel() ?? 'N/A',
				$device->getIpAddress() ?? 'N/A',
				$device->isEncrypted() ? 'yes' : 'no',
			]);
		}

		$table->render();

		$io->newLine();
	}

	private function askDeviceName(Style\SymfonyStyle $io, Entities\VieraDevice|null $device = null): string|null
	{
		$question = new Console\Question\Question(
			$this->translator->translate('//viera-connector.cmd.devices.questions.provide.name'),
			$device?->getName(),
		);

		$name = $io->askQuestion($question);

		return strval($name) === '' ? null : strval($name);
	}

	/**
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	private function askIpAddress(Style\SymfonyStyle $io, Entities\VieraDevice|null $device = null): string
	{
		$question = new Console\Question\Question(
			$this->translator->translate('//viera-connector.cmd.devices.questions.provide.ipAddress'),
			$device?->getIpAddress(),
		);
		$question->setValidator(function (string|null $answer): string {
			if ($answer !== null && preg_match(self::MATCH_IP_ADDRESS, $answer) === 1) {
				return $answer;
			}

			throw new Exceptions\Runtime(
				sprintf($this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'), $answer),
			);
		});

		$ipAddress = $io->askQuestion($question);

		return strval($ipAddress);
	}

	/**
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	private function askPort(Style\SymfonyStyle $io, Entities\VieraDevice|null $device = null): int
	{
		$question = new Console\Question\Question(
			$this->translator->translate('//viera-connector.cmd.devices.questions.provide.port'),
			$device?->getPort(),
		);
		$question->setValidator(function (string|null $answer): int {
			if ($answer !== null && strval(intval($answer)) === $answer) {
				return intval($answer);
			}

			throw new Exceptions\Runtime(
				sprintf($this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'), $answer),
			);
		});

		$port = $io->askQuestion($question);

		return intval($port);
	}

	/**
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	private function askMacAddress(Style\SymfonyStyle $io, Entities\VieraDevice|null $device = null): string
	{
		$question = new Console\Question\Question(
			$this->translator->translate('//viera-connector.cmd.devices.questions.provide.macAddress'),
			$device?->getMacAddress(),
		);
		$question->setValidator(function (string|null $answer): string {
			if ($answer !== null && preg_match(self::MATCH_MAC_ADDRESS, $answer) === 1) {
				return $answer;
			}

			throw new Exceptions\Runtime(
				sprintf($this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'), $answer),
			);
		});

		$macAddress = $io->askQuestion($question);

		return strval($macAddress);
	}

	private function askPinCode(
		Style\SymfonyStyle $io,
		Entities\VieraConnector $connector,
		API\TelevisionApi $televisionApi,
	): Entities\API\AuthorizePinCode
	{
		$question = new Console\Question\Question(
			$this->translator->translate('//viera-connector.cmd.discovery.questions.provide.pinCode'),
		);
		$question->setValidator(
			function (string|null $answer) use ($connector, $televisionApi): Entities\API\AuthorizePinCode {
				if ($answer !== null && $answer !== '') {
					try {
						return $televisionApi->authorizePinCode($answer, strval($this->challengeKey), false);
					} catch (Exceptions\TelevisionApiCall) {
						$this->challengeKey = $televisionApi
							->requestPinCode($connector->getName() ?? $connector->getIdentifier(), false)
							->getChallengeKey();

						throw new Exceptions\Runtime(
							sprintf(
								$this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
								$answer,
							),
						);
					}
				}

				throw new Exceptions\Runtime(
					sprintf(
						$this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
						$answer,
					),
				);
			},
		);

		$authorization = $io->askQuestion($question);
		assert($authorization instanceof Entities\API\AuthorizePinCode);

		return $authorization;
	}

	private function askHdmiName(Style\SymfonyStyle $io): string
	{
		$question = new Console\Question\Question(
			$this->translator->translate('//viera-connector.cmd.discovery.questions.provide.hdmiName'),
		);
		$question->setValidator(function (string|null $answer): string {
			if ($answer !== null) {
				return $answer;
			}

			throw new Exceptions\Runtime(
				sprintf($this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'), $answer),
			);
		});

		$ipAddress = $io->askQuestion($question);

		return strval($ipAddress);
	}

	private function askHdmiIndex(Style\SymfonyStyle $io, string $name): int
	{
		$question = new Console\Question\Question(
			$this->translator->translate(
				'//viera-connector.cmd.discovery.questions.provide.hdmiNumber',
				['name' => $name],
			),
		);
		$question->setValidator(function (string|null $answer): int {
			if (
				$answer !== null
				&& strval(intval($answer)) === $answer
				&& intval($answer) > 0
				&& intval($answer) < 10
			) {
				return intval($answer);
			}

			throw new Exceptions\Runtime(
				sprintf($this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'), $answer),
			);
		});

		$ipAddress = $io->askQuestion($question);

		return intval($ipAddress);
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 */
	private function askWhichConnector(Style\SymfonyStyle $io): Entities\VieraConnector|null
	{
		$connectors = [];

		$findConnectorsQuery = new Queries\FindConnectors();

		$systemConnectors = $this->connectorsRepository->findAllBy(
			$findConnectorsQuery,
			Entities\VieraConnector::class,
		);
		usort(
			$systemConnectors,
			// phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
			static fn (Entities\VieraConnector $a, Entities\VieraConnector $b): int => $a->getIdentifier() <=> $b->getIdentifier()
		);

		foreach ($systemConnectors as $connector) {
			$connectors[$connector->getIdentifier()] = $connector->getIdentifier()
				. ($connector->getName() !== null ? ' [' . $connector->getName() . ']' : '');
		}

		if (count($connectors) === 0) {
			return null;
		}

		$question = new Console\Question\ChoiceQuestion(
			$this->translator->translate('//viera-connector.cmd.devices.questions.select.connector'),
			array_values($connectors),
			count($connectors) === 1 ? 0 : null,
		);
		$question->setErrorMessage(
			$this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
		);
		$question->setValidator(function (string|int|null $answer) use ($connectors): Entities\VieraConnector {
			if ($answer === null) {
				throw new Exceptions\Runtime(
					sprintf(
						$this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
						$answer,
					),
				);
			}

			if (array_key_exists($answer, array_values($connectors))) {
				$answer = array_values($connectors)[$answer];
			}

			$identifier = array_search($answer, $connectors, true);

			if ($identifier !== false) {
				$findConnectorQuery = new Queries\FindConnectors();
				$findConnectorQuery->byIdentifier($identifier);

				$connector = $this->connectorsRepository->findOneBy(
					$findConnectorQuery,
					Entities\VieraConnector::class,
				);

				if ($connector !== null) {
					return $connector;
				}
			}

			throw new Exceptions\Runtime(
				sprintf(
					$this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
					$answer,
				),
			);
		});

		$connector = $io->askQuestion($question);
		assert($connector instanceof Entities\VieraConnector);

		return $connector;
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 */
	private function askWhichDevice(
		Style\SymfonyStyle $io,
		Entities\VieraConnector $connector,
	): Entities\VieraDevice|null
	{
		$devices = [];

		$findDevicesQuery = new Queries\FindDevices();
		$findDevicesQuery->forConnector($connector);

		$connectorDevices = $this->devicesRepository->findAllBy(
			$findDevicesQuery,
			Entities\VieraDevice::class,
		);
		usort(
			$connectorDevices,
			static fn (DevicesEntities\Devices\Device $a, DevicesEntities\Devices\Device $b): int => $a->getIdentifier() <=> $b->getIdentifier()
		);

		foreach ($connectorDevices as $device) {
			$devices[$device->getIdentifier()] = $device->getIdentifier()
				. ($device->getName() !== null ? ' [' . $device->getName() . ']' : '');
		}

		if (count($devices) === 0) {
			return null;
		}

		$question = new Console\Question\ChoiceQuestion(
			$this->translator->translate('//viera-connector.cmd.devices.questions.select.device'),
			array_values($devices),
			count($devices) === 1 ? 0 : null,
		);
		$question->setErrorMessage(
			$this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
		);
		$question->setValidator(
			function (string|int|null $answer) use ($connector, $devices): Entities\VieraDevice {
				if ($answer === null) {
					throw new Exceptions\Runtime(
						sprintf(
							$this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
							$answer,
						),
					);
				}

				if (array_key_exists($answer, array_values($devices))) {
					$answer = array_values($devices)[$answer];
				}

				$identifier = array_search($answer, $devices, true);

				if ($identifier !== false) {
					$findDeviceQuery = new Queries\FindDevices();
					$findDeviceQuery->byIdentifier($identifier);
					$findDeviceQuery->forConnector($connector);

					$device = $this->devicesRepository->findOneBy(
						$findDeviceQuery,
						Entities\VieraDevice::class,
					);

					if ($device !== null) {
						return $device;
					}
				}

				throw new Exceptions\Runtime(
					sprintf(
						$this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
						$answer,
					),
				);
			},
		);

		$device = $io->askQuestion($question);
		assert($device instanceof Entities\VieraDevice);

		return $device;
	}

	/**
	 * @throws DBAL\Exception
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws RuntimeException
	 */
	private function askConnectorAction(
		Style\SymfonyStyle $io,
		Entities\VieraConnector $connector,
	): void
	{
		$question = new Console\Question\ChoiceQuestion(
			$this->translator->translate('//viera-connector.cmd.base.questions.whatToDo'),
			[
				0 => $this->translator->translate('//viera-connector.cmd.devices.actions.create.device'),
				1 => $this->translator->translate('//viera-connector.cmd.devices.actions.update.device'),
				2 => $this->translator->translate('//viera-connector.cmd.devices.actions.remove.device'),
				3 => $this->translator->translate('//viera-connector.cmd.devices.actions.list.devices'),
				4 => $this->translator->translate('//viera-connector.cmd.devices.actions.nothing'),
			],
			4,
		);

		$question->setErrorMessage(
			$this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
		);

		$whatToDo = $io->askQuestion($question);

		if (
			$whatToDo === $this->translator->translate(
				'//viera-connector.cmd.devices.actions.create.device',
			)
			|| $whatToDo === '0'
		) {
			$this->createDevice($io, $connector);

			$this->askConnectorAction($io, $connector);

		} elseif (
			$whatToDo === $this->translator->translate(
				'//viera-connector.cmd.devices.actions.update.device',
			)
			|| $whatToDo === '1'
		) {
			$this->editDevice($io, $connector);

			$this->askConnectorAction($io, $connector);

		} elseif (
			$whatToDo === $this->translator->translate(
				'//viera-connector.cmd.devices.actions.remove.device',
			)
			|| $whatToDo === '2'
		) {
			$this->deleteDevice($io, $connector);

			$this->askConnectorAction($io, $connector);

		} elseif (
			$whatToDo === $this->translator->translate(
				'//viera-connector.cmd.devices.actions.list.devices',
			)
			|| $whatToDo === '3'
		) {
			$this->listDevices($io, $connector);

			$this->askConnectorAction($io, $connector);
		}
	}

	/**
	 * @throws Exceptions\Runtime
	 */
	private function getOrmConnection(): DBAL\Connection
	{
		$connection = $this->managerRegistry->getConnection();

		if ($connection instanceof DBAL\Connection) {
			return $connection;
		}

		throw new Exceptions\Runtime('Database connection could not be established');
	}

}
