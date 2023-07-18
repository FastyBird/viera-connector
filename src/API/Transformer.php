<?php declare(strict_types = 1);

/**
 * Transformer.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 * @since          1.0.0
 *
 * @date           01.07.23
 */

namespace FastyBird\Connector\Viera\API;

use DateTimeInterface;
use FastyBird\Connector\Viera\Exceptions;
use FastyBird\Library\Metadata\Exceptions as MetadataExceptions;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use FastyBird\Library\Metadata\ValueObjects as MetadataValueObjects;
use FastyBird\Module\Devices\Utilities as DevicesUtilities;
use Nette;
use Nette\Utils;
use Throwable;
use function array_filter;
use function array_values;
use function base64_decode;
use function base64_encode;
use function boolval;
use function chr;
use function count;
use function floatval;
use function hash_hmac;
use function intval;
use function is_bool;
use function openssl_decrypt;
use function openssl_encrypt;
use function pack;
use function random_bytes;
use function str_repeat;
use function strlen;
use function strval;
use function substr;
use function unpack;
use const OPENSSL_RAW_DATA;
use const OPENSSL_ZERO_PADDING;

/**
 * Devices data transformers
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class Transformer
{

	use Nette\SmartObject;

	public const SIGNATURE_BYTES_LENGTH = 32;

	/**
	 * @throws Exceptions\Encrypt
	 */
	public static function encryptPayload(string $data, string $key, string $iv, string $hmacKey): string
	{
		try {
			// Start with 12 random bytes
			$message = pack('C*', ...((array) unpack('C*', random_bytes(12))));
		} catch (Throwable $ex) {
			throw new Exceptions\Encrypt('Preparing payload header failed', $ex->getCode(), $ex);
		}

		// Add 4 bytes (big endian) of the length of data
		$message .= pack('N', strlen($data));

		$message .= $data;

		$message = $message . str_repeat(chr(0), 16 - (strlen($message) % 16));

		// Encrypt the payload
		$cipherText = openssl_encrypt(
			$message,
			'AES-128-CBC',
			$key,
			OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
			$iv,
		);

		if ($cipherText === false) {
			throw new Exceptions\Encrypt('Payload could not be encrypted');
		}

		// Compute HMAC-SHA-256
		$sig = hash_hmac('sha256', $cipherText, $hmacKey, true);

		// Concat HMAC with AES encrypted payload
		return base64_encode($cipherText . $sig);
	}

	/**
	 * @throws Exceptions\Decrypt
	 */
	public static function decryptPayload(string $data, string $key, string $iv, string $hmacKey): string
	{
		$decodedWithSignature = base64_decode($data, true);

		if ($decodedWithSignature === false) {
			throw new Exceptions\Decrypt('Payload could not be decoded');
		}

		$decoded = substr($decodedWithSignature, 0, -self::SIGNATURE_BYTES_LENGTH);
		$signature = substr($decodedWithSignature, -self::SIGNATURE_BYTES_LENGTH);

		$calculatedSignature = hash_hmac('sha256', $decoded, $hmacKey, true);

		if ($signature !== $calculatedSignature) {
			throw new Exceptions\Decrypt('Payload could not be decrypted. Signatures are different');
		}

		$result = openssl_decrypt(
			$decoded,
			'AES-128-CBC',
			$key,
			OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
			$iv,
		);

		if ($result === false) {
			throw new Exceptions\Decrypt('Payload could not be decrypted');
		}

		$decrypted = unpack('C*', $result);

		if ($decrypted === false) {
			throw new Exceptions\Decrypt('Payload could not be decrypted');
		}

		$decrypted = array_values($decrypted);

		$message = [];

		// The valid decrypted data starts at byte offset 16
		for ($i = 16; $i < count($decrypted); $i++) {
			// Strip ending
			if ($decrypted[$i] === 0) {
				break;
			}

			$message[] = $decrypted[$i];
		}

		return pack('C*', ...$message);
	}

	/**
	 * @throws MetadataExceptions\InvalidState
	 */
	public static function transformValueFromDevice(
		MetadataTypes\DataType $dataType,
		// phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
		MetadataValueObjects\StringEnumFormat|MetadataValueObjects\NumberRangeFormat|MetadataValueObjects\CombinedEnumFormat|MetadataValueObjects\EquationFormat|null $format,
		string|int|float|bool|null $value,
	): float|int|string|bool|MetadataTypes\ButtonPayload|MetadataTypes\SwitchPayload|DateTimeInterface|null
	{
		if ($value === null) {
			return null;
		}

		if ($dataType->equalsValue(MetadataTypes\DataType::DATA_TYPE_STRING)) {
			return strval($value);
		}

		if ($dataType->equalsValue(MetadataTypes\DataType::DATA_TYPE_BOOLEAN)) {
			return is_bool($value) ? $value : boolval($value);
		}

		if ($dataType->equalsValue(MetadataTypes\DataType::DATA_TYPE_FLOAT)) {
			$floatValue = floatval($value);

			if ($format instanceof MetadataValueObjects\NumberRangeFormat) {
				if ($format->getMin() !== null && $format->getMin() > $floatValue) {
					return null;
				}

				if ($format->getMax() !== null && $format->getMax() < $floatValue) {
					return null;
				}
			}

			return $floatValue;
		}

		if (
			$dataType->equalsValue(MetadataTypes\DataType::DATA_TYPE_UCHAR)
			|| $dataType->equalsValue(MetadataTypes\DataType::DATA_TYPE_CHAR)
			|| $dataType->equalsValue(MetadataTypes\DataType::DATA_TYPE_USHORT)
			|| $dataType->equalsValue(MetadataTypes\DataType::DATA_TYPE_SHORT)
			|| $dataType->equalsValue(MetadataTypes\DataType::DATA_TYPE_UINT)
			|| $dataType->equalsValue(MetadataTypes\DataType::DATA_TYPE_INT)
		) {
			$intValue = intval($value);

			if ($format instanceof MetadataValueObjects\NumberRangeFormat) {
				if ($format->getMin() !== null && $format->getMin() > $intValue) {
					return null;
				}

				if ($format->getMax() !== null && $format->getMax() < $intValue) {
					return null;
				}
			}

			return $intValue;
		}

		if ($dataType->equalsValue(MetadataTypes\DataType::DATA_TYPE_SWITCH)) {
			if ($format instanceof MetadataValueObjects\StringEnumFormat) {
				$filtered = array_values(array_filter(
					$format->getItems(),
					static fn (string $item): bool => Utils\Strings::lower(strval($value)) === $item,
				));

				if (count($filtered) === 1) {
					return MetadataTypes\SwitchPayload::isValidValue(strval($value))
						? MetadataTypes\SwitchPayload::get(
							strval($value),
						)
						: null;
				}

				return null;
			} elseif ($format instanceof MetadataValueObjects\CombinedEnumFormat) {
				$filtered = array_values(array_filter(
					$format->getItems(),
					static fn (array $item): bool => $item[1] !== null
						&& Utils\Strings::lower(strval($item[1]->getValue())) === Utils\Strings::lower(
							strval($value),
						),
				));

				if (
					count($filtered) === 1
					&& $filtered[0][0] instanceof MetadataValueObjects\CombinedEnumFormatItem
				) {
					return MetadataTypes\SwitchPayload::isValidValue(strval($filtered[0][0]->getValue()))
						? MetadataTypes\SwitchPayload::get(
							strval($filtered[0][0]->getValue()),
						)
						: null;
				}

				return null;
			}
		}

		if ($dataType->equalsValue(MetadataTypes\DataType::DATA_TYPE_BUTTON)) {
			if ($format instanceof MetadataValueObjects\StringEnumFormat) {
				$filtered = array_values(array_filter(
					$format->getItems(),
					static fn (string $item): bool => Utils\Strings::lower(strval($value)) === $item,
				));

				if (count($filtered) === 1) {
					return MetadataTypes\ButtonPayload::isValidValue(strval($value))
						? MetadataTypes\ButtonPayload::get(
							strval($value),
						)
						: null;
				}

				return null;
			} elseif ($format instanceof MetadataValueObjects\CombinedEnumFormat) {
				$filtered = array_values(array_filter(
					$format->getItems(),
					static fn (array $item): bool => $item[1] !== null
						&& Utils\Strings::lower(strval($item[1]->getValue())) === Utils\Strings::lower(
							strval($value),
						),
				));

				if (
					count($filtered) === 1
					&& $filtered[0][0] instanceof MetadataValueObjects\CombinedEnumFormatItem
				) {
					return MetadataTypes\ButtonPayload::isValidValue(strval($filtered[0][0]->getValue()))
						? MetadataTypes\ButtonPayload::get(
							strval($filtered[0][0]->getValue()),
						)
						: null;
				}

				return null;
			}
		}

		if ($dataType->equalsValue(MetadataTypes\DataType::DATA_TYPE_ENUM)) {
			if ($format instanceof MetadataValueObjects\StringEnumFormat) {
				$filtered = array_values(array_filter(
					$format->getItems(),
					static fn (string $item): bool => Utils\Strings::lower(strval($value)) === $item,
				));

				if (count($filtered) === 1) {
					return strval($value);
				}

				return null;
			} elseif ($format instanceof MetadataValueObjects\CombinedEnumFormat) {
				$filtered = array_values(array_filter(
					$format->getItems(),
					static fn (array $item): bool => $item[1] !== null
						&& Utils\Strings::lower(strval($item[1]->getValue())) === Utils\Strings::lower(
							strval($value),
						),
				));

				if (
					count($filtered) === 1
					&& $filtered[0][0] instanceof MetadataValueObjects\CombinedEnumFormatItem
				) {
					return strval($filtered[0][0]->getValue());
				}

				return null;
			}
		}

		if ($dataType->equalsValue(MetadataTypes\DataType::DATA_TYPE_DATETIME)) {
			$value = Utils\DateTime::createFromFormat(DateTimeInterface::RFC3339_EXTENDED, strval($value));

			return $value === false ? null : $value;
		}

		return null;
	}

	/**
	 * @throws MetadataExceptions\InvalidState
	 */
	public static function transformValueToDevice(
		MetadataTypes\DataType $dataType,
		// phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
		MetadataValueObjects\StringEnumFormat|MetadataValueObjects\NumberRangeFormat|MetadataValueObjects\CombinedEnumFormat|MetadataValueObjects\EquationFormat|null $format,
		bool|float|int|string|DateTimeInterface|MetadataTypes\ButtonPayload|MetadataTypes\SwitchPayload|MetadataTypes\CoverPayload|null $value,
	): string|int|float|bool|null
	{
		if ($value === null) {
			return null;
		}

		if ($dataType->equalsValue(MetadataTypes\DataType::DATA_TYPE_BOOLEAN)) {
			if (is_bool($value)) {
				return $value;
			}

			return null;
		}

		if ($dataType->equalsValue(MetadataTypes\DataType::DATA_TYPE_SWITCH)) {
			if ($format instanceof MetadataValueObjects\StringEnumFormat) {
				$filtered = array_values(array_filter(
					$format->getItems(),
					static fn (string $item): bool => Utils\Strings::lower(
						strval(DevicesUtilities\ValueHelper::flattenValue($value)),
					) === $item,
				));

				if (count($filtered) === 1) {
					return strval(DevicesUtilities\ValueHelper::flattenValue($value));
				}

				return null;
			} elseif ($format instanceof MetadataValueObjects\CombinedEnumFormat) {
				$filtered = array_values(array_filter(
					$format->getItems(),
					static fn (array $item): bool => $item[0] !== null
						&& Utils\Strings::lower(strval($item[0]->getValue())) === Utils\Strings::lower(
							strval(DevicesUtilities\ValueHelper::flattenValue($value)),
						),
				));

				if (
					count($filtered) === 1
					&& $filtered[0][2] instanceof MetadataValueObjects\CombinedEnumFormatItem
				) {
					return strval($filtered[0][2]->getValue());
				}

				return null;
			}
		}

		if ($dataType->equalsValue(MetadataTypes\DataType::DATA_TYPE_BUTTON)) {
			if ($format instanceof MetadataValueObjects\StringEnumFormat) {
				$filtered = array_values(array_filter(
					$format->getItems(),
					static fn (string $item): bool => Utils\Strings::lower(
						strval(DevicesUtilities\ValueHelper::flattenValue($value)),
					) === $item,
				));

				if (count($filtered) === 1) {
					return strval(DevicesUtilities\ValueHelper::flattenValue($value));
				}

				return null;
			} elseif ($format instanceof MetadataValueObjects\CombinedEnumFormat) {
				$filtered = array_values(array_filter(
					$format->getItems(),
					static fn (array $item): bool => $item[0] !== null
						&& Utils\Strings::lower(strval($item[0]->getValue())) === Utils\Strings::lower(
							strval(DevicesUtilities\ValueHelper::flattenValue($value)),
						),
				));

				if (
					count($filtered) === 1
					&& $filtered[0][2] instanceof MetadataValueObjects\CombinedEnumFormatItem
				) {
					return strval($filtered[0][2]->getValue());
				}

				return null;
			}
		}

		if ($dataType->equalsValue(MetadataTypes\DataType::DATA_TYPE_ENUM)) {
			if ($format instanceof MetadataValueObjects\StringEnumFormat) {
				$filtered = array_values(array_filter(
					$format->getItems(),
					static fn (string $item): bool => Utils\Strings::lower(
						strval(DevicesUtilities\ValueHelper::flattenValue($value)),
					) === $item,
				));

				if (count($filtered) === 1) {
					return strval(DevicesUtilities\ValueHelper::flattenValue($value));
				}

				return null;
			} elseif ($format instanceof MetadataValueObjects\CombinedEnumFormat) {
				$filtered = array_values(array_filter(
					$format->getItems(),
					static fn (array $item): bool => $item[0] !== null
						&& Utils\Strings::lower(strval($item[0]->getValue())) === Utils\Strings::lower(
							strval(DevicesUtilities\ValueHelper::flattenValue($value)),
						),
				));

				if (
					count($filtered) === 1
					&& $filtered[0][2] instanceof MetadataValueObjects\CombinedEnumFormatItem
				) {
					return strval($filtered[0][2]->getValue());
				}

				return null;
			}
		}

		return DevicesUtilities\ValueHelper::flattenValue($value);
	}

}
