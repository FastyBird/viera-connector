<?php declare(strict_types = 1);

namespace FastyBird\Connector\Viera\Tests\Cases\Unit\DI;

use Error;
use FastyBird\Connector\Viera\Hydrators;
use FastyBird\Connector\Viera\Schemas;
use FastyBird\Connector\Viera\Tests\Cases\Unit\BaseTestCase;
use FastyBird\Library\Bootstrap\Exceptions as BootstrapExceptions;
use Nette;

final class VieraExtensionTest extends BaseTestCase
{

	/**
	 * @throws BootstrapExceptions\InvalidArgument
	 * @throws Nette\DI\MissingServiceException
	 * @throws Error
	 */
	public function testServicesRegistration(): void
	{
		$container = $this->createContainer();

		self::assertNotNull($container->getByType(Hydrators\VieraConnector::class, false));
		self::assertNotNull($container->getByType(Hydrators\VieraDevice::class, false));

		self::assertNotNull($container->getByType(Schemas\VieraConnector::class, false));
		self::assertNotNull($container->getByType(Schemas\VieraDevice::class, false));
	}

}
