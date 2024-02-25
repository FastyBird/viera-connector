<?php declare(strict_types = 1);

/**
 * MessageBuilder.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Helpers
 * @since          1.0.0
 *
 * @date           10.08.23
 */

namespace FastyBird\Connector\Viera\Helpers;

use FastyBird\Connector\Viera\API;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Connector\Viera\Queue;
use Orisai\ObjectMapper;

/**
 * Message builder
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Helpers
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final readonly class MessageBuilder
{

	public function __construct(
		private ObjectMapper\Processing\Processor $processor,
	)
	{
	}

	/**
	 * @template T of API\Messages\Message|Queue\Messages\Message
	 *
	 * @param class-string<T> $message
	 * @param array<mixed> $data
	 *
	 * @return T
	 *
	 * @throws Exceptions\Runtime
	 */
	public function create(
		string $message,
		array $data,
	): API\Messages\Message|Queue\Messages\Message
	{
		try {
			$options = new ObjectMapper\Processing\Options();
			$options->setAllowUnknownFields();

			return $this->processor->process($data, $message, $options);
		} catch (ObjectMapper\Exception\InvalidData $ex) {
			$errorPrinter = new ObjectMapper\Printers\ErrorVisualPrinter(
				new ObjectMapper\Printers\TypeToStringConverter(),
			);

			throw new Exceptions\Runtime('Could not map data to message: ' . $errorPrinter->printError($ex));
		}
	}

}
