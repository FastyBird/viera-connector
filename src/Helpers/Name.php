<?php declare(strict_types = 1);

/**
 * Name.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Helpers
 * @since          1.0.0
 *
 * @date           21.06.23
 */

namespace FastyBird\Connector\Viera\Helpers;

use function array_map;
use function explode;
use function iconv;
use function implode;
use function in_array;
use function is_string;
use function mb_convert_case;
use function preg_replace;
use function str_replace;
use function strtolower;
use function strtoupper;
use function strval;
use function ucfirst;
use const MB_CASE_TITLE;

/**
 * Useful name helpers
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Helpers
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class Name
{

	public static function createName(string $identifier): string|null
	{
		$transformed = preg_replace('/(?<!^)[A-Z]/', '_$0', $identifier);

		if (!is_string($transformed)) {
			return null;
		}

		$transformed = strtolower($transformed);
		$transformed = ucfirst(strtolower(str_replace('_', ' ', $transformed)));
		$transformed = explode(' ', $transformed);
		$transformed = array_map(static function (string $part): string {
			if (in_array(strtolower($part), ['ip', 'mac', 'id', 'uid'], true)) {
				return strtoupper($part);
			}

			return $part;
		}, $transformed);

		return ucfirst(implode(' ', $transformed));
	}

	public static function sanitizeEnumName(string $name): string
	{
		return str_replace(
			' ',
			'',
			strval(iconv(
				'utf-8',
				'ascii//TRANSLIT',
				strtolower(
					strval(preg_replace(
						'/(?<!^)[A-Z]/',
						'_$0',
						mb_convert_case($name, MB_CASE_TITLE, 'UTF-8'),
					)),
				),
			)),
		);
	}

}
