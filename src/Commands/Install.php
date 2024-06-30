<?php declare(strict_types = 1);

/**
 * Install.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Commands
 * @since          1.0.0
 *
 * @date           14.12.23
 */

namespace FastyBird\Connector\Viera\Commands;

use DateTimeInterface;
use Doctrine\DBAL;
use FastyBird\Connector\Viera;
use FastyBird\Connector\Viera\API;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Connector\Viera\Queries;
use FastyBird\Connector\Viera\Types;
use FastyBird\DateTimeFactory;
use FastyBird\Library\Application\Exceptions as ApplicationExceptions;
use FastyBird\Library\Application\Helpers as ApplicationHelpers;
use FastyBird\Library\Metadata\Exceptions as MetadataExceptions;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Commands as DevicesCommands;
use FastyBird\Module\Devices\Entities as DevicesEntities;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use FastyBird\Module\Devices\Models as DevicesModels;
use FastyBird\Module\Devices\Types as DevicesTypes;
use FastyBird\Module\Devices\Utilities as DevicesUtilities;
use IPub\DoctrineCrud\Exceptions as DoctrineCrudExceptions;
use Nette\Localization;
use Nette\Utils;
use RuntimeException;
use Symfony\Component\Console;
use Symfony\Component\Console\Input;
use Symfony\Component\Console\Output;
use Symfony\Component\Console\Style;
use Throwable;
use TypeError;
use ValueError;
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
 * Connector install command
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Commands
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class Install extends Console\Command\Command
{

	public const NAME = 'fb:viera-connector:install';
	// phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
	private const MATCH_IP_ADDRESS = '/^((?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])[.]){3}(?:[0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])$/';

	private const MATCH_MAC_ADDRESS = '/^([0-9a-fA-F][0-9a-fA-F]:){5}([0-9a-fA-F][0-9a-fA-F])$/';

	private string|null $challengeKey = null;

	private Input\InputInterface|null $input = null;

	private Output\OutputInterface|null $output = null;

	public function __construct(
		private readonly API\TelevisionApiFactory $televisionApiFactory,
		private readonly Viera\Logger $logger,
		private readonly DevicesModels\Entities\Connectors\ConnectorsRepository $connectorsRepository,
		private readonly DevicesModels\Entities\Connectors\ConnectorsManager $connectorsManager,
		private readonly DevicesModels\Entities\Devices\DevicesRepository $devicesRepository,
		private readonly DevicesModels\Entities\Devices\DevicesManager $devicesManager,
		private readonly DevicesModels\Entities\Devices\Properties\PropertiesRepository $devicesPropertiesRepository,
		private readonly DevicesModels\Entities\Devices\Properties\PropertiesManager $devicesPropertiesManager,
		private readonly DevicesModels\Entities\Channels\ChannelsRepository $channelsRepository,
		private readonly DevicesModels\Entities\Channels\ChannelsManager $channelsManager,
		private readonly DevicesModels\Entities\Channels\Properties\PropertiesRepository $channelsPropertiesRepository,
		private readonly DevicesModels\Entities\Channels\Properties\PropertiesManager $channelsPropertiesManager,
		private readonly ApplicationHelpers\Database $databaseHelper,
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
			->setDescription('Viera connector installer');
	}

	/**
	 * @throws ApplicationExceptions\InvalidState
	 * @throws Console\Exception\ExceptionInterface
	 * @throws DBAL\Exception
	 * @throws DevicesExceptions\InvalidState
	 * @throws DoctrineCrudExceptions\InvalidArgumentException
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\InvalidState
	 * @throws Exceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws RuntimeException
	 * @throws TypeError
	 * @throws ValueError
	 */
	protected function execute(Input\InputInterface $input, Output\OutputInterface $output): int
	{
		$this->input = $input;
		$this->output = $output;

		$io = new Style\SymfonyStyle($this->input, $this->output);

		$io->title((string) $this->translator->translate('//viera-connector.cmd.install.title'));

		$io->note((string) $this->translator->translate('//viera-connector.cmd.install.subtitle'));

		$this->askInstallAction($io);

		return Console\Command\Command::SUCCESS;
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws RuntimeException
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function createConnector(Style\SymfonyStyle $io): void
	{
		$question = new Console\Question\Question(
			(string) $this->translator->translate(
				'//viera-connector.cmd.install.questions.provide.connector.identifier',
			),
		);

		$question->setValidator(function ($answer) {
			if ($answer !== null) {
				$findConnectorQuery = new Queries\Entities\FindConnectors();
				$findConnectorQuery->byIdentifier($answer);

				if ($this->connectorsRepository->findOneBy(
					$findConnectorQuery,
					Entities\Connectors\Connector::class,
				) !== null) {
					throw new Exceptions\Runtime(
						(string) $this->translator->translate(
							'//viera-connector.cmd.install.messages.identifier.connector.used',
						),
					);
				}
			}

			return $answer;
		});

		$identifier = $io->askQuestion($question);

		if ($identifier === '' || $identifier === null) {
			$identifierPattern = 'viera-%d';

			for ($i = 1; $i <= 100; $i++) {
				$identifier = sprintf($identifierPattern, $i);

				$findConnectorQuery = new Queries\Entities\FindConnectors();
				$findConnectorQuery->byIdentifier($identifier);

				if ($this->connectorsRepository->findOneBy(
					$findConnectorQuery,
					Entities\Connectors\Connector::class,
				) === null) {
					break;
				}
			}
		}

		if ($identifier === '') {
			$io->error(
				(string) $this->translator->translate(
					'//viera-connector.cmd.install.messages.identifier.connector.missing',
				),
			);

			return;
		}

		$name = $this->askConnectorName($io);

		try {
			// Start transaction connection to the database
			$this->databaseHelper->beginTransaction();

			$connector = $this->connectorsManager->create(Utils\ArrayHash::from([
				'entity' => Entities\Connectors\Connector::class,
				'identifier' => $identifier,
				'name' => $name === '' ? null : $name,
			]));
			assert($connector instanceof Entities\Connectors\Connector);

			// Commit all changes into database
			$this->databaseHelper->commitTransaction();

			$io->success(
				(string) $this->translator->translate(
					'//viera-connector.cmd.install.messages.create.connector.success',
					['name' => $connector->getName() ?? $connector->getIdentifier()],
				),
			);
		} catch (Throwable $ex) {
			// Log caught exception
			$this->logger->error(
				'An unhandled error occurred',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'install-cmd',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
				],
			);

			$io->error(
				(string) $this->translator->translate('//viera-connector.cmd.install.messages.create.connector.error'),
			);

			return;
		} finally {
			$this->databaseHelper->clear();
		}

		$question = new Console\Question\ConfirmationQuestion(
			(string) $this->translator->translate('//viera-connector.cmd.install.questions.create.devices'),
			true,
		);

		$createDevices = (bool) $io->askQuestion($question);

		if ($createDevices) {
			$connector = $this->connectorsRepository->find(
				$connector->getId(),
				Entities\Connectors\Connector::class,
			);
			assert($connector instanceof Entities\Connectors\Connector);

			$this->createDevice($io, $connector);
		}
	}

	/**
	 * @throws Console\Exception\ExceptionInterface
	 * @throws DBAL\Exception
	 * @throws DevicesExceptions\InvalidState
	 * @throws DoctrineCrudExceptions\InvalidArgumentException
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws RuntimeException
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function editConnector(Style\SymfonyStyle $io): void
	{
		$connector = $this->askWhichConnector($io);

		if ($connector === null) {
			$io->warning((string) $this->translator->translate('//viera-connector.cmd.base.messages.noConnectors'));

			$question = new Console\Question\ConfirmationQuestion(
				(string) $this->translator->translate('//viera-connector.cmd.install.questions.create.connector'),
				false,
			);

			$continue = (bool) $io->askQuestion($question);

			if ($continue) {
				$this->createConnector($io);
			}

			return;
		}

		$name = $this->askConnectorName($io, $connector);

		$enabled = $connector->isEnabled();

		if ($connector->isEnabled()) {
			$question = new Console\Question\ConfirmationQuestion(
				(string) $this->translator->translate('//viera-connector.cmd.install.questions.disable.connector'),
				false,
			);

			if ($io->askQuestion($question) === true) {
				$enabled = false;
			}
		} else {
			$question = new Console\Question\ConfirmationQuestion(
				(string) $this->translator->translate('//viera-connector.cmd.install.questions.enable.connector'),
				false,
			);

			if ($io->askQuestion($question) === true) {
				$enabled = true;
			}
		}

		try {
			// Start transaction connection to the database
			$this->databaseHelper->beginTransaction();

			$connector = $this->connectorsManager->update($connector, Utils\ArrayHash::from([
				'name' => $name === '' ? null : $name,
				'enabled' => $enabled,
			]));
			assert($connector instanceof Entities\Connectors\Connector);

			// Commit all changes into database
			$this->databaseHelper->commitTransaction();

			$io->success(
				(string) $this->translator->translate(
					'//viera-connector.cmd.install.messages.update.connector.success',
					['name' => $connector->getName() ?? $connector->getIdentifier()],
				),
			);
		} catch (Throwable $ex) {
			// Log caught exception
			$this->logger->error(
				'An unhandled error occurred',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'install-cmd',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
				],
			);

			$io->error(
				(string) $this->translator->translate('//viera-connector.cmd.install.messages.update.connector.error'),
			);

			return;
		} finally {
			$this->databaseHelper->clear();
		}

		$question = new Console\Question\ConfirmationQuestion(
			(string) $this->translator->translate('//viera-connector.cmd.install.questions.manage.devices'),
			false,
		);

		$manage = (bool) $io->askQuestion($question);

		if (!$manage) {
			return;
		}

		$connector = $this->connectorsRepository->find(
			$connector->getId(),
			Entities\Connectors\Connector::class,
		);
		assert($connector instanceof Entities\Connectors\Connector);

		$this->askManageConnectorAction($io, $connector);
	}

	/**
	 * @throws ApplicationExceptions\InvalidState
	 * @throws DevicesExceptions\InvalidState
	 */
	private function deleteConnector(Style\SymfonyStyle $io): void
	{
		$connector = $this->askWhichConnector($io);

		if ($connector === null) {
			$io->info((string) $this->translator->translate('//viera-connector.cmd.base.messages.noConnectors'));

			return;
		}

		$io->warning(
			(string) $this->translator->translate(
				'//viera-connector.cmd.install.messages.remove.connector.confirm',
				['name' => $connector->getName() ?? $connector->getIdentifier()],
			),
		);

		$question = new Console\Question\ConfirmationQuestion(
			(string) $this->translator->translate('//viera-connector.cmd.base.questions.continue'),
			false,
		);

		$continue = (bool) $io->askQuestion($question);

		if (!$continue) {
			return;
		}

		try {
			// Start transaction connection to the database
			$this->databaseHelper->beginTransaction();

			$this->connectorsManager->delete($connector);

			// Commit all changes into database
			$this->databaseHelper->commitTransaction();

			$io->success(
				(string) $this->translator->translate(
					'//viera-connector.cmd.install.messages.remove.connector.success',
					['name' => $connector->getName() ?? $connector->getIdentifier()],
				),
			);
		} catch (Throwable $ex) {
			// Log caught exception
			$this->logger->error(
				'An unhandled error occurred',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'install-cmd',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
				],
			);

			$io->error(
				(string) $this->translator->translate('//viera-connector.cmd.install.messages.remove.connector.error'),
			);
		} finally {
			$this->databaseHelper->clear();
		}
	}

	/**
	 * @throws ApplicationExceptions\InvalidState
	 * @throws Console\Exception\ExceptionInterface
	 * @throws DBAL\Exception
	 * @throws DevicesExceptions\InvalidState
	 * @throws DoctrineCrudExceptions\InvalidArgumentException
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\InvalidState
	 * @throws Exceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws RuntimeException
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function manageConnector(Style\SymfonyStyle $io): void
	{
		$connector = $this->askWhichConnector($io);

		if ($connector === null) {
			$io->info((string) $this->translator->translate('//viera-connector.cmd.base.messages.noConnectors'));

			return;
		}

		$this->askManageConnectorAction($io, $connector);
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 */
	private function listConnectors(Style\SymfonyStyle $io): void
	{
		$findConnectorsQuery = new Queries\Entities\FindConnectors();

		$connectors = $this->connectorsRepository->findAllBy(
			$findConnectorsQuery,
			Entities\Connectors\Connector::class,
		);
		usort(
			$connectors,
			static fn (Entities\Connectors\Connector $a, Entities\Connectors\Connector $b): int => (
				($a->getName() ?? $a->getIdentifier()) <=> ($b->getName() ?? $b->getIdentifier())
			),
		);

		$table = new Console\Helper\Table($io);
		$table->setHeaders([
			'#',
			(string) $this->translator->translate('//viera-connector.cmd.install.data.name'),
			(string) $this->translator->translate('//viera-connector.cmd.install.data.devicesCnt'),
		]);

		foreach ($connectors as $index => $connector) {
			$findDevicesQuery = new Queries\Entities\FindDevices();
			$findDevicesQuery->forConnector($connector);

			$devices = $this->devicesRepository->findAllBy($findDevicesQuery, Entities\Devices\Device::class);

			$table->addRow([
				$index + 1,
				$connector->getName() ?? $connector->getIdentifier(),
				count($devices),
			]);
		}

		$table->render();

		$io->newLine();
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws RuntimeException
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function createDevice(Style\SymfonyStyle $io, Entities\Connectors\Connector $connector): void
	{
		$tempIdentifier = 'new-device-' . $this->dateTimeFactory->getNow()->format(DateTimeInterface::ATOM);

		$ipAddress = $this->askDeviceIpAddress($io);

		try {
			$televisionApi = $this->televisionApiFactory->create(
				$tempIdentifier,
				$ipAddress,
				Entities\Devices\Device::DEFAULT_PORT,
			);
			$televisionApi->connect();
		} catch (Exceptions\TelevisionApiCall | Exceptions\TelevisionApiError | Exceptions\InvalidState $ex) {
			$io->error(
				(string) $this->translator->translate('//viera-connector.cmd.install.messages.device.connectionFailed'),
			);

			$this->logger->error(
				'Creating api client failed',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'install-cmd',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
				],
			);

			return;
		}

		try {
			$isOnline = $televisionApi->livenessProbe(1.5, true);
		} catch (Exceptions\TelevisionApiError $ex) {
			$io->error(
				(string) $this->translator->translate('//viera-connector.cmd.install.messages.device.connectionFailed'),
			);

			$this->logger->error(
				'Checking TV status failed',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'install-cmd',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
				],
			);

			return;
		}

		if ($isOnline === false) {
			$io->error(
				(string) $this->translator->translate(
					'//viera-connector.cmd.install.messages.device.unreachable',
					['address' => $ipAddress],
				),
			);

			return;
		}

		try {
			$specs = $televisionApi->getSpecs(false);
		} catch (Exceptions\TelevisionApiError $ex) {
			$io->error(
				(string) $this->translator->translate(
					'//viera-connector.cmd.install.messages.device.loadingSpecsFailed',
				),
			);

			$this->logger->error(
				'Loading TV specification failed',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'install-cmd',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
				],
			);

			return;
		} catch (Exceptions\TelevisionApiCall $ex) {
			$io->error(
				(string) $this->translator->translate(
					'//viera-connector.cmd.install.messages.device.loadingSpecsFailed',
				),
			);

			$this->logger->error(
				'Loading TV specification failed',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'install-cmd',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
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

		try {
			$isTurnedOn = $televisionApi->isTurnedOn(true);
		} catch (Exceptions\TelevisionApiError $ex) {
			$io->error(
				(string) $this->translator->translate(
					'//viera-connector.cmd.install.messages.device.checkStatusFailed',
				),
			);

			$this->logger->error(
				'Checking screen status failed',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'install-cmd',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
				],
			);

			return;
		}

		if ($specs->isRequiresEncryption()) {
			$io->warning(
				(string) $this->translator->translate('//viera-connector.cmd.install.messages.device.needPairing'),
			);

			if ($isTurnedOn === false) {
				$io->warning(
					(string) $this->translator->translate('//viera-connector.cmd.install.messages.device.offline'),
				);

				$question = new Console\Question\ConfirmationQuestion(
					(string) $this->translator->translate('//viera-connector.cmd.base.questions.continue'),
					false,
				);

				$continue = (bool) $io->askQuestion($question);

				if (!$continue) {
					return;
				}
			}

			try {
				$this->challengeKey = $televisionApi
					->requestPinCode($connector->getName() ?? $connector->getIdentifier(), false)
					->getChallengeKey();

				$authorization = $this->askDevicePinCode($io, $connector, $televisionApi);

				$televisionApi = $this->televisionApiFactory->create(
					$tempIdentifier,
					$ipAddress,
					Entities\Devices\Device::DEFAULT_PORT,
					$authorization->getAppId(),
					$authorization->getEncryptionKey(),
				);
				$televisionApi->connect();
			} catch (Exceptions\TelevisionApiCall | Exceptions\TelevisionApiError | Exceptions\InvalidState $ex) {
				$io->error(
					(string) $this->translator->translate(
						'//viera-connector.cmd.install.messages.device.connectionFailed',
					),
				);

				$this->logger->error(
					'Pin code pairing failed',
					[
						'source' => MetadataTypes\Sources\Connector::VIERA->value,
						'type' => 'install-cmd',
						'exception' => ApplicationHelpers\Logger::buildException($ex),
					],
				);

				return;
			}
		}

		try {
			$apps = $isTurnedOn ? $televisionApi->getApps(false) : null;
		} catch (Exceptions\TelevisionApiError | Exceptions\TelevisionApiCall | Exceptions\InvalidState $ex) {
			$io->error(
				(string) $this->translator->translate(
					'//viera-connector.cmd.install.messages.device.loadingAppsFailed',
				),
			);

			$this->logger->error(
				'Loading apps failed',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'install-cmd',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
				],
			);

			return;
		}

		$hdmi = [];

		$question = new Console\Question\ConfirmationQuestion(
			(string) $this->translator->translate('//viera-connector.cmd.install.questions.configure.hdmi'),
			false,
		);

		$configureHdmi = (bool) $io->askQuestion($question);

		if ($configureHdmi) {
			$io->note(
				(string) $this->translator->translate('//viera-connector.cmd.install.messages.info.hdmi'),
			);

			while (true) {
				$hdmiName = $this->askDeviceHdmiName($io);

				$hdmiIndex = $this->askDeviceHdmiIndex($io, $hdmiName);

				$hdmi[] = [
					Helpers\Name::sanitizeEnumName($hdmiName),
					$hdmiIndex,
					$hdmiIndex,
				];

				$question = new Console\Question\ConfirmationQuestion(
					(string) $this->translator->translate('//viera-connector.cmd.install.questions.configure.nextHdmi'),
					false,
				);

				$configureMode = (bool) $io->askQuestion($question);

				if (!$configureMode) {
					break;
				}
			}
		}

		$question = new Console\Question\ConfirmationQuestion(
			(string) $this->translator->translate('//viera-connector.cmd.install.questions.configure.macAddress'),
			false,
		);

		$configureMacAddress = (bool) $io->askQuestion($question);

		$macAddress = null;

		if ($configureMacAddress) {
			$io->note(
				(string) $this->translator->translate('//viera-connector.cmd.install.messages.info.macAddress'),
			);

			$macAddress = $this->askDeviceMacAddress($io);
		}

		try {
			// Start transaction connection to the database
			$this->databaseHelper->beginTransaction();

			$device = $this->devicesManager->create(Utils\ArrayHash::from([
				'entity' => Entities\Devices\Device::class,
				'connector' => $connector,
				'identifier' => $specs->getSerialNumber(),
				'name' => $specs->getFriendlyName() ?? $specs->getModelName(),
			]));
			assert($device instanceof Entities\Devices\Device);

			$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Devices\Properties\Variable::class,
				'identifier' => Types\DevicePropertyIdentifier::IP_ADDRESS->value,
				'name' => DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::IP_ADDRESS->value),
				'dataType' => MetadataTypes\DataType::STRING,
				'value' => $ipAddress,
				'device' => $device,
			]));

			$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Devices\Properties\Variable::class,
				'identifier' => Types\DevicePropertyIdentifier::PORT->value,
				'name' => DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::PORT->value),
				'dataType' => MetadataTypes\DataType::UINT,
				'value' => Entities\Devices\Device::DEFAULT_PORT,
				'device' => $device,
			]));

			$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Devices\Properties\Variable::class,
				'identifier' => Types\DevicePropertyIdentifier::MODEL->value,
				'name' => DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::MODEL->value),
				'dataType' => MetadataTypes\DataType::STRING,
				'value' => trim(sprintf('%s %s', $specs->getModelName(), $specs->getModelNumber())),
				'device' => $device,
			]));

			$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Devices\Properties\Variable::class,
				'identifier' => Types\DevicePropertyIdentifier::MANUFACTURER->value,
				'name' => DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::MANUFACTURER->value),
				'dataType' => MetadataTypes\DataType::STRING,
				'value' => $specs->getManufacturer(),
				'device' => $device,
			]));

			$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Devices\Properties\Variable::class,
				'identifier' => Types\DevicePropertyIdentifier::SERIAL_NUMBER->value,
				'name' => DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::SERIAL_NUMBER->value),
				'dataType' => MetadataTypes\DataType::STRING,
				'value' => $specs->getSerialNumber(),
				'device' => $device,
			]));

			if ($macAddress !== null) {
				$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::MAC_ADDRESS->value,
					'name' => DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::MAC_ADDRESS->value),
					'dataType' => MetadataTypes\DataType::STRING,
					'value' => $macAddress,
					'device' => $device,
				]));
			}

			$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Devices\Properties\Variable::class,
				'identifier' => Types\DevicePropertyIdentifier::ENCRYPTED->value,
				'name' => DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::ENCRYPTED->value),
				'dataType' => MetadataTypes\DataType::BOOLEAN,
				'value' => $specs->isRequiresEncryption(),
				'device' => $device,
			]));

			if ($authorization !== null) {
				$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::APP_ID->value,
					'name' => DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::APP_ID->value),
					'dataType' => MetadataTypes\DataType::STRING,
					'value' => $authorization->getAppId(),
					'device' => $device,
				]));

				$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::ENCRYPTION_KEY->value,
					'name' => DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::ENCRYPTION_KEY->value),
					'dataType' => MetadataTypes\DataType::STRING,
					'value' => $authorization->getEncryptionKey(),
					'device' => $device,
				]));
			}

			$channel = $this->channelsManager->create(Utils\ArrayHash::from([
				'entity' => Entities\Channels\Channel::class,
				'device' => $device,
				'identifier' => Types\ChannelType::TELEVISION->value,
			]));

			$this->channelsPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Channels\Properties\Dynamic::class,
				'identifier' => Types\ChannelPropertyIdentifier::STATE->value,
				'name' => DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::STATE->value),
				'dataType' => MetadataTypes\DataType::BOOLEAN,
				'settable' => true,
				'queryable' => true,
				'format' => null,
				'channel' => $channel,
			]));

			$this->channelsPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Channels\Properties\Dynamic::class,
				'identifier' => Types\ChannelPropertyIdentifier::VOLUME->value,
				'name' => DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::VOLUME->value),
				'dataType' => MetadataTypes\DataType::UCHAR,
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
				'identifier' => Types\ChannelPropertyIdentifier::MUTE->value,
				'name' => DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::MUTE->value),
				'dataType' => MetadataTypes\DataType::BOOLEAN,
				'settable' => true,
				'queryable' => true,
				'format' => null,
				'channel' => $channel,
			]));

			$this->channelsPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Channels\Properties\Dynamic::class,
				'identifier' => Types\ChannelPropertyIdentifier::HDMI->value,
				'name' => DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::HDMI->value),
				'dataType' => MetadataTypes\DataType::ENUM,
				'settable' => true,
				'queryable' => false,
				'format' => $hdmi !== [] ? $hdmi : null,
				'channel' => $channel,
			]));

			$this->channelsPropertiesManager->create(Utils\ArrayHash::from([
				'entity' => DevicesEntities\Channels\Properties\Dynamic::class,
				'identifier' => Types\ChannelPropertyIdentifier::APPLICATION->value,
				'name' => DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::APPLICATION->value),
				'dataType' => MetadataTypes\DataType::ENUM,
				'settable' => true,
				'queryable' => false,
				'format' => $apps !== null ? array_map(
					static fn (API\Messages\Response\Application $item): array => [
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
				'identifier' => Types\ChannelPropertyIdentifier::INPUT_SOURCE->value,
				'name' => DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::INPUT_SOURCE->value),
				'dataType' => MetadataTypes\DataType::ENUM,
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
						static fn (API\Messages\Response\Application $item): array => [
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
			$this->databaseHelper->commitTransaction();

			$io->success(
				(string) $this->translator->translate(
					'//viera-connector.cmd.install.messages.create.device.success',
					['name' => $device->getName() ?? $device->getIdentifier()],
				),
			);
		} catch (Throwable $ex) {
			// Log caught exception
			$this->logger->error(
				'An unhandled error occurred',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'install-cmd',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
				],
			);

			$io->error(
				(string) $this->translator->translate('//viera-connector.cmd.install.messages.create.device.error'),
			);

			return;
		} finally {
			$this->databaseHelper->clear();
		}
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws Exceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws RuntimeException
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function editDevice(Style\SymfonyStyle $io, Entities\Connectors\Connector $connector): void
	{
		$device = $this->askWhichDevice($io, $connector);

		if ($device === null) {
			$io->warning((string) $this->translator->translate('//viera-connector.cmd.install.messages.noDevices'));

			$question = new Console\Question\ConfirmationQuestion(
				(string) $this->translator->translate('//viera-connector.cmd.install.questions.create.device'),
				false,
			);

			$continue = (bool) $io->askQuestion($question);

			if ($continue) {
				$this->createDevice($io, $connector);
			}

			return;
		}

		$findChannel = new Queries\Entities\FindChannels();
		$findChannel->forDevice($device);
		$findChannel->byIdentifier(Types\ChannelType::TELEVISION);

		$channel = $this->channelsRepository->findOneBy($findChannel, Entities\Channels\Channel::class);

		$authorization = null;

		$name = $this->askDeviceName($io, $device);

		$findDevicePropertyQuery = new Queries\Entities\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::IP_ADDRESS);

		$ipAddressProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

		if ($ipAddressProperty === null) {
			$changeIpAddress = true;

		} else {
			$question = new Console\Question\ConfirmationQuestion(
				(string) $this->translator->translate('//viera-connector.cmd.install.questions.change.ipAddress'),
				false,
			);

			$changeIpAddress = (bool) $io->askQuestion($question);
		}

		$ipAddress = $device->getIpAddress();

		if ($changeIpAddress || $ipAddress === null) {
			$ipAddress = $this->askDeviceIpAddress($io, $device);
		}

		$findDevicePropertyQuery = new Queries\Entities\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::PORT);

		$portProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

		if ($portProperty === null) {
			$changePort = true;

		} else {
			$question = new Console\Question\ConfirmationQuestion(
				(string) $this->translator->translate('//viera-connector.cmd.install.questions.change.port'),
				false,
			);

			$changePort = (bool) $io->askQuestion($question);
		}

		$port = $device->getPort();

		if ($changePort) {
			$port = $this->askDevicePort($io, $device);
		}

		$hdmiProperty = null;

		if ($channel !== null) {
			$findChannelPropertyQuery = new Queries\Entities\FindChannelProperties();
			$findChannelPropertyQuery->forChannel($channel);
			$findChannelPropertyQuery->byIdentifier(Types\ChannelPropertyIdentifier::HDMI);

			$hdmiProperty = $this->channelsPropertiesRepository->findOneBy($findChannelPropertyQuery);
		}

		$question = $hdmiProperty === null ? new Console\Question\ConfirmationQuestion(
			(string) $this->translator->translate('//viera-connector.cmd.install.questions.configure.hdmi'),
			false,
		) : new Console\Question\ConfirmationQuestion(
			(string) $this->translator->translate('//viera-connector.cmd.install.questions.change.hdmi'),
			false,
		);

		$configureHdmi = (bool) $io->askQuestion($question);

		$hdmi = null;

		if ($configureHdmi) {
			$hdmi = [];

			$io->note(
				(string) $this->translator->translate('//viera-connector.cmd.install.messages.info.hdmi'),
			);

			while (true) {
				$hdmiName = $this->askDeviceHdmiName($io);

				$hdmiIndex = $this->askDeviceHdmiIndex($io, $hdmiName);

				$hdmi[$hdmiIndex] = $hdmiName;

				$question = new Console\Question\ConfirmationQuestion(
					(string) $this->translator->translate('//viera-connector.cmd.install.questions.configure.nextHdmi'),
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
			$findChannelPropertyQuery = new Queries\Entities\FindChannelProperties();
			$findChannelPropertyQuery->forChannel($channel);
			$findChannelPropertyQuery->byIdentifier(Types\ChannelPropertyIdentifier::APPLICATION);

			$appsProperty = $this->channelsPropertiesRepository->findOneBy($findChannelPropertyQuery);
		}

		$findDevicePropertyQuery = new Queries\Entities\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::APP_ID);

		$appIdProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

		$findDevicePropertyQuery = new Queries\Entities\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::ENCRYPTION_KEY);

		$encryptionKeyProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

		$findDevicePropertyQuery = new Queries\Entities\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::MODEL);

		$hardwareModelProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

		$findDevicePropertyQuery = new Queries\Entities\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::MANUFACTURER);

		$hardwareManufacturerProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

		$findDevicePropertyQuery = new Queries\Entities\FindDeviceProperties();
		$findDevicePropertyQuery->forDevice($device);
		$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::MAC_ADDRESS);

		$macAddressProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

		if ($macAddressProperty === null) {
			$question = new Console\Question\ConfirmationQuestion(
				(string) $this->translator->translate('//viera-connector.cmd.install.questions.configure.macAddress'),
				false,
			);

			$changeMacAddress = (bool) $io->askQuestion($question);

		} else {
			$question = new Console\Question\ConfirmationQuestion(
				(string) $this->translator->translate('//viera-connector.cmd.install.questions.change.macAddress'),
				false,
			);

			$changeMacAddress = (bool) $io->askQuestion($question);
		}

		$macAddress = $device->getMacAddress();

		if ($changeMacAddress) {
			$macAddress = $this->askDeviceMacAddress($io, $device);
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
				(string) $this->translator->translate('//viera-connector.cmd.install.messages.device.connectionFailed'),
			);

			$this->logger->error(
				'Creating api client failed',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'install-cmd',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
				],
			);

			return;
		}

		try {
			$isOnline = $televisionApi->livenessProbe(1.5, true);
		} catch (Exceptions\TelevisionApiError $ex) {
			$io->error(
				(string) $this->translator->translate('//viera-connector.cmd.install.messages.device.connectionFailed'),
			);

			$this->logger->error(
				'Checking TV status failed',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'install-cmd',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
				],
			);

			return;
		}

		if ($isOnline === false) {
			$io->error(
				(string) $this->translator->translate(
					'//viera-connector.cmd.install.messages.device.unreachable',
					['address' => $ipAddress],
				),
			);

			return;
		}

		try {
			$specs = $televisionApi->getSpecs(false);
		} catch (Exceptions\TelevisionApiError $ex) {
			$io->error(
				(string) $this->translator->translate(
					'//viera-connector.cmd.install.messages.device.loadingSpecsFailed',
				),
			);

			$this->logger->error(
				'Loading TV specification failed',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'install-cmd',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
				],
			);

			return;
		} catch (Exceptions\TelevisionApiCall $ex) {
			$io->error(
				(string) $this->translator->translate(
					'//viera-connector.cmd.install.messages.device.loadingSpecsFailed',
				),
			);

			$this->logger->error(
				'Loading TV specification failed',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'install-cmd',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
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

		try {
			$isTurnedOn = $televisionApi->isTurnedOn(true);
		} catch (Exceptions\TelevisionApiError $ex) {
			$io->error(
				(string) $this->translator->translate(
					'//viera-connector.cmd.install.messages.device.checkStatusFailed',
				),
			);

			$this->logger->error(
				'Checking screen status failed',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'install-cmd',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
				],
			);

			return;
		}

		if (!$specs->isRequiresEncryption()) {
			$authorization = false;
		} else {
			if (
				!$device->isEncrypted()
				|| $device->getAppId() === null
				|| $device->getEncryptionKey() === null
			) {
				$io->warning(
					(string) $this->translator->translate('//viera-connector.cmd.install.messages.device.needPairing'),
				);

				if ($isTurnedOn === false) {
					$io->warning(
						(string) $this->translator->translate('//viera-connector.cmd.install.messages.device.offline'),
					);

					$question = new Console\Question\ConfirmationQuestion(
						(string) $this->translator->translate('//viera-connector.cmd.base.questions.continue'),
						false,
					);

					$continue = (bool) $io->askQuestion($question);

					if (!$continue) {
						return;
					}
				}

				try {
					$this->challengeKey = $televisionApi
						->requestPinCode($connector->getName() ?? $connector->getIdentifier(), false)
						->getChallengeKey();

					$authorization = $this->askDevicePinCode($io, $connector, $televisionApi);

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
						(string) $this->translator->translate(
							'//viera-connector.cmd.install.messages.device.connectionFailed',
						),
					);

					$this->logger->error(
						'Pin code pairing failed',
						[
							'source' => MetadataTypes\Sources\Connector::VIERA->value,
							'type' => 'install-cmd',
							'exception' => ApplicationHelpers\Logger::buildException($ex),
						],
					);

					return;
				}
			}
		}

		try {
			$apps = $isTurnedOn ? $televisionApi->getApps(false) : null;
		} catch (Exceptions\TelevisionApiError | Exceptions\TelevisionApiCall | Exceptions\InvalidState $ex) {
			$io->error(
				(string) $this->translator->translate(
					'//viera-connector.cmd.install.messages.device.loadingAppsFailed',
				),
			);

			$this->logger->error(
				'Loading apps failed',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'install-cmd',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
				],
			);

			return;
		}

		try {
			// Start transaction connection to the database
			$this->databaseHelper->beginTransaction();

			$device = $this->devicesManager->update($device, Utils\ArrayHash::from([
				'name' => $name,
			]));
			assert($device instanceof Entities\Devices\Device);

			if ($ipAddressProperty === null) {
				$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::IP_ADDRESS->value,
					'name' => DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::IP_ADDRESS->value),
					'dataType' => MetadataTypes\DataType::STRING,
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
					'identifier' => Types\DevicePropertyIdentifier::PORT->value,
					'name' => DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::PORT->value),
					'dataType' => MetadataTypes\DataType::UINT,
					'value' => $port,
					'device' => $device,
				]));
			} else {
				$this->devicesPropertiesManager->update($portProperty, Utils\ArrayHash::from([
					'value' => $port,
				]));
			}

			if ($appIdProperty === null && $authorization !== null && $authorization !== false) {
				$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::APP_ID->value,
					'name' => DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::APP_ID->value),
					'dataType' => MetadataTypes\DataType::STRING,
					'value' => $authorization->getAppId(),
					'device' => $device,
				]));
			} elseif ($appIdProperty !== null && $authorization !== null && $authorization !== false) {
				$this->devicesPropertiesManager->update($appIdProperty, Utils\ArrayHash::from([
					'value' => $authorization->getAppId(),
				]));
			} elseif ($appIdProperty !== null && $authorization === false) {
				$this->devicesPropertiesManager->delete($appIdProperty);
			}

			if ($encryptionKeyProperty === null && $authorization !== null && $authorization !== false) {
				$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::ENCRYPTION_KEY->value,
					'name' => DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::ENCRYPTION_KEY->value),
					'dataType' => MetadataTypes\DataType::STRING,
					'value' => $authorization->getEncryptionKey(),
					'device' => $device,
				]));
			} elseif ($encryptionKeyProperty !== null && $authorization !== null && $authorization !== false) {
				$this->devicesPropertiesManager->update($encryptionKeyProperty, Utils\ArrayHash::from([
					'value' => $authorization->getEncryptionKey(),
				]));
			} elseif ($encryptionKeyProperty !== null && $authorization === false) {
				$this->devicesPropertiesManager->delete($encryptionKeyProperty);
			}

			if ($hardwareModelProperty === null) {
				$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
					'entity' => DevicesEntities\Devices\Properties\Variable::class,
					'identifier' => Types\DevicePropertyIdentifier::MODEL->value,
					'name' => DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::MODEL->value),
					'dataType' => MetadataTypes\DataType::STRING,
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
					'identifier' => Types\DevicePropertyIdentifier::MODEL->value,
					'name' => DevicesUtilities\Name::createName(
						Types\DevicePropertyIdentifier::MANUFACTURER->value,
					),
					'dataType' => MetadataTypes\DataType::STRING,
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
					'identifier' => Types\DevicePropertyIdentifier::MAC_ADDRESS->value,
					'name' => DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::MAC_ADDRESS->value),
					'dataType' => MetadataTypes\DataType::STRING,
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
					'entity' => Entities\Channels\Channel::class,
					'device' => $device,
					'identifier' => Types\ChannelType::TELEVISION,
				]));
			}

			if ($hdmi !== null) {
				if ($hdmiProperty === null) {
					$this->channelsPropertiesManager->create(Utils\ArrayHash::from([
						'entity' => DevicesEntities\Channels\Properties\Dynamic::class,
						'identifier' => Types\ChannelPropertyIdentifier::HDMI->value,
						'name' => DevicesUtilities\Name::createName(Types\ChannelPropertyIdentifier::HDMI->value),
						'dataType' => MetadataTypes\DataType::ENUM,
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
						'identifier' => Types\ChannelPropertyIdentifier::APPLICATION->value,
						'name' => DevicesUtilities\Name::createName(
							Types\ChannelPropertyIdentifier::APPLICATION->value,
						),
						'dataType' => MetadataTypes\DataType::ENUM,
						'settable' => true,
						'queryable' => false,
						'format' => $apps->getApps() !== [] ? array_map(
							static fn (API\Messages\Response\Application $application): array => [
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
							static fn (API\Messages\Response\Application $application): array => [
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
			$this->databaseHelper->commitTransaction();

			$io->success(
				(string) $this->translator->translate(
					'//viera-connector.cmd.install.messages.update.device.success',
					['name' => $device->getName() ?? $device->getIdentifier()],
				),
			);
		} catch (Throwable $ex) {
			// Log caught exception
			$this->logger->error(
				'An unhandled error occurred',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'install-cmd',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
				],
			);

			$io->error(
				(string) $this->translator->translate('//viera-connector.cmd.install.messages.update.device.error'),
			);

			return;
		} finally {
			$this->databaseHelper->clear();
		}
	}

	/**
	 * @throws ApplicationExceptions\InvalidState
	 * @throws DevicesExceptions\InvalidState
	 */
	private function deleteDevice(Style\SymfonyStyle $io, Entities\Connectors\Connector $connector): void
	{
		$device = $this->askWhichDevice($io, $connector);

		if ($device === null) {
			$io->warning((string) $this->translator->translate('//viera-connector.cmd.install.messages.noDevices'));

			return;
		}

		$io->warning(
			(string) $this->translator->translate(
				'//viera-connector.cmd.install.messages.remove.device.confirm',
				['name' => $device->getName() ?? $device->getIdentifier()],
			),
		);

		$question = new Console\Question\ConfirmationQuestion(
			(string) $this->translator->translate('//viera-connector.cmd.base.questions.continue'),
			false,
		);

		$continue = (bool) $io->askQuestion($question);

		if (!$continue) {
			return;
		}

		try {
			// Start transaction connection to the database
			$this->databaseHelper->beginTransaction();

			$this->devicesManager->delete($device);

			// Commit all changes into database
			$this->databaseHelper->commitTransaction();

			$io->success(
				(string) $this->translator->translate(
					'//viera-connector.cmd.install.messages.remove.device.success',
					['name' => $device->getName() ?? $device->getIdentifier()],
				),
			);
		} catch (Throwable $ex) {
			// Log caught exception
			$this->logger->error(
				'An unhandled error occurred',
				[
					'source' => MetadataTypes\Sources\Connector::VIERA->value,
					'type' => 'install-cmd',
					'exception' => ApplicationHelpers\Logger::buildException($ex),
				],
			);

			$io->error(
				(string) $this->translator->translate('//viera-connector.cmd.install.messages.remove.device.error'),
			);
		} finally {
			$this->databaseHelper->clear();
		}
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function listDevices(Style\SymfonyStyle $io, Entities\Connectors\Connector $connector): void
	{
		$findDevicesQuery = new Queries\Entities\FindDevices();
		$findDevicesQuery->forConnector($connector);

		$devices = $this->devicesRepository->findAllBy($findDevicesQuery, Entities\Devices\Device::class);
		usort(
			$devices,
			static fn (Entities\Devices\Device $a, Entities\Devices\Device $b): int => (
				($a->getName() ?? $a->getIdentifier()) <=> ($b->getName() ?? $b->getIdentifier())
			),
		);

		$table = new Console\Helper\Table($io);
		$table->setHeaders([
			'#',
			(string) $this->translator->translate('//viera-connector.cmd.install.data.name'),
			(string) $this->translator->translate('//viera-connector.cmd.install.data.model'),
			(string) $this->translator->translate('//viera-connector.cmd.install.data.ipAddress'),
			(string) $this->translator->translate('//viera-connector.cmd.install.data.encryption'),
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

	/**
	 * @throws ApplicationExceptions\InvalidState
	 * @throws Console\Exception\ExceptionInterface
	 * @throws DevicesExceptions\InvalidState
	 * @throws DoctrineCrudExceptions\InvalidArgumentException
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\InvalidState
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function discoverDevices(Style\SymfonyStyle $io, Entities\Connectors\Connector $connector): void
	{
		if ($this->output === null) {
			throw new Exceptions\InvalidState('Something went wrong, console output is not configured');
		}

		$executedTime = $this->dateTimeFactory->getNow();

		$symfonyApp = $this->getApplication();

		if ($symfonyApp === null) {
			throw new Exceptions\InvalidState('Something went wrong, console app is not configured');
		}

		$serviceCmd = $symfonyApp->find(DevicesCommands\Connector::NAME);

		$io->info((string) $this->translator->translate('//viera-connector.cmd.install.messages.discover.starting'));

		$result = $serviceCmd->run(new Input\ArrayInput([
			'--connector' => $connector->getId()->toString(),
			'--mode' => DevicesTypes\ConnectorMode::DISCOVER->value,
			'--no-interaction' => true,
			'--quiet' => true,
		]), $this->output);

		$this->databaseHelper->clear();

		$io->newLine(2);

		$io->info((string) $this->translator->translate('//viera-connector.cmd.install.messages.discover.stopping'));

		if ($result !== Console\Command\Command::SUCCESS) {
			$io->error((string) $this->translator->translate('//viera-connector.cmd.install.messages.discover.error'));

			return;
		}

		$table = new Console\Helper\Table($io);
		$table->setHeaders([
			'#',
			(string) $this->translator->translate('//viera-connector.cmd.install.data.id'),
			(string) $this->translator->translate('//viera-connector.cmd.install.data.name'),
			(string) $this->translator->translate('//viera-connector.cmd.install.data.model'),
			(string) $this->translator->translate('//viera-connector.cmd.install.data.ipAddress'),
			(string) $this->translator->translate('//viera-connector.cmd.install.data.encryption'),
		]);

		$foundDevices = 0;
		$encryptedDevices = [];

		$findDevicesQuery = new Queries\Entities\FindDevices();
		$findDevicesQuery->forConnector($connector);

		$devices = $this->devicesRepository->findAllBy($findDevicesQuery, Entities\Devices\Device::class);

		foreach ($devices as $device) {
			$createdAt = $device->getCreatedAt();

			if (
				$createdAt !== null
				&& $createdAt->getTimestamp() > $executedTime->getTimestamp()
			) {
				$foundDevices++;

				$isEncrypted = $device->isEncrypted();

				$table->addRow([
					$foundDevices,
					$device->getId()->toString(),
					$device->getName() ?? $device->getIdentifier(),
					$device->getModel() ?? 'N/A',
					$device->getIpAddress() ?? 'N/A',
					$isEncrypted ? 'yes' : 'no',
				]);

				if (
					$isEncrypted
					&& (
						$device->getAppId() === null
						|| $device->getEncryptionKey() === null
					)
				) {
					$encryptedDevices[] = $device;
				}
			}
		}

		if ($foundDevices > 0) {
			$io->info(sprintf(
				(string) $this->translator->translate('//viera-connector.cmd.install.messages.foundDevices'),
				$foundDevices,
			));

			$table->render();

			$io->newLine();

		} else {
			$io->info((string) $this->translator->translate('//viera-connector.cmd.install.messages.noDevicesFound'));
		}

		if ($encryptedDevices !== []) {
			$this->processEncryptedDevices($io, $connector, $encryptedDevices);
		}

		$io->success((string) $this->translator->translate('//viera-connector.cmd.install.messages.discover.success'));
	}

	/**
	 * @throws ApplicationExceptions\InvalidState
	 * @throws Console\Exception\ExceptionInterface
	 * @throws DBAL\Exception
	 * @throws DevicesExceptions\InvalidState
	 * @throws DoctrineCrudExceptions\InvalidArgumentException
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\InvalidState
	 * @throws Exceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws RuntimeException
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function askInstallAction(Style\SymfonyStyle $io): void
	{
		$question = new Console\Question\ChoiceQuestion(
			(string) $this->translator->translate('//viera-connector.cmd.base.questions.whatToDo'),
			[
				0 => (string) $this->translator->translate('//viera-connector.cmd.install.actions.create.connector'),
				1 => (string) $this->translator->translate('//viera-connector.cmd.install.actions.update.connector'),
				2 => (string) $this->translator->translate('//viera-connector.cmd.install.actions.remove.connector'),
				3 => (string) $this->translator->translate('//viera-connector.cmd.install.actions.manage.connector'),
				4 => (string) $this->translator->translate('//viera-connector.cmd.install.actions.list.connectors'),
				5 => (string) $this->translator->translate('//viera-connector.cmd.install.actions.nothing'),
			],
			5,
		);

		$question->setErrorMessage(
			(string) $this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
		);

		$whatToDo = $io->askQuestion($question);

		if (
			$whatToDo === (string) $this->translator->translate(
				'//viera-connector.cmd.install.actions.create.connector',
			)
			|| $whatToDo === '0'
		) {
			$this->createConnector($io);

			$this->askInstallAction($io);

		} elseif (
			$whatToDo === (string) $this->translator->translate(
				'//viera-connector.cmd.install.actions.update.connector',
			)
			|| $whatToDo === '1'
		) {
			$this->editConnector($io);

			$this->askInstallAction($io);

		} elseif (
			$whatToDo === (string) $this->translator->translate(
				'//viera-connector.cmd.install.actions.remove.connector',
			)
			|| $whatToDo === '2'
		) {
			$this->deleteConnector($io);

			$this->askInstallAction($io);

		} elseif (
			$whatToDo === (string) $this->translator->translate(
				'//viera-connector.cmd.install.actions.manage.connector',
			)
			|| $whatToDo === '3'
		) {
			$this->manageConnector($io);

			$this->askInstallAction($io);

		} elseif (
			$whatToDo === (string) $this->translator->translate(
				'//viera-connector.cmd.install.actions.list.connectors',
			)
			|| $whatToDo === '4'
		) {
			$this->listConnectors($io);

			$this->askInstallAction($io);
		}
	}

	/**
	 * @throws ApplicationExceptions\InvalidState
	 * @throws Console\Exception\ExceptionInterface
	 * @throws DBAL\Exception
	 * @throws DevicesExceptions\InvalidState
	 * @throws DoctrineCrudExceptions\InvalidArgumentException
	 * @throws Exceptions\InvalidArgument
	 * @throws Exceptions\InvalidState
	 * @throws Exceptions\Runtime
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws RuntimeException
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function askManageConnectorAction(
		Style\SymfonyStyle $io,
		Entities\Connectors\Connector $connector,
	): void
	{
		$question = new Console\Question\ChoiceQuestion(
			(string) $this->translator->translate('//viera-connector.cmd.base.questions.whatToDo'),
			[
				0 => (string) $this->translator->translate('//viera-connector.cmd.install.actions.create.device'),
				1 => (string) $this->translator->translate('//viera-connector.cmd.install.actions.update.device'),
				2 => (string) $this->translator->translate('//viera-connector.cmd.install.actions.remove.device'),
				3 => (string) $this->translator->translate('//viera-connector.cmd.install.actions.list.devices'),
				4 => (string) $this->translator->translate('//viera-connector.cmd.install.actions.discover.devices'),
				5 => (string) $this->translator->translate('//viera-connector.cmd.install.actions.nothing'),
			],
			5,
		);

		$question->setErrorMessage(
			(string) $this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
		);

		$whatToDo = $io->askQuestion($question);

		if (
			$whatToDo === (string) $this->translator->translate(
				'//viera-connector.cmd.install.actions.create.device',
			)
			|| $whatToDo === '0'
		) {
			$this->createDevice($io, $connector);

			$this->askManageConnectorAction($io, $connector);

		} elseif (
			$whatToDo === (string) $this->translator->translate(
				'//viera-connector.cmd.install.actions.update.device',
			)
			|| $whatToDo === '1'
		) {
			$this->editDevice($io, $connector);

			$this->askManageConnectorAction($io, $connector);

		} elseif (
			$whatToDo === (string) $this->translator->translate(
				'//viera-connector.cmd.install.actions.remove.device',
			)
			|| $whatToDo === '2'
		) {
			$this->deleteDevice($io, $connector);

			$this->askManageConnectorAction($io, $connector);

		} elseif (
			$whatToDo === (string) $this->translator->translate(
				'//viera-connector.cmd.install.actions.list.devices',
			)
			|| $whatToDo === '3'
		) {
			$this->listDevices($io, $connector);

			$this->askManageConnectorAction($io, $connector);

		} elseif (
			$whatToDo === (string) $this->translator->translate(
				'//viera-connector.cmd.install.actions.discover.devices',
			)
			|| $whatToDo === '4'
		) {
			$this->discoverDevices($io, $connector);

			$this->askManageConnectorAction($io, $connector);
		}
	}

	private function askConnectorName(
		Style\SymfonyStyle $io,
		Entities\Connectors\Connector|null $connector = null,
	): string|null
	{
		$question = new Console\Question\Question(
			(string) $this->translator->translate('//viera-connector.cmd.install.questions.provide.connector.name'),
			$connector?->getName(),
		);

		$name = $io->askQuestion($question);

		return strval($name) === '' ? null : strval($name);
	}

	private function askDeviceName(Style\SymfonyStyle $io, Entities\Devices\Device|null $device = null): string|null
	{
		$question = new Console\Question\Question(
			(string) $this->translator->translate('//viera-connector.cmd.install.questions.provide.device.name'),
			$device?->getName(),
		);

		$name = $io->askQuestion($question);

		return strval($name) === '' ? null : strval($name);
	}

	/**
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function askDeviceIpAddress(Style\SymfonyStyle $io, Entities\Devices\Device|null $device = null): string
	{
		$question = new Console\Question\Question(
			(string) $this->translator->translate('//viera-connector.cmd.install.questions.provide.device.ipAddress'),
			$device?->getIpAddress(),
		);
		$question->setValidator(function (string|null $answer): string {
			if ($answer !== null && preg_match(self::MATCH_IP_ADDRESS, $answer) === 1) {
				return $answer;
			}

			throw new Exceptions\Runtime(
				sprintf(
					(string) $this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
					$answer,
				),
			);
		});

		$ipAddress = $io->askQuestion($question);

		return strval($ipAddress);
	}

	/**
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function askDevicePort(Style\SymfonyStyle $io, Entities\Devices\Device|null $device = null): int
	{
		$question = new Console\Question\Question(
			(string) $this->translator->translate('//viera-connector.cmd.install.questions.provide.device.port'),
			$device?->getPort(),
		);
		$question->setValidator(function (string|null $answer): int {
			if ($answer !== null && strval(intval($answer)) === $answer) {
				return intval($answer);
			}

			throw new Exceptions\Runtime(
				sprintf(
					(string) $this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
					$answer,
				),
			);
		});

		$port = $io->askQuestion($question);

		return intval($port);
	}

	/**
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function askDeviceMacAddress(Style\SymfonyStyle $io, Entities\Devices\Device|null $device = null): string
	{
		$question = new Console\Question\Question(
			(string) $this->translator->translate('//viera-connector.cmd.install.questions.provide.device.macAddress'),
			$device?->getMacAddress(),
		);
		$question->setValidator(function (string|null $answer): string {
			if ($answer !== null && preg_match(self::MATCH_MAC_ADDRESS, $answer) === 1) {
				return $answer;
			}

			throw new Exceptions\Runtime(
				sprintf(
					(string) $this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
					$answer,
				),
			);
		});

		$macAddress = $io->askQuestion($question);

		return strval($macAddress);
	}

	private function askDevicePinCode(
		Style\SymfonyStyle $io,
		Entities\Connectors\Connector $connector,
		API\TelevisionApi $televisionApi,
	): API\Messages\Response\AuthorizePinCode
	{
		$question = new Console\Question\Question(
			(string) $this->translator->translate('//viera-connector.cmd.install.questions.provide.device.pinCode'),
		);
		$question->setValidator(
			function (string|null $answer) use ($connector, $televisionApi): API\Messages\Response\AuthorizePinCode {
				if ($answer !== null && $answer !== '') {
					try {
						return $televisionApi->authorizePinCode($answer, strval($this->challengeKey), false);
					} catch (Exceptions\TelevisionApiCall) {
						$this->challengeKey = $televisionApi
							->requestPinCode($connector->getName() ?? $connector->getIdentifier(), false)
							->getChallengeKey();

						throw new Exceptions\Runtime(
							sprintf(
								(string) $this->translator->translate(
									'//viera-connector.cmd.base.messages.answerNotValid',
								),
								$answer,
							),
						);
					}
				}

				throw new Exceptions\Runtime(
					sprintf(
						(string) $this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
						$answer,
					),
				);
			},
		);

		$authorization = $io->askQuestion($question);
		assert($authorization instanceof API\Messages\Response\AuthorizePinCode);

		return $authorization;
	}

	private function askDeviceHdmiName(Style\SymfonyStyle $io): string
	{
		$question = new Console\Question\Question(
			(string) $this->translator->translate('//viera-connector.cmd.install.questions.provide.device.hdmiName'),
		);
		$question->setValidator(function (string|null $answer): string {
			if ($answer !== null) {
				return $answer;
			}

			throw new Exceptions\Runtime(
				sprintf(
					(string) $this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
					$answer,
				),
			);
		});

		$ipAddress = $io->askQuestion($question);

		return strval($ipAddress);
	}

	private function askDeviceHdmiIndex(Style\SymfonyStyle $io, string $name): int
	{
		$question = new Console\Question\Question(
			(string) $this->translator->translate(
				'//viera-connector.cmd.install.questions.provide.device.hdmiNumber',
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
				sprintf(
					(string) $this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
					$answer,
				),
			);
		});

		$ipAddress = $io->askQuestion($question);

		return intval($ipAddress);
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 */
	private function askWhichConnector(Style\SymfonyStyle $io): Entities\Connectors\Connector|null
	{
		$connectors = [];

		$findConnectorsQuery = new Queries\Entities\FindConnectors();

		$systemConnectors = $this->connectorsRepository->findAllBy(
			$findConnectorsQuery,
			Entities\Connectors\Connector::class,
		);
		usort(
			$systemConnectors,
			static fn (Entities\Connectors\Connector $a, Entities\Connectors\Connector $b): int => (
				($a->getName() ?? $a->getIdentifier()) <=> ($b->getName() ?? $b->getIdentifier())
			),
		);

		foreach ($systemConnectors as $connector) {
			$connectors[$connector->getIdentifier()] = $connector->getName() ?? $connector->getIdentifier();
		}

		if (count($connectors) === 0) {
			return null;
		}

		$question = new Console\Question\ChoiceQuestion(
			(string) $this->translator->translate('//viera-connector.cmd.install.questions.select.item.connector'),
			array_values($connectors),
			count($connectors) === 1 ? 0 : null,
		);

		$question->setErrorMessage(
			(string) $this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
		);
		$question->setValidator(function (string|int|null $answer) use ($connectors): Entities\Connectors\Connector {
			if ($answer === null) {
				throw new Exceptions\Runtime(
					sprintf(
						(string) $this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
						$answer,
					),
				);
			}

			if (array_key_exists($answer, array_values($connectors))) {
				$answer = array_values($connectors)[$answer];
			}

			$identifier = array_search($answer, $connectors, true);

			if ($identifier !== false) {
				$findConnectorQuery = new Queries\Entities\FindConnectors();
				$findConnectorQuery->byIdentifier($identifier);

				$connector = $this->connectorsRepository->findOneBy(
					$findConnectorQuery,
					Entities\Connectors\Connector::class,
				);

				if ($connector !== null) {
					return $connector;
				}
			}

			throw new Exceptions\Runtime(
				sprintf(
					(string) $this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
					$answer,
				),
			);
		});

		$connector = $io->askQuestion($question);
		assert($connector instanceof Entities\Connectors\Connector);

		return $connector;
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 */
	private function askWhichDevice(
		Style\SymfonyStyle $io,
		Entities\Connectors\Connector $connector,
	): Entities\Devices\Device|null
	{
		$devices = [];

		$findDevicesQuery = new Queries\Entities\FindDevices();
		$findDevicesQuery->forConnector($connector);

		$connectorDevices = $this->devicesRepository->findAllBy(
			$findDevicesQuery,
			Entities\Devices\Device::class,
		);
		usort(
			$connectorDevices,
			static fn (Entities\Devices\Device $a, Entities\Devices\Device $b): int => (
				($a->getName() ?? $a->getIdentifier()) <=> ($b->getName() ?? $b->getIdentifier())
			),
		);

		foreach ($connectorDevices as $device) {
			$devices[$device->getIdentifier()] = $device->getName() ?? $device->getIdentifier();
		}

		if (count($devices) === 0) {
			return null;
		}

		$question = new Console\Question\ChoiceQuestion(
			(string) $this->translator->translate('//viera-connector.cmd.install.questions.select.item.device'),
			array_values($devices),
			count($devices) === 1 ? 0 : null,
		);

		$question->setErrorMessage(
			(string) $this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
		);
		$question->setValidator(
			function (string|int|null $answer) use ($connector, $devices): Entities\Devices\Device {
				if ($answer === null) {
					throw new Exceptions\Runtime(
						sprintf(
							(string) $this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
							$answer,
						),
					);
				}

				if (array_key_exists($answer, array_values($devices))) {
					$answer = array_values($devices)[$answer];
				}

				$identifier = array_search($answer, $devices, true);

				if ($identifier !== false) {
					$findDeviceQuery = new Queries\Entities\FindDevices();
					$findDeviceQuery->byIdentifier($identifier);
					$findDeviceQuery->forConnector($connector);

					$device = $this->devicesRepository->findOneBy(
						$findDeviceQuery,
						Entities\Devices\Device::class,
					);

					if ($device !== null) {
						return $device;
					}
				}

				throw new Exceptions\Runtime(
					sprintf(
						(string) $this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
						$answer,
					),
				);
			},
		);

		$device = $io->askQuestion($question);
		assert($device instanceof Entities\Devices\Device);

		return $device;
	}

	/**
	 * @param array<Entities\Devices\Device> $encryptedDevices
	 *
	 * @throws ApplicationExceptions\InvalidState
	 * @throws DoctrineCrudExceptions\InvalidArgumentException
	 * @throws Exceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function processEncryptedDevices(
		Style\SymfonyStyle $io,
		Entities\Connectors\Connector $connector,
		array $encryptedDevices,
	): void
	{
		$io->info(
			(string) $this->translator->translate('//viera-connector.cmd.install.messages.foundEncryptedDevices'),
		);

		$question = new Console\Question\ConfirmationQuestion(
			(string) $this->translator->translate('//viera-connector.cmd.install.questions.pairDevice'),
			false,
		);

		$continue = (bool) $io->askQuestion($question);

		if ($continue) {
			foreach ($encryptedDevices as $device) {
				if ($device->getIpAddress() === null) {
					$io->error(
						(string) $this->translator->translate(
							'//viera-connector.cmd.install.messages.missingIpAddress',
							['device' => $device->getName()],
						),
					);

					continue;
				}

				$io->info(
					(string) $this->translator->translate(
						'//viera-connector.cmd.install.messages.pairing.started',
						['device' => $device->getName()],
					),
				);

				try {
					$televisionApi = $this->televisionApiFactory->create(
						$device->getIdentifier(),
						$device->getIpAddress(),
						$device->getPort(),
					);
					$televisionApi->connect();
				} catch (Exceptions\TelevisionApiCall | Exceptions\TelevisionApiError | Exceptions\InvalidState $ex) {
					$io->error(
						(string) $this->translator->translate(
							'//viera-connector.cmd.install.messages.device.connectionFailed',
							['device' => $device->getName()],
						),
					);

					$this->logger->error(
						'Creating api client failed',
						[
							'source' => MetadataTypes\Sources\Connector::VIERA->value,
							'type' => 'install-cmd',
							'exception' => ApplicationHelpers\Logger::buildException($ex),
						],
					);

					continue;
				}

				try {
					$isTurnedOn = $televisionApi->isTurnedOn(true);
				} catch (Throwable $ex) {
					$io->error(
						(string) $this->translator->translate(
							'//viera-connector.cmd.install.messages.device.pairingFailed',
							['device' => $device->getName()],
						),
					);

					$this->logger->error(
						'Checking screen status failed',
						[
							'source' => MetadataTypes\Sources\Connector::VIERA->value,
							'type' => 'install-cmd',
							'exception' => ApplicationHelpers\Logger::buildException($ex),
						],
					);

					continue;
				}

				if ($isTurnedOn === false) {
					$io->warning(
						(string) $this->translator->translate(
							'//viera-connector.cmd.install.messages.device.offline',
							['device' => $device->getName()],
						),
					);

					$question = new Console\Question\ConfirmationQuestion(
						(string) $this->translator->translate('//viera-connector.cmd.base.questions.continue'),
						false,
					);

					$continue = (bool) $io->askQuestion($question);

					if (!$continue) {
						continue;
					}
				}

				try {
					$this->challengeKey = $televisionApi
						->requestPinCode($connector->getName() ?? $connector->getIdentifier(), false)
						->getChallengeKey();

					$authorization = $this->askDevicePinCode($io, $connector, $televisionApi);

					$televisionApi = $this->televisionApiFactory->create(
						$device->getIdentifier(),
						$device->getIpAddress(),
						$device->getPort(),
						$authorization->getAppId(),
						$authorization->getEncryptionKey(),
					);
					$televisionApi->connect();
				} catch (Exceptions\TelevisionApiCall | Exceptions\TelevisionApiError | Exceptions\InvalidState $ex) {
					$io->error(
						(string) $this->translator->translate(
							'//viera-connector.cmd.install.messages.device.connectionFailed',
						),
					);

					$this->logger->error(
						'Pin code pairing failed',
						[
							'source' => MetadataTypes\Sources\Connector::VIERA->value,
							'type' => 'install-cmd',
							'exception' => ApplicationHelpers\Logger::buildException($ex),
						],
					);

					return;
				}

				$findDevicePropertyQuery = new Queries\Entities\FindDeviceProperties();
				$findDevicePropertyQuery->byDeviceId($device->getId());
				$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::APP_ID);

				$appIdProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

				if ($appIdProperty === null) {
					$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
						'entity' => DevicesEntities\Devices\Properties\Variable::class,
						'device' => $device,
						'identifier' => Types\DevicePropertyIdentifier::APP_ID->value,
						'name' => DevicesUtilities\Name::createName(Types\DevicePropertyIdentifier::APP_ID->value),
						'dataType' => MetadataTypes\DataType::STRING,
						'value' => $authorization->getAppId(),
						'format' => null,
					]));
				} else {
					$this->devicesPropertiesManager->update($appIdProperty, Utils\ArrayHash::from([
						'value' => $authorization->getAppId(),
					]));
				}

				$findDevicePropertyQuery = new Queries\Entities\FindDeviceProperties();
				$findDevicePropertyQuery->byDeviceId($device->getId());
				$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::ENCRYPTION_KEY);

				$encryptionKeyProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

				if ($encryptionKeyProperty === null) {
					$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
						'entity' => DevicesEntities\Devices\Properties\Variable::class,
						'device' => $device,
						'identifier' => Types\DevicePropertyIdentifier::ENCRYPTION_KEY->value,
						'name' => DevicesUtilities\Name::createName(
							Types\DevicePropertyIdentifier::ENCRYPTION_KEY->value,
						),
						'dataType' => MetadataTypes\DataType::STRING,
						'value' => $authorization->getEncryptionKey(),
						'format' => null,
					]));
				} else {
					$this->devicesPropertiesManager->update($encryptionKeyProperty, Utils\ArrayHash::from([
						'value' => $authorization->getEncryptionKey(),
					]));
				}

				$io->success(
					(string) $this->translator->translate(
						'//viera-connector.cmd.install.messages.pairing.finished',
						['device' => $device->getName()],
					),
				);
			}
		}
	}

}
