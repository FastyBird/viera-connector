<?php declare(strict_types = 1);

/**
 * Discovery.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Commands
 * @since          1.0.0
 *
 * @date           04.07.08.23
 */

namespace FastyBird\Connector\Viera\Commands;

use DateTimeInterface;
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
use FastyBird\Module\Devices\Commands as DevicesCommands;
use FastyBird\Module\Devices\Entities as DevicesEntities;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use FastyBird\Module\Devices\Models as DevicesModels;
use FastyBird\Module\Devices\Queries as DevicesQueries;
use IPub\DoctrineCrud\Exceptions as DoctrineCrudExceptions;
use Nette\Localization;
use Nette\Utils;
use Ramsey\Uuid;
use Symfony\Component\Console;
use Symfony\Component\Console\Input;
use Symfony\Component\Console\Output;
use Symfony\Component\Console\Style;
use Throwable;
use function array_key_exists;
use function array_key_first;
use function array_search;
use function array_values;
use function assert;
use function count;
use function is_string;
use function sprintf;
use function strval;
use function usort;

/**
 * Connector devices discovery command
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Commands
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class Discovery extends Console\Command\Command
{

	public const NAME = 'fb:viera-connector:discover';

	private string|null $challengeKey = null;

	private DateTimeInterface|null $executedTime = null;

	public function __construct(
		private readonly Api\TelevisionApiFactory $televisionApiFactory,
		private readonly Viera\Logger $logger,
		private readonly DevicesModels\Connectors\ConnectorsRepository $connectorsRepository,
		private readonly DevicesModels\Devices\DevicesRepository $devicesRepository,
		private readonly DevicesModels\Devices\Properties\PropertiesRepository $devicesPropertiesRepository,
		private readonly DevicesModels\Devices\Properties\PropertiesManager $devicesPropertiesManager,
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
			->setDescription('Viera connector televisions discovery')
			->setDefinition(
				new Input\InputDefinition([
					new Input\InputOption(
						'connector',
						'c',
						Input\InputOption::VALUE_OPTIONAL,
						'Connector ID or identifier',
						true,
					),
				]),
			);
	}

	/**
	 * @throws Console\Exception\ExceptionInterface
	 * @throws Console\Exception\InvalidArgumentException
	 * @throws DevicesExceptions\InvalidState
	 * @throws DoctrineCrudExceptions\InvalidArgumentException
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	protected function execute(Input\InputInterface $input, Output\OutputInterface $output): int
	{
		$symfonyApp = $this->getApplication();

		if ($symfonyApp === null) {
			return Console\Command\Command::FAILURE;
		}

		$io = new Style\SymfonyStyle($input, $output);

		$io->title($this->translator->translate('//viera-connector.cmd.discovery.title'));

		$io->note($this->translator->translate('//viera-connector.cmd.discovery.subtitle'));

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

		if (
			$input->hasOption('connector')
			&& is_string($input->getOption('connector'))
			&& $input->getOption('connector') !== ''
		) {
			$connectorId = $input->getOption('connector');

			$findConnectorQuery = new Queries\FindConnectors();

			if (Uuid\Uuid::isValid($connectorId)) {
				$findConnectorQuery->byId(Uuid\Uuid::fromString($connectorId));
			} else {
				$findConnectorQuery->byIdentifier($connectorId);
			}

			$connector = $this->connectorsRepository->findOneBy($findConnectorQuery, Entities\VieraConnector::class);

			if ($connector === null) {
				$io->warning(
					$this->translator->translate('//viera-connector.cmd.discovery.messages.connector.notFound'),
				);

				return Console\Command\Command::FAILURE;
			}
		} else {
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
				$io->warning($this->translator->translate('//viera-connector.cmd.discovery.messages.noConnectors'));

				return Console\Command\Command::FAILURE;
			}

			if (count($connectors) === 1) {
				$connectorIdentifier = array_key_first($connectors);

				$findConnectorQuery = new Queries\FindConnectors();
				$findConnectorQuery->byIdentifier($connectorIdentifier);

				$connector = $this->connectorsRepository->findOneBy(
					$findConnectorQuery,
					Entities\VieraConnector::class,
				);

				if ($connector === null) {
					$io->warning(
						$this->translator->translate('//viera-connector.cmd.discovery.messages.connector.notFound'),
					);

					return Console\Command\Command::FAILURE;
				}

				if ($input->getOption('no-interaction') === false) {
					$question = new Console\Question\ConfirmationQuestion(
						$this->translator->translate(
							'//viera-connector.cmd.discovery.questions.execute',
							['connector' => $connector->getName() ?? $connector->getIdentifier()],
						),
						false,
					);

					if ($io->askQuestion($question) === false) {
						return Console\Command\Command::SUCCESS;
					}
				}
			} else {
				$question = new Console\Question\ChoiceQuestion(
					$this->translator->translate('//viera-connector.cmd.discovery.questions.select.connector'),
					array_values($connectors),
				);
				$question->setErrorMessage(
					$this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
				);
				$question->setValidator(
					function (string|int|null $answer) use ($connectors): Entities\VieraConnector {
						if ($answer === null) {
							throw new Exceptions\Runtime(
								sprintf(
									$this->translator->translate(
										'//viera-connector.cmd.base.messages.answerNotValid',
									),
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
					},
				);

				$connector = $io->askQuestion($question);
				assert($connector instanceof Entities\VieraConnector);
			}
		}

		if (!$connector->isEnabled()) {
			$io->warning(
				$this->translator->translate('//viera-connector.cmd.discovery.messages.connector.disabled'),
			);

			return Console\Command\Command::SUCCESS;
		}

		$this->executedTime = $this->dateTimeFactory->getNow();

		$serviceCmd = $symfonyApp->find(DevicesCommands\Connector::NAME);

		$result = $serviceCmd->run(new Input\ArrayInput([
			'--connector' => $connector->getId()->toString(),
			'--mode' => DevicesCommands\Connector::MODE_DISCOVER,
			'--no-interaction' => true,
			'--quiet' => true,
		]), $output);

		if ($result !== Console\Command\Command::SUCCESS) {
			$io->error($this->translator->translate('//viera-connector.cmd.execute.messages.error'));

			return Console\Command\Command::FAILURE;
		}

		$this->showResults($io, $output, $connector);

		return Console\Command\Command::SUCCESS;
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 * @throws DoctrineCrudExceptions\InvalidArgumentException
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	private function showResults(
		Style\SymfonyStyle $io,
		Output\OutputInterface $output,
		Entities\VieraConnector $connector,
	): void
	{
		$io->newLine();

		$table = new Console\Helper\Table($output);
		$table->setHeaders([
			'#',
			$this->translator->translate('//viera-connector.cmd.discovery.data.id'),
			$this->translator->translate('//viera-connector.cmd.discovery.data.name'),
			$this->translator->translate('//viera-connector.cmd.discovery.data.model'),
			$this->translator->translate('//viera-connector.cmd.discovery.data.ipAddress'),
			$this->translator->translate('//viera-connector.cmd.discovery.data.encryption'),
		]);

		$foundDevices = 0;
		$encryptedDevices = [];

		$findDevicesQuery = new Queries\FindDevices();
		$findDevicesQuery->forConnector($connector);

		$devices = $this->devicesRepository->findAllBy($findDevicesQuery, Entities\VieraDevice::class);

		foreach ($devices as $device) {
			$createdAt = $device->getCreatedAt();

			if (
				$createdAt !== null
				&& $this->executedTime !== null
				&& $createdAt->getTimestamp() > $this->executedTime->getTimestamp()
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

				if ($isEncrypted && ($device->getAppId() === null || $device->getEncryptionKey() === null)) {
					$encryptedDevices[] = $device;
				}
			}
		}

		if ($foundDevices > 0) {
			$io->newLine();

			$io->info(sprintf(
				$this->translator->translate('//viera-connector.cmd.discovery.messages.foundDevices'),
				$foundDevices,
			));

			$table->render();

			$io->newLine();

		} else {
			$io->info($this->translator->translate('//viera-connector.cmd.discovery.messages.noDevicesFound'));
		}

		if ($encryptedDevices !== []) {
			$this->processEncryptedDevices($io, $connector, $encryptedDevices);
		}

		$io->success($this->translator->translate('//viera-connector.cmd.discovery.messages.success'));
	}

	/**
	 * @param array<Entities\VieraDevice> $encryptedDevices
	 *
	 * @throws DevicesExceptions\InvalidState
	 * @throws DoctrineCrudExceptions\InvalidArgumentException
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 */
	private function processEncryptedDevices(
		Style\SymfonyStyle $io,
		Entities\VieraConnector $connector,
		array $encryptedDevices,
	): void
	{
		$io->info($this->translator->translate('//viera-connector.cmd.discovery.messages.foundEncryptedDevices'));

		$question = new Console\Question\ConfirmationQuestion(
			$this->translator->translate('//viera-connector.cmd.discovery.questions.pairDevice'),
			false,
		);

		$continue = (bool) $io->askQuestion($question);

		if ($continue) {
			foreach ($encryptedDevices as $device) {
				if ($device->getIpAddress() === null) {
					$io->error(
						$this->translator->translate(
							'//viera-connector.cmd.discovery.messages.missingIpAddress',
							['device' => $device->getName()],
						),
					);

					continue;
				}

				$io->info(
					$this->translator->translate(
						'//viera-connector.cmd.discovery.messages.pairing.started',
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
						$this->translator->translate(
							'//viera-connector.cmd.discovery.messages.device.connectionFailed',
							['device' => $device->getName()],
						),
					);

					$this->logger->error(
						'Creating api client failed',
						[
							'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
							'type' => 'discovery-cmd',
							'exception' => BootstrapHelpers\Logger::buildException($ex),
						],
					);

					continue;
				}

				try {
					$isTurnedOn = $televisionApi->isTurnedOn(true);
				} catch (Throwable $ex) {
					$io->error(
						$this->translator->translate(
							'//viera-connector.cmd.discovery.messages.device.pairingFailed',
							['device' => $device->getName()],
						),
					);

					$this->logger->error(
						'Checking screen status failed',
						[
							'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
							'type' => 'discovery-cmd',
							'exception' => BootstrapHelpers\Logger::buildException($ex),
						],
					);

					continue;
				}

				if ($isTurnedOn === false) {
					$io->warning(
						$this->translator->translate(
							'//viera-connector.cmd.discovery.messages.device.offline',
							['device' => $device->getName()],
						),
					);

					$question = new Console\Question\ConfirmationQuestion(
						$this->translator->translate('//viera-connector.cmd.base.questions.continue'),
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
				} catch (Exceptions\TelevisionApiError $ex) {
					$io->error(
						$this->translator->translate(
							'//viera-connector.cmd.discovery.messages.pairing.failed',
							['device' => $device->getName()],
						),
					);

					$this->logger->error(
						'Preparing api request failed',
						[
							'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
							'type' => 'discovery-cmd',
							'exception' => BootstrapHelpers\Logger::buildException($ex),
						],
					);

					continue;
				} catch (Exceptions\TelevisionApiCall $ex) {
					$io->error(
						$this->translator->translate(
							'//viera-connector.cmd.discovery.messages.pairing.failed',
							['device' => $device->getName()],
						),
					);

					$this->logger->error(
						'Calling device api failed',
						[
							'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
							'type' => 'discovery-cmd',
							'exception' => BootstrapHelpers\Logger::buildException($ex),
						],
					);

					continue;
				}

				$authorization = $this->askPinCode($io, $connector, $televisionApi);

				$findDevicePropertyQuery = new DevicesQueries\FindDeviceProperties();
				$findDevicePropertyQuery->forDevice($device);
				$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::APP_ID);

				$appIdProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

				if ($appIdProperty === null) {
					$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
						'entity' => DevicesEntities\Devices\Properties\Variable::class,
						'device' => $device,
						'identifier' => Types\DevicePropertyIdentifier::APP_ID,
						'name' => Helpers\Name::createName(Types\DevicePropertyIdentifier::APP_ID),
						'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
						'settable' => false,
						'queryable' => false,
						'value' => $authorization->getAppId(),
						'format' => null,
					]));
				} else {
					$this->devicesPropertiesManager->update($appIdProperty, Utils\ArrayHash::from([
						'value' => $authorization->getAppId(),
					]));
				}

				$findDevicePropertyQuery = new DevicesQueries\FindDeviceProperties();
				$findDevicePropertyQuery->forDevice($device);
				$findDevicePropertyQuery->byIdentifier(Types\DevicePropertyIdentifier::APP_ID);

				$encryptionKeyProperty = $this->devicesPropertiesRepository->findOneBy($findDevicePropertyQuery);

				if ($encryptionKeyProperty === null) {
					$this->devicesPropertiesManager->create(Utils\ArrayHash::from([
						'entity' => DevicesEntities\Devices\Properties\Variable::class,
						'device' => $device,
						'identifier' => Types\DevicePropertyIdentifier::ENCRYPTION_KEY,
						'name' => Helpers\Name::createName(
							Types\DevicePropertyIdentifier::ENCRYPTION_KEY,
						),
						'dataType' => MetadataTypes\DataType::get(MetadataTypes\DataType::DATA_TYPE_STRING),
						'settable' => false,
						'queryable' => false,
						'value' => $authorization->getEncryptionKey(),
						'format' => null,
					]));
				} else {
					$this->devicesPropertiesManager->update($encryptionKeyProperty, Utils\ArrayHash::from([
						'value' => $authorization->getEncryptionKey(),
					]));
				}

				$io->success(
					$this->translator->translate(
						'//viera-connector.cmd.discovery.messages.pairing.finished',
						['device' => $device->getName()],
					),
				);
			}
		}
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

}
