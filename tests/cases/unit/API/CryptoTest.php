<?php declare(strict_types = 1);

namespace FastyBird\Connector\Viera\Tests\Cases\Unit\API;

use FastyBird\Connector\Viera\API;
use FastyBird\Connector\Viera\Exceptions;
use PHPUnit\Framework\TestCase;
use function array_fill;
use function array_values;
use function assert;
use function base64_decode;
use function is_array;
use function pack;
use function strval;
use function unpack;

final class CryptoTest extends TestCase
{

	/**
	 * @throws Exceptions\Decrypt
	 * @throws Exceptions\Encrypt
	 */
	public function testEncodeDecode(): void
	{
		$challengeKey = 'vdj1PiHp9lJ3OhhzSbqNRw==';

		$iv = unpack('C*', strval(base64_decode($challengeKey, true)));
		assert(is_array($iv));
		/** @var array<int> $iv */
		$iv = array_values($iv);

		$key = array_fill(0, 16, 0);
		$hmacKey = array_fill(0, 32, 0);

		for ($i = $k = 0; $k < 16; $i = $k += 4) {
			$key[$i] = ~$iv[$i + 3] & 0xff;
			$key[$i + 1] = ~$iv[$i + 2] & 0xff;
			$key[$i + 2] = ~$iv[$i + 1] & 0xff;
			$key[$i + 3] = ~$iv[$i] & 0xff;
		}

		$hmacKeyMaskVals = [
			0x15, 0xc9, 0x5a, 0xc2, 0xb0, 0x8a, 0xa7, 0xeb, 0x4e, 0x22, 0x8f, 0x81, 0x1e, 0x34, 0xd0,
			0x4f, 0xa5, 0x4b, 0xa7, 0xdc, 0xac, 0x98, 0x79, 0xfa, 0x8a, 0xcd, 0xa3, 0xfc, 0x24, 0x4f,
			0x38, 0x54,
		];

		for ($j = $l = 0; $l < 32; $j = $l += 4) {
			$hmacKey[$j] = $hmacKeyMaskVals[$j] ^ $iv[$j + 2 & 0xf];
			$hmacKey[$j + 1] = $hmacKeyMaskVals[$j + 1] ^ $iv[$j + 3 & 0xf];
			$hmacKey[$j + 2] = $hmacKeyMaskVals[$j + 2] ^ $iv[$j & 0xf];
			$hmacKey[$j + 3] = $hmacKeyMaskVals[$j + 3] ^ $iv[$j + 1 & 0xf];
		}

		$iv = pack('C*', ...$iv);
		$key = pack('C*', ...$key);
		$hmacKey = pack('C*', ...$hmacKey);

		$payload = 'test_message_content';

		$encoded = API\Crypto::encryptPayload($payload, $key, $iv, $hmacKey);

		$result = API\Crypto::decryptPayload($encoded, $key, $iv, $hmacKey);

		self::assertSame($payload, $result);
	}

}
