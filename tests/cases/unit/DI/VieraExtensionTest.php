<?php declare(strict_types = 1);

namespace FastyBird\Connector\Viera\Tests\Cases\Unit\DI;

use Error;
use FastyBird\Connector\Viera\API;
use FastyBird\Connector\Viera\Clients;
use FastyBird\Connector\Viera\Commands;
use FastyBird\Connector\Viera\Connector;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\Connector\Viera\Hydrators;
use FastyBird\Connector\Viera\Queue;
use FastyBird\Connector\Viera\Schemas;
use FastyBird\Connector\Viera\Services;
use FastyBird\Connector\Viera\Subscribers;
use FastyBird\Connector\Viera\Tests;
use FastyBird\Connector\Viera\Writers;
use FastyBird\Library\Application\Exceptions as ApplicationExceptions;
use Nette;

final class VieraExtensionTest extends Tests\Cases\Unit\BaseTestCase
{

	/**
	 * @throws ApplicationExceptions\InvalidArgument
	 * @throws ApplicationExceptions\InvalidState
	 * @throws Nette\DI\MissingServiceException
	 * @throws Error
	 */
	public function testServicesRegistration(): void
	{
		$container = $this->createContainer();

		self::assertCount(2, $container->findByType(Writers\WriterFactory::class));

		self::assertNotNull($container->getByType(Clients\TelevisionFactory::class, false));
		self::assertNotNull($container->getByType(Clients\DiscoveryFactory::class, false));

		self::assertNotNull($container->getByType(Services\HttpClientFactory::class, false));
		self::assertNotNull($container->getByType(Services\MulticastFactory::class, false));
		self::assertNotNull($container->getByType(Services\SocketClientFactory::class, false));

		self::assertNotNull($container->getByType(API\ConnectionManager::class, false));
		self::assertNotNull($container->getByType(API\TelevisionApiFactory::class, false));

		self::assertNotNull($container->getByType(Queue\Consumers\StoreDevice::class, false));
		self::assertNotNull($container->getByType(Queue\Consumers\StoreDeviceConnectionState::class, false));
		self::assertNotNull($container->getByType(Queue\Consumers\StoreChannelPropertyState::class, false));
		self::assertNotNull($container->getByType(Queue\Consumers\WriteChannelPropertyState::class, false));
		self::assertNotNull($container->getByType(Queue\Consumers::class, false));
		self::assertNotNull($container->getByType(Queue\Queue::class, false));

		self::assertNotNull($container->getByType(Subscribers\Properties::class, false));
		self::assertNotNull($container->getByType(Subscribers\Controls::class, false));
		self::assertNotNull($container->getByType(Subscribers\Device::class, false));

		self::assertNotNull($container->getByType(Schemas\Connectors\Connector::class, false));
		self::assertNotNull($container->getByType(Schemas\Devices\Device::class, false));

		self::assertNotNull($container->getByType(Hydrators\Connectors\Connector::class, false));
		self::assertNotNull($container->getByType(Hydrators\Devices\Device::class, false));

		self::assertNotNull($container->getByType(Helpers\MessageBuilder::class, false));
		self::assertNotNull($container->getByType(Helpers\Device::class, false));
		self::assertNotNull($container->getByType(Helpers\DeviceProperty::class, false));
		self::assertNotNull($container->getByType(Helpers\ChannelProperty::class, false));

		self::assertNotNull($container->getByType(Commands\Execute::class, false));
		self::assertNotNull($container->getByType(Commands\Discover::class, false));
		self::assertNotNull($container->getByType(Commands\Install::class, false));

		self::assertNotNull($container->getByType(Connector\ConnectorFactory::class, false));
	}

}
