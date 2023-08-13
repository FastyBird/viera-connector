<?php declare(strict_types = 1);

/**
 * Crypto.php
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

use FastyBird\Connector\Viera\Exceptions;
use Nette;
use Throwable;
use function array_values;
use function base64_decode;
use function base64_encode;
use function chr;
use function count;
use function hash_hmac;
use function openssl_decrypt;
use function openssl_encrypt;
use function pack;
use function random_bytes;
use function str_repeat;
use function strlen;
use function substr;
use function unpack;
use const OPENSSL_RAW_DATA;
use const OPENSSL_ZERO_PADDING;

/**
 * API crypto transformers
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class Crypto
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

}
