<?php declare(strict_types = 1);

/**
 * Discover.php
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
use FastyBird\Connector\Viera\Documents;
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
use Ramsey\Uuid;
use Symfony\Component\Console;
use Symfony\Component\Console\Input;
use Symfony\Component\Console\Output;
use Symfony\Component\Console\Style;
use Throwable;
use TypeError;
use ValueError;
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
class Discover extends Console\Command\Command
{

	public const NAME = 'fb:viera-connector:discover';

	private string|null $challengeKey = null;

	private DateTimeInterface|null $executedTime = null;

	public function __construct(
		private readonly Api\TelevisionApiFactory $televisionApiFactory,
		private readonly Helpers\Device $deviceHelper,
		private readonly Viera\Logger $logger,
		private readonly DevicesModels\Entities\Devices\DevicesRepository $devicesRepository,
		private readonly DevicesModels\Entities\Devices\Properties\PropertiesRepository $devicesPropertiesRepository,
		private readonly DevicesModels\Entities\Devices\Properties\PropertiesManager $devicesPropertiesManager,
		private readonly DevicesModels\Configuration\Connectors\Repository $connectorsConfigurationRepository,
		private readonly DevicesModels\Configuration\Devices\Repository $devicesConfigurationRepository,
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
			->setDescription('Viera connector discovery')
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
	 * @throws ApplicationExceptions\InvalidState
	 * @throws Console\Exception\ExceptionInterface
	 * @throws Console\Exception\InvalidArgumentException
	 * @throws DevicesExceptions\InvalidState
	 * @throws DoctrineCrudExceptions\InvalidArgumentException
	 * @throws Exceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws TypeError
	 * @throws ValueError
	 */
	protected function execute(Input\InputInterface $input, Output\OutputInterface $output): int
	{
		$symfonyApp = $this->getApplication();

		if ($symfonyApp === null) {
			return Console\Command\Command::FAILURE;
		}

		$io = new Style\SymfonyStyle($input, $output);

		$io->title((string) $this->translator->translate('//viera-connector.cmd.discover.title'));

		$io->note((string) $this->translator->translate('//viera-connector.cmd.discover.subtitle'));

		if ($input->getOption('no-interaction') === false) {
			$question = new Console\Question\ConfirmationQuestion(
				(string) $this->translator->translate('//viera-connector.cmd.base.questions.continue'),
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

			$findConnectorQuery = new Queries\Configuration\FindConnectors();

			if (Uuid\Uuid::isValid($connectorId)) {
				$findConnectorQuery->byId(Uuid\Uuid::fromString($connectorId));
			} else {
				$findConnectorQuery->byIdentifier($connectorId);
			}

			$connector = $this->connectorsConfigurationRepository->findOneBy(
				$findConnectorQuery,
				Documents\Connectors\Connector::class,
			);

			if ($connector === null) {
				$io->warning(
					(string) $this->translator->translate('//viera-connector.cmd.discover.messages.connector.notFound'),
				);

				return Console\Command\Command::FAILURE;
			}
		} else {
			$connectors = [];

			$findConnectorsQuery = new Queries\Configuration\FindConnectors();

			$systemConnectors = $this->connectorsConfigurationRepository->findAllBy(
				$findConnectorsQuery,
				Documents\Connectors\Connector::class,
			);
			usort(
				$systemConnectors,
				static fn (Documents\Connectors\Connector $a, Documents\Connectors\Connector $b): int => $a->getIdentifier() <=> $b->getIdentifier(),
			);

			foreach ($systemConnectors as $connector) {
				$connectors[$connector->getIdentifier()] = $connector->getIdentifier()
					. ($connector->getName() !== null ? ' [' . $connector->getName() . ']' : '');
			}

			if (count($connectors) === 0) {
				$io->warning((string) $this->translator->translate('//viera-connector.cmd.base.messages.noConnectors'));

				return Console\Command\Command::FAILURE;
			}

			if (count($connectors) === 1) {
				$connectorIdentifier = array_key_first($connectors);

				$findConnectorQuery = new Queries\Configuration\FindConnectors();
				$findConnectorQuery->byIdentifier($connectorIdentifier);

				$connector = $this->connectorsConfigurationRepository->findOneBy(
					$findConnectorQuery,
					Documents\Connectors\Connector::class,
				);

				if ($connector === null) {
					$io->warning(
						(string) $this->translator->translate(
							'//viera-connector.cmd.discover.messages.connector.notFound',
						),
					);

					return Console\Command\Command::FAILURE;
				}

				if ($input->getOption('no-interaction') === false) {
					$question = new Console\Question\ConfirmationQuestion(
						(string) $this->translator->translate(
							'//viera-connector.cmd.discover.questions.execute',
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
					(string) $this->translator->translate('//viera-connector.cmd.discover.questions.select.connector'),
					array_values($connectors),
				);
				$question->setErrorMessage(
					(string) $this->translator->translate('//viera-connector.cmd.base.messages.answerNotValid'),
				);
				$question->setValidator(
					function (string|int|null $answer) use ($connectors): Documents\Connectors\Connector {
						if ($answer === null) {
							throw new Exceptions\Runtime(
								sprintf(
									(string) $this->translator->translate(
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
							$findConnectorQuery = new Queries\Configuration\FindConnectors();
							$findConnectorQuery->byIdentifier($identifier);

							$connector = $this->connectorsConfigurationRepository->findOneBy(
								$findConnectorQuery,
								Documents\Connectors\Connector::class,
							);

							if ($connector !== null) {
								return $connector;
							}
						}

						throw new Exceptions\Runtime(
							sprintf(
								(string) $this->translator->translate(
									'//viera-connector.cmd.base.messages.answerNotValid',
								),
								$answer,
							),
						);
					},
				);

				$connector = $io->askQuestion($question);
				assert($connector instanceof Documents\Connectors\Connector);
			}
		}

		if (!$connector->isEnabled()) {
			$io->warning(
				(string) $this->translator->translate('//viera-connector.cmd.discover.messages.connector.disabled'),
			);

			return Console\Command\Command::SUCCESS;
		}

		$io->info((string) $this->translator->translate('//viera-connector.cmd.discover.messages.starting'));

		$this->executedTime = $this->dateTimeFactory->getNow();

		$serviceCmd = $symfonyApp->find(DevicesCommands\Connector::NAME);

		$result = $serviceCmd->run(new Input\ArrayInput([
			'--connector' => $connector->getId()->toString(),
			'--mode' => DevicesTypes\ConnectorMode::DISCOVER->value,
			'--no-interaction' => true,
			'--quiet' => true,
		]), $output);

		$io->newLine(2);

		$io->info((string) $this->translator->translate('//viera-connector.cmd.discover.messages.stopping'));

		if ($result !== Console\Command\Command::SUCCESS) {
			$io->error((string) $this->translator->translate('//viera-connector.cmd.execute.messages.error'));

			return Console\Command\Command::FAILURE;
		}

		$this->showResults($io, $output, $connector);

		return Console\Command\Command::SUCCESS;
	}

	/**
	 * @throws ApplicationExceptions\InvalidState
	 * @throws DevicesExceptions\InvalidState
	 * @throws DoctrineCrudExceptions\InvalidArgumentException
	 * @throws Exceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidArgument
	 * @throws MetadataExceptions\InvalidState
	 * @throws TypeError
	 * @throws ValueError
	 */
	private function showResults(
		Style\SymfonyStyle $io,
		Output\OutputInterface $output,
		Documents\Connectors\Connector $connector,
	): void
	{
		$table = new Console\Helper\Table($output);
		$table->setHeaders([
			'#',
			(string) $this->translator->translate('//viera-connector.cmd.discover.data.id'),
			(string) $this->translator->translate('//viera-connector.cmd.discover.data.name'),
			(string) $this->translator->translate('//viera-connector.cmd.discover.data.model'),
			(string) $this->translator->translate('//viera-connector.cmd.discover.data.ipAddress'),
			(string) $this->translator->translate('//viera-connector.cmd.discover.data.encryption'),
		]);

		$foundDevices = 0;
		$encryptedDevices = [];

		$findDevicesQuery = new Queries\Configuration\FindDevices();
		$findDevicesQuery->forConnector($connector);

		$devices = $this->devicesConfigurationRepository->findAllBy(
			$findDevicesQuery,
			Documents\Devices\Device::class,
		);

		foreach ($devices as $device) {
			$createdAt = $device->getCreatedAt();

			if (
				$createdAt !== null
				&& $this->executedTime !== null
				&& $createdAt->getTimestamp() > $this->executedTime->getTimestamp()
			) {
				$foundDevices++;

				$isEncrypted = $this->deviceHelper->isEncrypted($device);

				$table->addRow([
					$foundDevices,
					$device->getId()->toString(),
					$device->getName() ?? $device->getIdentifier(),
					$this->deviceHelper->getModel($device) ?? 'N/A',
					$this->deviceHelper->getIpAddress($device) ?? 'N/A',
					$isEncrypted ? 'yes' : 'no',
				]);

				if (
					$isEncrypted
					&& (
						$this->deviceHelper->getAppId($device) === null
						|| $this->deviceHelper->getEncryptionKey($device) === null
					)
				) {
					$encryptedDevices[] = $device;
				}
			}
		}

		if ($foundDevices > 0) {
			$io->info(sprintf(
				(string) $this->translator->translate('//viera-connector.cmd.discover.messages.foundDevices'),
				$foundDevices,
			));

			$table->render();

			$io->newLine();

		} else {
			$io->info((string) $this->translator->translate('//viera-connector.cmd.discover.messages.noDevicesFound'));
		}

		if ($encryptedDevices !== []) {
			$this->processEncryptedDevices($io, $connector, $encryptedDevices);
		}

		$io->success((string) $this->translator->translate('//viera-connector.cmd.discover.messages.success'));
	}

	/**
	 * @param array<Documents\Devices\Device> $encryptedDevices
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
		Documents\Connectors\Connector $connector,
		array $encryptedDevices,
	): void
	{
		$io->info(
			(string) $this->translator->translate('//viera-connector.cmd.discover.messages.foundEncryptedDevices'),
		);

		$question = new Console\Question\ConfirmationQuestion(
			(string) $this->translator->translate('//viera-connector.cmd.discover.questions.pairDevice'),
			false,
		);

		$continue = (bool) $io->askQuestion($question);

		if ($continue) {
			foreach ($encryptedDevices as $configuredDevice) {
				$device = $this->devicesRepository->find($configuredDevice->getId());
				assert($device instanceof Entities\Devices\Device);

				if ($device->getIpAddress() === null) {
					$io->error(
						(string) $this->translator->translate(
							'//viera-connector.cmd.discover.messages.missingIpAddress',
							['device' => $device->getName()],
						),
					);

					continue;
				}

				$io->info(
					(string) $this->translator->translate(
						'//viera-connector.cmd.discover.messages.pairing.started',
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
							'//viera-connector.cmd.discover.messages.device.connectionFailed',
							['device' => $device->getName()],
						),
					);

					$this->logger->error(
						'Creating api client failed',
						[
							'source' => MetadataTypes\Sources\Connector::VIERA->value,
							'type' => 'discovery-cmd',
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
							'//viera-connector.cmd.discover.messages.device.pairingFailed',
							['device' => $device->getName()],
						),
					);

					$this->logger->error(
						'Checking screen status failed',
						[
							'source' => MetadataTypes\Sources\Connector::VIERA->value,
							'type' => 'discovery-cmd',
							'exception' => ApplicationHelpers\Logger::buildException($ex),
						],
					);

					continue;
				}

				if ($isTurnedOn === false) {
					$io->warning(
						(string) $this->translator->translate(
							'//viera-connector.cmd.discover.messages.device.offline',
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
				} catch (Exceptions\TelevisionApiError $ex) {
					$io->error(
						(string) $this->translator->translate(
							'//viera-connector.cmd.discover.messages.pairing.failed',
							['device' => $device->getName()],
						),
					);

					$this->logger->error(
						'Preparing api request failed',
						[
							'source' => MetadataTypes\Sources\Connector::VIERA->value,
							'type' => 'discovery-cmd',
							'exception' => ApplicationHelpers\Logger::buildException($ex),
						],
					);

					continue;
				} catch (Exceptions\TelevisionApiCall $ex) {
					$io->error(
						(string) $this->translator->translate(
							'//viera-connector.cmd.discover.messages.pairing.failed',
							['device' => $device->getName()],
						),
					);

					$this->logger->error(
						'Calling device api failed',
						[
							'source' => MetadataTypes\Sources\Connector::VIERA->value,
							'type' => 'discovery-cmd',
							'exception' => ApplicationHelpers\Logger::buildException($ex),
						],
					);

					continue;
				}

				$authorization = $this->askPinCode($io, $connector, $televisionApi);

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
						'//viera-connector.cmd.discover.messages.pairing.finished',
						['device' => $device->getName()],
					),
				);
			}
		}
	}

	private function askPinCode(
		Style\SymfonyStyle $io,
		Documents\Connectors\Connector $connector,
		API\TelevisionApi $televisionApi,
	): API\Messages\Response\AuthorizePinCode
	{
		$question = new Console\Question\Question(
			(string) $this->translator->translate('//viera-connector.cmd.discover.questions.provide.pinCode'),
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

}
