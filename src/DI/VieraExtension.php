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
use FastyBird\Connector\Viera\API;
use FastyBird\Connector\Viera\Clients;
use FastyBird\Connector\Viera\Commands;
use FastyBird\Connector\Viera\Connector;
use FastyBird\Connector\Viera\Consumers;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Connector\Viera\Hydrators;
use FastyBird\Connector\Viera\Schemas;
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
		// @phpstan-ignore-next-line
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

		$writer = null;

		if ($configuration->writer === Writers\Event::NAME) {
			$writer = $builder->addDefinition($this->prefix('writers.event'), new DI\Definitions\ServiceDefinition())
				->setType(Writers\Event::class)
				->setAutowired(false);
		} elseif ($configuration->writer === Writers\Exchange::NAME) {
			$writer = $builder->addDefinition($this->prefix('writers.exchange'), new DI\Definitions\ServiceDefinition())
				->setType(Writers\Exchange::class)
				->setAutowired(false)
				->addTag(ExchangeDI\ExchangeExtension::CONSUMER_STATE, false);
		} elseif ($configuration->writer === Writers\Periodic::NAME) {
			$writer = $builder->addDefinition($this->prefix('writers.periodic'), new DI\Definitions\ServiceDefinition())
				->setType(Writers\Periodic::class)
				->setAutowired(false);
		}

		$builder->addFactoryDefinition($this->prefix('clients.television'))
			->setImplement(Clients\TelevisionFactory::class)
			->getResultDefinition()
			->setType(Clients\Television::class)
			->setArguments([
				'writer' => $writer,
			]);

		$builder->addFactoryDefinition($this->prefix('clients.discovery'))
			->setImplement(Clients\DiscoveryFactory::class)
			->getResultDefinition()
			->setType(Clients\Discovery::class);

		$builder->addFactoryDefinition($this->prefix('api.televisionApi'))
			->setImplement(API\TelevisionApiFactory::class)
			->getResultDefinition()
			->setType(API\TelevisionApi::class);

		$builder->addDefinition($this->prefix('api.httpClient'), new DI\Definitions\ServiceDefinition())
			->setType(API\HttpClientFactory::class);

		$builder->addDefinition(
			$this->prefix('consumers.messages.device.configured'),
			new DI\Definitions\ServiceDefinition(),
		)
			->setType(Consumers\Messages\ConfigureDevice::class);

		$builder->addDefinition(
			$this->prefix('consumers.messages.device.state'),
			new DI\Definitions\ServiceDefinition(),
		)
			->setType(Consumers\Messages\State::class);

		$builder->addDefinition(
			$this->prefix('consumers.messages.channel.propertyState'),
			new DI\Definitions\ServiceDefinition(),
		)
			->setType(Consumers\Messages\ChannelPropertyState::class);

		$builder->addDefinition($this->prefix('consumers.messages'), new DI\Definitions\ServiceDefinition())
			->setType(Consumers\Messages::class)
			->setArguments([
				'consumers' => $builder->findByType(Consumers\Consumer::class),
			]);

		$builder->addDefinition($this->prefix('subscribers.properties'), new DI\Definitions\ServiceDefinition())
			->setType(Subscribers\Properties::class);

		$builder->addDefinition($this->prefix('subscribers.controls'), new DI\Definitions\ServiceDefinition())
			->setType(Subscribers\Controls::class);

		$builder->addDefinition($this->prefix('schemas.connector.viera'), new DI\Definitions\ServiceDefinition())
			->setType(Schemas\VieraConnector::class);

		$builder->addDefinition($this->prefix('schemas.device.viera'), new DI\Definitions\ServiceDefinition())
			->setType(Schemas\VieraDevice::class);

		$builder->addDefinition($this->prefix('hydrators.connector.viera'), new DI\Definitions\ServiceDefinition())
			->setType(Hydrators\VieraConnector::class);

		$builder->addDefinition($this->prefix('hydrators.device.viera'), new DI\Definitions\ServiceDefinition())
			->setType(Hydrators\VieraDevice::class);

		$builder->addDefinition($this->prefix('helpers.property'), new DI\Definitions\ServiceDefinition())
			->setType(Helpers\Property::class);

		$builder->addDefinition($this->prefix('helpers.name'), new DI\Definitions\ServiceDefinition())
			->setType(Helpers\Name::class);

		$builder->addFactoryDefinition($this->prefix('executor.factory'))
			->setImplement(Connector\ConnectorFactory::class)
			->addTag(
				DevicesDI\DevicesExtension::CONNECTOR_TYPE_TAG,
				Entities\VieraConnector::CONNECTOR_TYPE,
			)
			->getResultDefinition()
			->setType(Connector\Connector::class)
			->setArguments();

		$builder->addDefinition($this->prefix('commands.initialize'), new DI\Definitions\ServiceDefinition())
			->setType(Commands\Initialize::class);

		$builder->addDefinition($this->prefix('commands.discovery'), new DI\Definitions\ServiceDefinition())
			->setType(Commands\Discovery::class);

		$builder->addDefinition($this->prefix('commands.device'), new DI\Definitions\ServiceDefinition())
			->setType(Commands\Devices::class);

		$builder->addDefinition($this->prefix('commands.execute'), new DI\Definitions\ServiceDefinition())
			->setType(Commands\Execute::class);
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
