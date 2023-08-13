<?php declare(strict_types = 1);

/**
 * Logger.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     common
 * @since          1.0.0
 *
 * @date           10.08.23
 */

namespace FastyBird\Connector\Viera;

use Monolog;
use Psr\Log;

/**
 * Connector logger
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     common
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class Logger implements Log\LoggerInterface
{

	private Log\LoggerInterface $logger;

	public function __construct(
		Log\LoggerInterface $logger = new Log\NullLogger(),
	)
	{
		$this->logger = $logger instanceof Monolog\Logger ? $logger->withName(DI\VieraExtension::NAME) : $logger;
	}

	/**
	 * @param string $message
	 * @param array<mixed> $context
	 */
	// phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	public function alert($message, array $context = []): void
	{
		$this->logger->alert($message, $context);
	}

	/**
	 * @param string $message
	 * @param array<mixed> $context
	 */
	// phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	public function critical($message, array $context = []): void
	{
		$this->logger->critical($message, $context);
	}

	/**
	 * @param string $message
	 * @param array<mixed> $context
	 */
	// phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	public function debug($message, array $context = []): void
	{
		$this->logger->debug($message, $context);
	}

	/**
	 * @param string $message
	 * @param array<mixed> $context
	 */
	// phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	public function emergency($message, array $context = []): void
	{
		$this->logger->emergency($message, $context);
	}

	/**
	 * @param string $message
	 * @param array<mixed> $context
	 */
	// phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	public function error($message, array $context = []): void
	{
		$this->logger->error($message, $context);
	}

	/**
	 * @param string $message
	 * @param array<mixed> $context
	 */
	// phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	public function info($message, array $context = []): void
	{
		$this->logger->info($message, $context);
	}

	/**
	 * @param string $message
	 * @param array<mixed> $context
	 */
	// phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	public function log(mixed $level, $message, array $context = []): void
	{
		$this->logger->log($level, $message, $context);
	}

	/**
	 * @param string $message
	 * @param array<mixed> $context
	 */
	// phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	public function notice($message, array $context = []): void
	{
		$this->logger->notice($message, $context);
	}

	/**
	 * @param string $message
	 * @param array<mixed> $context
	 */
	// phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	public function warning($message, array $context = []): void
	{
		$this->logger->warning($message, $context);
	}

}
