<?php declare(strict_types = 1);

/**
 * VieraExtension.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     DI
 * @since          1.0.0
 *
 * @date           21.06.23
 */

namespace FastyBird\Connector\Viera\DI;

use Doctrine\Persistence;
use FastyBird\Connector\Viera;
use FastyBird\Connector\Viera\API;
use FastyBird\Connector\Viera\Clients;
use FastyBird\Connector\Viera\Commands;
use FastyBird\Connector\Viera\Connector;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Connector\Viera\Hydrators;
use FastyBird\Connector\Viera\Queue;
use FastyBird\Connector\Viera\Schemas;
use FastyBird\Connector\Viera\Services;
use FastyBird\Connector\Viera\Subscribers;
use FastyBird\Connector\Viera\Writers;
use FastyBird\Library\Bootstrap\Boot as BootstrapBoot;
use FastyBird\Library\Exchange\DI as ExchangeDI;
use FastyBird\Module\Devices\DI as DevicesDI;
use Nette\DI;
use Nette\Schema;
use stdClass;
use function assert;
use const DIRECTORY_SEPARATOR;

/**
 * Viera connector
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     DI
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class VieraExtension extends DI\CompilerExtension
{

	public const NAME = 'fbVieraConnector';

	public static function register(
		BootstrapBoot\Configurator $config,
		string $extensionName = self::NAME,
	): void
	{
		$config->onCompile[] = static function (
			BootstrapBoot\Configurator $config,
			DI\Compiler $compiler,
		) use ($extensionName): void {
			$compiler->addExtension($extensionName, new self());
		};
	}

	public function getConfigSchema(): Schema\Schema
	{
		return Schema\Expect::structure([
			'writer' => Schema\Expect::anyOf(
				Writers\Event::NAME,
				Writers\Exchange::NAME,
				Writers\Periodic::NAME,
			)->default(
				Writers\Periodic::NAME,
			),
		]);
	}

	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$configuration = $this->getConfig();
		assert($configuration instanceof stdClass);

		$logger = $builder->addDefinition($this->prefix('logger'), new DI\Definitions\ServiceDefinition())
			->setType(Viera\Logger::class)
			->setAutowired(false);

		/**
		 * WRITERS
		 */

		if ($configuration->writer === Writers\Event::NAME) {
			$builder->addFactoryDefinition($this->prefix('writers.event'))
				->setImplement(Writers\EventFactory::class)
				->getResultDefinition()
				->setType(Writers\Event::class);
		} elseif ($configuration->writer === Writers\Exchange::NAME) {
			$builder->addFactoryDefinition($this->prefix('writers.exchange'))
				->setImplement(Writers\ExchangeFactory::class)
				->getResultDefinition()
				->setType(Writers\Exchange::class)
				->addTag(ExchangeDI\ExchangeExtension::CONSUMER_STATE, false);
		} elseif ($configuration->writer === Writers\Periodic::NAME) {
			$builder->addFactoryDefinition($this->prefix('writers.periodic'))
				->setImplement(Writers\PeriodicFactory::class)
				->getResultDefinition()
				->setType(Writers\Periodic::class);
		}

		/**
		 * SERVICES & FACTORIES
		 */

		$builder->addDefinition(
			$this->prefix('services.multicastFactory'),
			new DI\Definitions\ServiceDefinition(),
		)
			->setType(Services\MulticastFactory::class);

		$builder->addDefinition($this->prefix('services.httpClientFactory'), new DI\Definitions\ServiceDefinition())
			->setType(Services\HttpClientFactory::class);

		$builder->addDefinition(
			$this->prefix('services.socketClientFactory'),
			new DI\Definitions\ServiceDefinition(),
		)
			->setType(Services\SocketClientFactory::class);

		/**
		 * CLIENTS
		 */

		$builder->addFactoryDefinition($this->prefix('clients.television'))
			->setImplement(Clients\TelevisionFactory::class)
			->getResultDefinition()
			->setType(Clients\Television::class)
			->setArguments([
				'logger' => $logger,
			]);

		$builder->addFactoryDefinition($this->prefix('clients.discovery'))
			->setImplement(Clients\DiscoveryFactory::class)
			->getResultDefinition()
			->setType(Clients\Discovery::class)
			->setArguments([
				'logger' => $logger,
			]);

		/**
		 * API
		 */

		$builder->addDefinition($this->prefix('api.connectionsManager'), new DI\Definitions\ServiceDefinition())
			->setType(API\ConnectionManager::class);

		$builder->addFactoryDefinition($this->prefix('api.televisionApi'))
			->setImplement(API\TelevisionApiFactory::class)
			->getResultDefinition()
			->setType(API\TelevisionApi::class)
			->setArguments([
				'logger' => $logger,
			]);

		/**
		 * MESSAGES QUEUE
		 */

		$builder->addDefinition(
			$this->prefix('queue.consumers.store.device'),
			new DI\Definitions\ServiceDefinition(),
		)
			->setType(Queue\Consumers\StoreDevice::class)
			->setArguments([
				'logger' => $logger,
			]);

		$builder->addDefinition(
			$this->prefix('queue.consumers.store.deviceConnectionState'),
			new DI\Definitions\ServiceDefinition(),
		)
			->setType(Queue\Consumers\StoreDeviceConnectionState::class)
			->setArguments([
				'logger' => $logger,
			]);

		$builder->addDefinition(
			$this->prefix('queue.consumers.store.channelPropertyState'),
			new DI\Definitions\ServiceDefinition(),
		)
			->setType(Queue\Consumers\StoreChannelPropertyState::class)
			->setArguments([
				'logger' => $logger,
			]);

		$builder->addDefinition(
			$this->prefix('queue.consumers.write.channelPropertyState'),
			new DI\Definitions\ServiceDefinition(),
		)
			->setType(Queue\Consumers\WriteChannelPropertyState::class)
			->setArguments([
				'logger' => $logger,
			]);

		$builder->addDefinition(
			$this->prefix('queue.consumers'),
			new DI\Definitions\ServiceDefinition(),
		)
			->setType(Queue\Consumers::class)
			->setArguments([
				'consumers' => $builder->findByType(Queue\Consumer::class),
				'logger' => $logger,
			]);

		$builder->addDefinition(
			$this->prefix('queue.queue'),
			new DI\Definitions\ServiceDefinition(),
		)
			->setType(Queue\Queue::class)
			->setArguments([
				'logger' => $logger,
			]);

		/**
		 * SUBSCRIBERS
		 */

		$builder->addDefinition($this->prefix('subscribers.properties'), new DI\Definitions\ServiceDefinition())
			->setType(Subscribers\Properties::class);

		$builder->addDefinition($this->prefix('subscribers.controls'), new DI\Definitions\ServiceDefinition())
			->setType(Subscribers\Controls::class);

		/**
		 * JSON-API SCHEMAS
		 */

		$builder->addDefinition($this->prefix('schemas.connector.viera'), new DI\Definitions\ServiceDefinition())
			->setType(Schemas\VieraConnector::class);

		$builder->addDefinition($this->prefix('schemas.device.viera'), new DI\Definitions\ServiceDefinition())
			->setType(Schemas\VieraDevice::class);

		/**
		 * JSON-API HYDRATORS
		 */

		$builder->addDefinition($this->prefix('hydrators.connector.viera'), new DI\Definitions\ServiceDefinition())
			->setType(Hydrators\VieraConnector::class);

		$builder->addDefinition($this->prefix('hydrators.device.viera'), new DI\Definitions\ServiceDefinition())
			->setType(Hydrators\VieraDevice::class);

		/**
		 * HELPERS
		 */

		$builder->addDefinition($this->prefix('helpers.entity'), new DI\Definitions\ServiceDefinition())
			->setType(Helpers\Entity::class);

		/**
		 * COMMANDS
		 */

		$builder->addDefinition($this->prefix('commands.initialize'), new DI\Definitions\ServiceDefinition())
			->setType(Commands\Initialize::class)
			->setArguments([
				'logger' => $logger,
			]);

		$builder->addDefinition($this->prefix('commands.device'), new DI\Definitions\ServiceDefinition())
			->setType(Commands\Devices::class)
			->setArguments([
				'logger' => $logger,
			]);

		$builder->addDefinition($this->prefix('commands.execute'), new DI\Definitions\ServiceDefinition())
			->setType(Commands\Execute::class);

		$builder->addDefinition($this->prefix('commands.discovery'), new DI\Definitions\ServiceDefinition())
			->setType(Commands\Discovery::class)
			->setArguments([
				'logger' => $logger,
			]);

		/**
		 * CONNECTOR
		 */

		$builder->addFactoryDefinition($this->prefix('connector'))
			->setImplement(Connector\ConnectorFactory::class)
			->addTag(
				DevicesDI\DevicesExtension::CONNECTOR_TYPE_TAG,
				Entities\VieraConnector::TYPE,
			)
			->getResultDefinition()
			->setType(Connector\Connector::class)
			->setArguments([
				'logger' => $logger,
			]);
	}

	/**
	 * @throws DI\MissingServiceException
	 */
	public function beforeCompile(): void
	{
		parent::beforeCompile();

		$builder = $this->getContainerBuilder();

		/**
		 * Doctrine entities
		 */

		$ormAnnotationDriverService = $builder->getDefinition('nettrineOrmAnnotations.annotationDriver');

		if ($ormAnnotationDriverService instanceof DI\Definitions\ServiceDefinition) {
			$ormAnnotationDriverService->addSetup(
				'addPaths',
				[[__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Entities']],
			);
		}

		$ormAnnotationDriverChainService = $builder->getDefinitionByType(
			Persistence\Mapping\Driver\MappingDriverChain::class,
		);

		if ($ormAnnotationDriverChainService instanceof DI\Definitions\ServiceDefinition) {
			$ormAnnotationDriverChainService->addSetup('addDriver', [
				$ormAnnotationDriverService,
				'FastyBird\Connector\Viera\Entities',
			]);
		}
	}

}
