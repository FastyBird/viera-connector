<?php declare(strict_types = 1);

/**
 * Event.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Writers
 * @since          1.0.0
 *
 * @date           21.06.23
 */

namespace FastyBird\Connector\Viera\Writers;

use DateTimeInterface;
use FastyBird\Connector\Viera\Clients;
use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Helpers;
use FastyBird\DateTimeFactory;
use FastyBird\Library\Bootstrap\Helpers as BootstrapHelpers;
use FastyBird\Library\Metadata\Entities as MetadataEntities;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Module\Devices\Entities as DevicesEntities;
use FastyBird\Module\Devices\Events as DevicesEvents;
use FastyBird\Module\Devices\Exceptions as DevicesExceptions;
use FastyBird\Module\Devices\Models as DevicesModels;
use FastyBird\Module\Devices\Queries as DevicesQueries;
use FastyBird\Module\Devices\States as DevicesStates;
use Nette;
use Nette\Utils;
use Psr\Log;
use Ramsey\Uuid;
use Symfony\Component\EventDispatcher;
use Throwable;
use function assert;

/**
 * Event based properties writer
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Writers
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class Event implements Writer, EventDispatcher\EventSubscriberInterface
{

	use Nette\SmartObject;

	public const NAME = 'event';

	/** @var array<string, Clients\Client> */
	private array $clients = [];

	public function __construct(
		private readonly Helpers\Property $propertyStateHelper,
		private readonly DateTimeFactory\Factory $dateTimeFactory,
		private readonly DevicesModels\Channels\ChannelsRepository $channelsRepository,
		private readonly Log\LoggerInterface $logger = new Log\NullLogger(),
	)
	{
	}

	public static function getSubscribedEvents(): array
	{
		return [
			DevicesEvents\ChannelPropertyStateEntityCreated::class => 'stateChanged',
			DevicesEvents\ChannelPropertyStateEntityUpdated::class => 'stateChanged',
		];
	}

	public function connect(
		Entities\VieraConnector $connector,
		Clients\Client $client,
	): void
	{
		$this->clients[$connector->getPlainId()] = $client;
	}

	public function disconnect(
		Entities\VieraConnector $connector,
		Clients\Client $client,
	): void
	{
		unset($this->clients[$connector->getPlainId()]);
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 */
	public function stateChanged(
		DevicesEvents\ChannelPropertyStateEntityCreated|DevicesEvents\ChannelPropertyStateEntityUpdated $event,
	): void
	{
		foreach ($this->clients as $id => $client) {
			$this->processClient(Uuid\Uuid::fromString($id), $event, $client);
		}
	}

	/**
	 * @throws DevicesExceptions\InvalidState
	 */
	public function processClient(
		Uuid\UuidInterface $connectorId,
		DevicesEvents\ChannelPropertyStateEntityCreated|DevicesEvents\ChannelPropertyStateEntityUpdated $event,
		Clients\Client $client,
	): void
	{
		$property = $event->getProperty();

		$state = $event->getState();

		if ($state->getExpectedValue() === null || $state->getPending() !== true) {
			return;
		}

		if (
			$property instanceof DevicesEntities\Channels\Properties\Dynamic
			|| $property instanceof MetadataEntities\DevicesModule\ChannelDynamicProperty
		) {
			if ($property->getChannel() instanceof DevicesEntities\Channels\Channel) {
				$channel = $property->getChannel();

			} else {
				$findChannelQuery = new DevicesQueries\FindChannels();
				$findChannelQuery->byId($property->getChannel());

				$channel = $this->channelsRepository->findOneBy($findChannelQuery);
			}

			if ($channel === null) {
				return;
			}

			if (!$channel->getDevice()->getConnector()->getId()->equals($connectorId)) {
				return;
			}

			$device = $channel->getDevice();

			assert($device instanceof Entities\VieraDevice);

			$client->writeChannelProperty($device, $channel, $property)
				->then(function () use ($property): void {
					$this->propertyStateHelper->setValue(
						$property,
						Utils\ArrayHash::from([
							DevicesStates\Property::PENDING_KEY => $this->dateTimeFactory->getNow()->format(
								DateTimeInterface::ATOM,
							),
						]),
					);
				})
				->otherwise(function (Throwable $ex) use ($connectorId, $device, $channel, $property): void {
					$this->logger->error(
						'Could write new property state',
						[
							'source' => MetadataTypes\ConnectorSource::SOURCE_CONNECTOR_VIERA,
							'type' => 'event-writer',
							'exception' => BootstrapHelpers\Logger::buildException($ex),
							'connector' => [
								'id' => $connectorId->toString(),
							],
							'device' => [
								'id' => $device->getPlainId(),
							],
							'channel' => [
								'id' => $channel->getPlainId(),
							],
							'property' => [
								'id' => $property->getId()->toString(),
							],
						],
					);

					$this->propertyStateHelper->setValue(
						$property,
						Utils\ArrayHash::from([
							DevicesStates\Property::EXPECTED_VALUE_KEY => null,
							DevicesStates\Property::PENDING_KEY => false,
						]),
					);
				});
		}
	}

}
