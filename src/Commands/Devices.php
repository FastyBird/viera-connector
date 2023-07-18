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

use BadMethodCallException;
use DateTimeInterface;
use Doctrine\DBAL;
use Doctrine\Persistence;
use FastyBird\Connector\Viera;
use FastyBird\Connector\Viera\API;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Helpers;
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
use Nette\Utils;
use Psr\Log;
use React\EventLoop;
use Symfony\Component\Console;
use Symfony\Component\Console\Input;
use Symfony\Component\Console\Output;
use Symfony\Component\Console\Style;
use Throwable;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_search;
use function array_values;
use function assert;
use function count;
use function intval;
use function preg_match;
use function React\Async\async;
use function sprintf;
use function strval;
use function trim;
use function usort;
use const SIGINT;

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

	private const WAITING_INTERVAL = 5.0;

	private const MAX_PROCESSING_INTERVAL = 60.0;

	private const QUEUE_PROCESSING_INTERVAL = 0.01;
	// phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
	private const MATCH_IP_ADDRESS = '/^((?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])[.]){3}(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])$/';

	private const MATCH_MAC_ADDRESS = '/^([0-9a-fA-F][0-9a-fA-F]:){5}([0-9a-fA-F][0-9a-fA-F])$/';

	private const CHOICE_QUESTION_CREATE_DEVICE = 'Create new connector device';

	private const CHOICE_QUESTION_EDIT_DEVICE = 'Edit existing connector device';

	private const CHOICE_QUESTION_DELETE_DEVICE = 'Delete existing connector device';

	private string|null $challengeKey = null;

	private DateTimeInterface|null $executedTime = null;

	private EventLoop\TimerInterface|null $consumerTimer = null;

	public function __construct(
		private readonly API\TelevisionApiFactory $televisionApiFactory,
		private readonly DevicesModels\Connectors\ConnectorsRepository $connectorsRepository,
		private readonly DevicesModels\Devices\DevicesRepository $devicesRepository,
		private readonly DevicesModels\Devices\DevicesManager $devicesManager,
		private readonly DevicesModels\Devices\Properties\PropertiesRepository $devicePropertiesRepository,
		private readonly DevicesModels\Devices\Properties\PropertiesManager $devicePropertiesManager,
		private readonly DevicesModels\Channels\ChannelsRepository $channelsRepository,
		private readonly DevicesModels\Channels\ChannelsManager $channelsManager,
		private readonly DevicesModels\Channels\Properties\PropertiesRepository $channelPropertiesRepository,
		private readonly DevicesModels\Channels\Properties\PropertiesManager $channelPropertiesManager,
		private readonly Viera\Consumers\Messages $consumer,
		private readonly Persistence\ManagerRegistry $managerRegistry,
		private readonly DateTimeFactory\Factory $dateTimeFactory,
		private readonly EventLoop\LoopInterface $eventLoop,
		private readonly Log\LoggerInterface $logger = new Log\NullLogger(),
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
			->setDescription('Viera televisions management');
	}

	/**
	 * @throws BadMethodCallException
	 * @throws Console\Exception\InvalidArgumentException
	 * @throws DBAL\Exception
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\Runtime
	 * @throws InvalidArgumentExceptionAlias
	 * @throws MetadataExceptions\InvalidState
	 */
	protected function execute(Input\InputInterface $input, Output\OutputInterface $output): int
	{
		$io = new Style\SymfonyStyle($input, $output);

		$io->title('Viera connector - televisions management');

		$io->note('This action will create|update|delete connector device.');

		if ($input->getOption('no-interaction') === false) {
			$question = new Console\Question\ConfirmationQuestion(
				'Would you like to continue?',
				false,
			);

			$continue = (bool) $io->askQuestion($question);

			if (!$continue) {
				return Console\Command\Command::SUCCESS;
			}
		}

		$connector = $this->askWhichConnector($io);

		if ($connector === null) {
			$io->warning('No Viera connectors registered in system');

			return Console\Command\Command::SUCCESS;
		}

		$this->executedTime = $this->dateTimeFactory->getNow();

		$question = new Console\Question\ChoiceQuestion(
			'What would you like to do?',
			[
				0 => self::CHOICE_QUESTION_CREATE_DEVICE,
				1 => self::CHOICE_QUESTION_EDIT_DEVICE,
				2 => self::CHOICE_QUESTION_DELETE_DEVICE,
			],
		);

		$question->setErrorMessage('Selected answer: "%s" is not valid.');

		$whatToDo = $io->askQuestion($question);

		if ($whatToDo === self::CHOICE_QUESTION_CREATE_DEVICE) {
			$this->createNewDevice($io, $connector);

		} elseif ($whatToDo === self::CHOICE_QUESTION_EDIT_DEVICE) {
			$this->editExistingDevice($io, $connector);

		} elseif ($whatToDo === self::CHOICE_QUESTION_DELETE_DEVICE) {
			$this->deleteExistingDevice($io, $connector);
		}

		$this->consumerTimer = $this->eventLoop->addPeriodicTimer(
			self::QUEUE_PROCESSING_INTERVAL,
			async(function (): void {
				$this->consumer->consume();

				if ($this->consumer->isEmpty()) {
					$this->checkAndTerminate();
				}
			}),
		);

		$this->eventLoop->addSignal(SIGINT, function (): void {
			$this->checkAndTerminate();
		});

		$this->eventLoop->addTimer(
			self::MAX_PROCESSING_INTERVAL,
			async(function (): void {
				$this->checkAndTerminate();
			}),
		);

		$this->eventLoop->run();

		return Console\Command\Command::SUCCESS;
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	private function createNewDevice(Style\SymfonyStyle $io, Entities\VieraConnector $connector): void
	{
		$tempIdentifier = 'new-device-' . $this->dateTimeFactory->getNow()->format(DateTimeInterface::ATOM);

		try {
			$ipAddress = $this->askIpAddress($io);

			$televisionApi = $this->televisionApiFactory->create(
				$tempIdentifier,
				$ipAddress,
				Entities\VieraDevice::DEFAULT_PORT,
			);
			$televisionApi->connect();

			try {
				$isOnline = $televisionApi->livenessProbe(1.5, true);
			} catch (Throwable $ex) {
				$this->logger->error(
					'Checking TV status failed',
					[
						'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
						'type' => 'devices-cmd',
						'exception' => BootstrapHelpers\Logger::buildException($ex),
					],
				);

				$io->error('Something went wrong, television could not be created. Error was logged.');

				return;
			}

			if ($isOnline === false) {
				$io->error(sprintf('The provided IP: %s address is unreachable.', $ipAddress));

				return;
			}

			$specs = $televisionApi->getSpecs(false);

			$authorization = null;

			try {
				$isTurnedOn = $televisionApi->isTurnedOn(true);
			} catch (Throwable $ex) {
				$this->logger->error(
					'Checking screen status failed',
					[
						'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
						'type' => 'devices-cmd',
						'exception' => BootstrapHelpers\Logger::buildException($ex),
					],
				);

				$io->error('Something went wrong, television could not be created. Error was logged.');

				return;
			}

			if ($specs->isRequiresEncryption()) {
				if ($isTurnedOn === false) {
					$io->warning(
						'It looks like your TV is not turned on. It is possible that the pairing could not be finished.',
					);

					$question = new Console\Question\ConfirmationQuestion(
						'Would you like to continue?',
						false,
					);

					$continue = (bool) $io->askQuestion($question);

					if (!$continue) {
						return;
					}
				}

				$this->challengeKey = $televisionApi->requestPinCode(
					$connector->getName() ?? $connector->getIdentifier(),
					false,
				);

				$authorization = $this->askPinCode($io, $connector, $televisionApi);

				$televisionApi = $this->televisionApiFactory->create(
					$tempIdentifier,
					$ipAddress,
					Entities\VieraDevice::DEFAULT_PORT,
					$authorization->getAppId(),
					$authorization->getEncryptionKey(),
				);
				$televisionApi->connect();
			}

			$apps = $isTurnedOn ? $televisionApi->getApps(false) : null;

		} catch (Exceptions\TelevisionApiCall | Exceptions\Encrypt | Exceptions\Decrypt $ex) {
			$this->logger->error(
				'Calling television api failed',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'devices-cmd',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
				],
			);

			$io->error('Something went wrong, television could not be created. Error was logged.');

			return;
		} catch (Throwable $ex) {
			$this->logger->error(
				'Unhandled error occur',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'devices-cmd',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
				],
			);

			$io->error('Something went wrong, television could not be created. Error was logged.');

			return;
		}

		$hdmi = [];

		$question = new Console\Question\ConfirmationQuestion(
			'Would you like to configure HDMI inputs?',
			false,
		);

		$configureHdmi = (bool) $io->askQuestion($question);

		if ($configureHdmi) {
			$io->note(
				'Now you have to provide name for configured HDMI input and its number. HDMI number is related to you television',
			);

			while (true) {
				$hdmiName = $this->askHdmiName($io);

				$hdmiIndex = $this->askHdmiIndex($io, $hdmiName);

				$hdmi[] = new Entities\Messages\DeviceHdmi(
					$hdmiIndex,
					$hdmiName,
				);

				$question = new Console\Question\ConfirmationQuestion(
					'Would you like to configure another HDMI input?',
					false,
				);

				$configureMode = (bool) $io->askQuestion($question);

				if (!$configureMode) {
					break;
				}
			}
		}

		$question = new Console\Question\ConfirmationQuestion(
			'Would you like to configure television MAC address?',
			false,
		);

		$configureMacAddress = (bool) $io->askQuestion($question);

		$macAddress = null;

		if ($configureMacAddress) {
			$io->note('MAC address will be used to turn on you television on');

			$macAddress = $this->askMacAddress($io);
		}

		$message = new Entities\Messages\ConfigureDevice(
			$connector->getId(),
			$specs->getSerialNumber(),
			$ipAddress,
			Entities\VieraDevice::DEFAULT_PORT,
			$specs->getFriendlyName() ?? $specs->getModelName(),
			trim(sprintf('%s %s', $specs->getModelName(), $specs->getModelNumber())),
			$specs->getManufacturer(),
			$specs->getSerialNumber(),
			$macAddress,
			$authorization?->getAppId() !== null && $authorization->getEncryptionKey() !== null,
			$authorization?->getAppId(),
			$authorization?->getEncryptionKey(),
			$hdmi,
			$apps !== null ? array_map(
				// phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
				static fn (Entities\API\Application $application): Entities\Messages\DeviceApplication => new Entities\Messages\DeviceApplication(
					$application->getId(),
					$application->getName(),
				),
				$apps->getApps(),
			) : [],
		);

		$this->consumer->append($message);

		$io->success(sprintf(
			'Television "%s" was successfully created',
			$message->getName() ?? $message->getIdentifier(),
		));
	}

	/**
	 * @throws DBAL\Exception
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	private function editExistingDevice(Style\SymfonyStyle $io, Entities\VieraConnector $connector): void
	{
		$device = $this->askWhichDevice($io, $connector);

		if ($device === null) {
			$io->warning('No televisions registered in Viera connector');

			$question = new Console\Question\ConfirmationQuestion(
				'Would you like to create new television in connector?',
				false,
			);

			$continue = (bool) $io->askQuestion($question);

			if ($continue) {
				$this->createNewDevice($io, $connector);
			}

			return;
		}

		$findDeviceChannel = new DevicesQueries\FindChannels();
		$findDeviceChannel->forDevice($device);
		$findDeviceChannel->byIdentifier(Types\ChannelType::TELEVISION);

		$channel = $this->channelsRepository->findOneBy($findDeviceChannel);

		$authorization = null;

		$name = $this->askDeviceName($io, $device);

		$findDevicePropertyQuery = new DevicesQueries\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::IDENTIFIER_IP_ADDRESS);

		$ipAddressProperty = $this->devicePropertiesRepository->findOneBy($findDevicePropertyQuery);

		if ($ipAddressProperty === null) {
			$changeIpAddress = true;

		} else {
			$question = new Console\Question\ConfirmationQuestion(
				'Do you want to change television IP address?',
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
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::IDENTIFIER_PORT);

		$portProperty = $this->devicePropertiesRepository->findOneBy($findDevicePropertyQuery);

		if ($portProperty === null) {
			$changePort = true;

		} else {
			$question = new Console\Question\ConfirmationQuestion(
				'Do you want to change television port?',
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
			$findChannelPropertyQuery->byIdentifier(Types\ChannelPropertyIdentifier::IDENTIFIER_HDMI);

			$hdmiProperty = $this->channelPropertiesRepository->findOneBy($findChannelPropertyQuery);
		}

		if ($hdmiProperty === null) {
			$question = new Console\Question\ConfirmationQuestion(
				'Would you like to configure HDMI inputs?',
				false,
			);

			$configureHdmi = (bool) $io->askQuestion($question);

		} else {
			$question = new Console\Question\ConfirmationQuestion(
				'Do you want to redefine television HDMI inputs?',
				false,
			);

			$configureHdmi = (bool) $io->askQuestion($question);
		}

		$hdmi = null;

		if ($configureHdmi) {
			$hdmi = [];

			$io->note(
				'Now you have to provide name for configured HDMI input and its number. HDMI number is related to you television',
			);

			while (true) {
				$hdmiName = $this->askHdmiName($io);

				$hdmiIndex = $this->askHdmiIndex($io, $hdmiName);

				$hdmi[$hdmiIndex] = $hdmiName;

				$question = new Console\Question\ConfirmationQuestion(
					'Would you like to configure another HDMI input?',
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
			$findChannelPropertyQuery->byIdentifier(Types\ChannelPropertyIdentifier::IDENTIFIER_APPLICATION);

			$appsProperty = $this->channelPropertiesRepository->findOneBy($findChannelPropertyQuery);
		}

		$findDevicePropertyQuery = new DevicesQueries\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::IDENTIFIER_APP_ID);

		$appIdProperty = $this->devicePropertiesRepository->findOneBy($findDevicePropertyQuery);

		$findDevicePropertyQuery = new DevicesQueries\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::IDENTIFIER_ENCRYPTION_KEY);

		$encryptionKeyProperty = $this->devicePropertiesRepository->findOneBy($findDevicePropertyQuery);

		$findDevicePropertyQuery = new DevicesQueries\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MODEL);

		$hardwareModelProperty = $this->devicePropertiesRepository->findOneBy($findDevicePropertyQuery);

		$findDevicePropertyQuery = new DevicesQueries\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MANUFACTURER);

		$hardwareManufacturerProperty = $this->devicePropertiesRepository->findOneBy($findDevicePropertyQuery);

		$findDevicePropertyQuery = new DevicesQueries\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MAC_ADDRESS);

		$macAddressProperty = $this->devicePropertiesRepository->findOneBy($findDevicePropertyQuery);

		if ($macAddressProperty === null) {
			$question = new Console\Question\ConfirmationQuestion(
				'Do you want to configure television MAC address?',
				false,
			);

			$changeMacAddress = (bool) $io->askQuestion($question);

		} else {
			$question = new Console\Question\ConfirmationQuestion(
				'Do you want to change television MAC address?',
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

			try {
				$isOnline = $televisionApi->livenessProbe(1.5, true);
			} catch (Throwable $ex) {
				$this->logger->error(
					'Checking TV status failed',
					[
						'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
						'type' => 'devices-cmd',
						'exception' => BootstrapHelpers\Logger::buildException($ex),
					],
				);

				$io->error('Something went wrong, television could not be edited. Error was logged.');

				return;
			}

			if ($isOnline === false) {
				$io->warning(sprintf('Television with IP: %s address is unreachable.', $ipAddress));

				return;
			}

			$specs = $televisionApi->getSpecs(false);

			try {
				$isTurnedOn = $televisionApi->isTurnedOn(true);
			} catch (Throwable $ex) {
				$this->logger->error(
					'Checking screen status failed',
					[
						'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
						'type' => 'devices-cmd',
						'exception' => BootstrapHelpers\Logger::buildException($ex),
					],
				);

				$io->error('Something went wrong, television could not be edited. Error was logged.');

				return;
			}

			if (!$device->isEncrypted() && $specs->isRequiresEncryption()) {
				$io->warning(
					'It looks like your TV require application pairing.',
				);

				if ($isTurnedOn === false) {
					$io->warning(
						'It looks like your TV is not turned on. It is possible that the pairing could not be finished.',
					);

					$question = new Console\Question\ConfirmationQuestion(
						'Would you like to continue?',
						false,
					);

					$continue = (bool) $io->askQuestion($question);

					if (!$continue) {
						return;
					}
				}

				$this->challengeKey = $televisionApi->requestPinCode(
					$connector->getName() ?? $connector->getIdentifier(),
					false,
				);

				$authorization = $this->askPinCode($io, $connector, $televisionApi);

				$televisionApi = $this->televisionApiFactory->create(
					$device->getIdentifier(),
					$ipAddress,
					$port,
					$authorization->getAppId(),
					$authorization->getEncryptionKey(),
				);
				$televisionApi->connect();
			}

			$apps = $isTurnedOn ? $televisionApi->getApps(false) : null;
		} catch (Exceptions\TelevisionApiCall | Exceptions\Encrypt | Exceptions\Decrypt $ex) {
			$this->logger->error(
				'Calling television api failed',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'devices-cmd',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
				],
			);

			$io->error('Something went wrong, television could not be edited. Error was logged.');

			return;
		} catch (Throwable $ex) {
			$this->logger->error(
				'Unhandled error occur',
				[
					'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
					'type' => 'devices-cmd',
					'exception' => BootstrapHelpers\Logger::buildException($ex),
				],
			);

			$io->error('Something went wrong, television could not be edited. Error was logged.');

			return;
		}

		try {
			// Start transaction connection to the database
			$this->getOrmConnection()->beginTransaction();

			$device = $this->devicesManager->update($device, Utils\ArrayHash::from([
				'name' => $name,
			]));
			assert($device instanceof Entities\VieraDevice);

			if ($ipAddressProperty === null) {
				$this->devicePropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::IDENTIFIER_IP_ADDRESS,
					'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::IDENTIFIER_IP_ADDRESS),
					'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
					'value' => $ipAddress,
					'device' => $device,
				]));
			} elseif ($ipAddress !== null) {
				$this->devicePropertiesManager->update($ipAddressProperty, Utils\ArrayHash::from([
					'value' => $ipAddress,
				]));
			}

			if ($portProperty === null) {
				$this->devicePropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::IDENTIFIER_PORT,
					'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::IDENTIFIER_PORT),
					'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_UINT),
					'value' => $port,
					'device' => $device,
				]));
			} else {
				$this->devicePropertiesManager->update($portProperty, Utils\ArrayHash::from([
					'value' => $port,
				]));
			}

			if ($appIdProperty === null && $authorization !== null) {
				$this->devicePropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::IDENTIFIER_APP_ID,
					'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::IDENTIFIER_APP_ID),
					'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
					'value' => $authorization->getAppId(),
					'device' => $device,
				]));
			} elseif ($appIdProperty !== null && $authorization !== null) {
				$this->devicePropertiesManager->update($appIdProperty, Utils\ArrayHash::from([
					'value' => $authorization->getAppId(),
				]));
			} elseif ($appIdProperty !== null && $authorization === null) {
				$this->devicePropertiesManager->delete($appIdProperty);
			}

			if ($encryptionKeyProperty === null && $authorization !== null) {
				$this->devicePropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::IDENTIFIER_ENCRYPTION_KEY,
					'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::IDENTIFIER_ENCRYPTION_KEY),
					'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
					'value' => $authorization->getEncryptionKey(),
					'device' => $device,
				]));
			} elseif ($encryptionKeyProperty !== null && $authorization !== null) {
				$this->devicePropertiesManager->update($encryptionKeyProperty, Utils\ArrayHash::from([
					'value' => $authorization->getEncryptionKey(),
				]));
			} elseif ($encryptionKeyProperty !== null && $authorization === null) {
				$this->devicePropertiesManager->delete($encryptionKeyProperty);
			}

			if ($hardwareModelProperty === null) {
				$this->devicePropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MODEL,
					'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MODEL),
					'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
					'value' => trim(sprintf('%s %s', $specs->getModelName(), $specs->getModelNumber())),
					'device' => $device,
				]));
			} else {
				$this->devicePropertiesManager->update($hardwareModelProperty, Utils\ArrayHash::from([
					'value' => trim(sprintf('%s %s', $specs->getModelName(), $specs->getModelNumber())),
				]));
			}

			if ($hardwareManufacturerProperty === null) {
				$this->devicePropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MODEL,
					'name' => Helpers\Name::createName(
						Types\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MANUFACTURER,
					),
					'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
					'value' => $specs->getManufacturer(),
					'device' => $device,
				]));
			} else {
				$this->devicePropertiesManager->update($hardwareManufacturerProperty, Utils\ArrayHash::from([
					'value' => $specs->getManufacturer(),
				]));
			}

			if ($macAddressProperty === null) {
				$this->devicePropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MAC_ADDRESS,
					'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::IDENTIFIER_HARDWARE_MAC_ADDRESS),
					'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
					'value' => $macAddress,
					'device' => $device,
				]));
			} elseif ($macAddress !== null) {
				$this->devicePropertiesManager->update($macAddressProperty, Utils\ArrayHash::from([
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
					$this->channelPropertiesManager->create(Utils\ArrayHash::from([
						'entity' => DevicesEntities\Channels\Properties\Dynamic::class,
						'identifier' => Types\ChannelPropertyIdentifier::IDENTIFIER_HDMI,
						'name' => Helpers\Name::createName(Types\ChannelPropertyIdentifier::IDENTIFIER_HDMI),
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
					$this->channelPropertiesManager->update($hdmiProperty, Utils\ArrayHash::from([
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
					$this->channelPropertiesManager->create(Utils\ArrayHash::from([
						'entity' => DevicesEntities\Channels\Properties\Dynamic::class,
						'identifier' => Types\ChannelPropertyIdentifier::IDENTIFIER_APPLICATION,
						'name' => Helpers\Name::createName(Types\ChannelPropertyIdentifier::IDENTIFIER_APPLICATION),
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
					$this->channelPropertiesManager->update($appsProperty, Utils\ArrayHash::from([
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

			$io->success(sprintf(
				'Television "%s" was successfully updated',
				$device->getName() ?? $device->getIdentifier(),
			));
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

			$io->error('Something went wrong, television could not be updated. Error was logged.');
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
	private function deleteExistingDevice(Style\SymfonyStyle $io, Entities\VieraConnector $connector): void
	{
		$device = $this->askWhichDevice($io, $connector);

		if ($device === null) {
			$io->info('No Viera televisions registered in selected connector');

			return;
		}

		$question = new Console\Question\ConfirmationQuestion(
			'Would you like to continue?',
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

			$io->success(sprintf(
				'Television "%s" was successfully removed',
				$device->getName() ?? $device->getIdentifier(),
			));
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

			$io->error('Something went wrong, television could not be removed. Error was logged.');
		} finally {
			// Revert all changes when error occur
			if ($this->getOrmConnection()->isTransactionActive()) {
				$this->getOrmConnection()->rollBack();
			}
		}
	}

	private function askDeviceName(Style\SymfonyStyle $io, Entities\VieraDevice|null $device = null): string|null
	{
		$question = new Console\Question\Question('Provide television name', $device?->getName());

		$name = $io->askQuestion($question);

		return strval($name) === '' ? null : strval($name);
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	private function askIpAddress(Style\SymfonyStyle $io, Entities\VieraDevice|null $device = null): string
	{
		$question = new Console\Question\Question('Provide television IP address', $device?->getIpAddress());
		$question->setValidator(static function (string|null $answer): string {
			if ($answer !== null && preg_match(self::MATCH_IP_ADDRESS, $answer) === 1) {
				return $answer;
			}

			throw new Exceptions\Runtime('Provided IP address is not valid');
		});

		$ipAddress = $io->askQuestion($question);

		return strval($ipAddress);
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	private function askPort(Style\SymfonyStyle $io, Entities\VieraDevice|null $device = null): int
	{
		$question = new Console\Question\Question('Provide television port number', $device?->getPort());
		$question->setValidator(static function (string|null $answer): int {
			if ($answer !== null && strval(intval($answer)) === $answer) {
				return intval($answer);
			}

			throw new Exceptions\Runtime('Provided port number is not valid');
		});

		$port = $io->askQuestion($question);

		return intval($port);
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	private function askMacAddress(Style\SymfonyStyle $io, Entities\VieraDevice|null $device = null): string
	{
		$question = new Console\Question\Question(
			'Provide television MAC address in format: 01:23:45:67:89:ab',
			$device?->getMacAddress(),
		);
		$question->setValidator(static function (string|null $answer): string {
			if ($answer !== null && preg_match(self::MATCH_MAC_ADDRESS, $answer) === 1) {
				return $answer;
			}

			throw new Exceptions\Runtime('Provided mac address is not valid');
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
		$question = new Console\Question\Question('Provide television PIN code displayed on you TV');
		$question->setValidator(
			function (string|null $answer) use ($connector, $televisionApi): Entities\API\AuthorizePinCode {
				if ($answer !== null && $answer !== '') {
					try {
						return $televisionApi->authorizePinCode($answer, strval($this->challengeKey), false);
					} catch (Exceptions\TelevisionApiCall) {
						$this->challengeKey = $televisionApi->requestPinCode(
							$connector->getName() ?? $connector->getIdentifier(),
							false,
						);

						throw new Exceptions\Runtime('Provided PIN code is not valid');
					}
				}

				throw new Exceptions\Runtime('Provided PIN code is not valid');
			},
		);

		$authorization = $io->askQuestion($question);
		assert($authorization instanceof Entities\API\AuthorizePinCode);

		return $authorization;
	}

	private function askHdmiName(Style\SymfonyStyle $io): string
	{
		$question = new Console\Question\Question('Provide name for HDMI input');
		$question->setValidator(static function (string|null $answer): string {
			if ($answer !== null) {
				return $answer;
			}

			throw new Exceptions\Runtime('Provided HDMI name is not valid');
		});

		$ipAddress = $io->askQuestion($question);

		return strval($ipAddress);
	}

	private function askHdmiIndex(Style\SymfonyStyle $io, string $name): int
	{
		$question = new Console\Question\Question(sprintf('Provide number for "%s" HDMI input', $name));
		$question->setValidator(static function (string|null $answer): int {
			if (
				$answer !== null
				&& strval(intval($answer)) === $answer
				&& intval($answer) > 0
				&& intval($answer) < 10
			) {
				return intval($answer);
			}

			throw new Exceptions\Runtime('Provided HDMI number is not valid');
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

		$findConnectorsQuery = new DevicesQueries\FindConnectors();

		$systemConnectors = $this->connectorsRepository->findAllBy(
			$findConnectorsQuery,
			Entities\VieraConnector::class,
		);
		usort(
			$systemConnectors,
			// phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
			static fn (DevicesEntities\Connectors\Connector $a, DevicesEntities\Connectors\Connector $b): int => $a->getIdentifier() <=> $b->getIdentifier()
		);

		foreach ($systemConnectors as $connector) {
			assert($connector instanceof Entities\VieraConnector);

			$connectors[$connector->getIdentifier()] = $connector->getIdentifier()
				. ($connector->getName() !== null ? ' [' . $connector->getName() . ']' : '');
		}

		if (count($connectors) === 0) {
			return null;
		}

		$question = new Console\Question\ChoiceQuestion(
			'Please select connector under which you want to manage devices',
			array_values($connectors),
			count($connectors) === 1 ? 0 : null,
		);
		$question->setErrorMessage('Selected connector: "%s" is not valid.');
		$question->setValidator(function (string|null $answer) use ($connectors): Entities\VieraConnector {
			if ($answer === null) {
				throw new Exceptions\InvalidState('Selected answer is not valid');
			}

			if (array_key_exists($answer, array_values($connectors))) {
				$answer = array_values($connectors)[$answer];
			}

			$identifier = array_search($answer, $connectors, true);

			if ($identifier !== false) {
				$findConnectorQuery = new DevicesQueries\FindConnectors();
				$findConnectorQuery->byIdentifier($identifier);

				$connector = $this->connectorsRepository->findOneBy(
					$findConnectorQuery,
					Entities\VieraConnector::class,
				);
				assert($connector instanceof Entities\VieraConnector || $connector === null);

				if ($connector !== null) {
					return $connector;
				}
			}

			throw new Exceptions\InvalidState('Selected answer is not valid');
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

		$findDevicesQuery = new DevicesQueries\FindDevices();
		$findDevicesQuery->forConnector($connector);

		$connectorDevices = $this->devicesRepository->findAllBy($findDevicesQuery, Entities\VieraDevice::class);
		usort(
			$connectorDevices,
			static fn (DevicesEntities\Devices\Device $a, DevicesEntities\Devices\Device $b): int => $a->getIdentifier() <=> $b->getIdentifier()
		);

		foreach ($connectorDevices as $device) {
			assert($device instanceof Entities\VieraDevice);

			$devices[$device->getIdentifier()] = $device->getIdentifier()
				. ($device->getName() !== null ? ' [' . $device->getName() . ']' : '');
		}

		if (count($devices) === 0) {
			return null;
		}

		$question = new Console\Question\ChoiceQuestion(
			'Please select television to manage',
			array_values($devices),
			count($devices) === 1 ? 0 : null,
		);
		$question->setErrorMessage('Selected device: "%s" is not valid.');
		$question->setValidator(function (string|null $answer) use ($connector, $devices): Entities\VieraDevice {
			if ($answer === null) {
				throw new Exceptions\Runtime('You have to select television from list');
			}

			if (array_key_exists($answer, array_values($devices))) {
				$answer = array_values($devices)[$answer];
			}

			$identifier = array_search($answer, $devices, true);

			if ($identifier !== false) {
				$findDeviceQuery = new DevicesQueries\FindDevices();
				$findDeviceQuery->byIdentifier($identifier);
				$findDeviceQuery->forConnector($connector);

				$device = $this->devicesRepository->findOneBy($findDeviceQuery, Entities\VieraDevice::class);
				assert($device instanceof Entities\VieraDevice || $device === null);

				if ($device !== null) {
					return $device;
				}
			}

			throw new Exceptions\Runtime('You have to select television from list');
		});

		$device = $io->askQuestion($question);
		assert($device instanceof Entities\VieraDevice);

		return $device;
	}

	private function checkAndTerminate(): void
	{
		if ($this->consumer->isEmpty()) {
			if ($this->consumerTimer !== null) {
				$this->eventLoop->cancelTimer($this->consumerTimer);
			}

			$this->eventLoop->stop();

		} else {
			if (
				$this->executedTime !== null
				&& $this->dateTimeFactory->getNow()->getTimestamp() - $this->executedTime->getTimestamp() > self::MAX_PROCESSING_INTERVAL
			) {
				$this->logger->error(
					'Discovery exceeded reserved time and have been terminated',
					[
						'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_TUYA,
						'type' => 'discovery-cmd',
					],
				);

				if ($this->consumerTimer !== null) {
					$this->eventLoop->cancelTimer($this->consumerTimer);
				}

				$this->eventLoop->stop();

				return;
			}

			$this->eventLoop->addTimer(
				self::WAITING_INTERVAL,
				async(function (): void {
					$this->checkAndTerminate();
				}),
			);
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

		throw new Exceptions\Runtime('Transformer manager could not be loaded');
	}

}
