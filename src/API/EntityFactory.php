<?php declare(strict_types = 1);

/**
 * EntityFactory.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 * @since          1.0.0
 *
 * @date           25.06.23
 */

namespace FastyBird\Connector\Viera\API;

use FastyBird\Connector\Viera\Entities;
use FastyBird\Connector\Viera\Exceptions;
use phpDocumentor;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionUnionType;
use Reflector;
use SimpleXMLElement;
use stdClass;
use Throwable;
use function array_combine;
use function array_keys;
use function array_merge;
use function assert;
use function call_user_func_array;
use function class_exists;
use function get_object_vars;
use function in_array;
use function is_callable;
use function is_string;
use function is_subclass_of;
use function preg_replace_callback;
use function property_exists;
use function strtolower;
use function strtoupper;
use function strval;
use function trim;
use function ucfirst;

/**
 * API data entity factory
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class EntityFactory
{

	/**
	 * @param class-string<T> $entityClass
	 *
	 * @template T of Entities\API\Entity
	 *
	 * @return T
	 *
	 * @throws Exceptions\InvalidState
	 */
	public static function build(
		string $entityClass,
		SimpleXMLElement $data,
	): Entities\API\Entity
	{
		if (!class_exists($entityClass)) {
			throw new Exceptions\InvalidState('Transformer could not be created. Class could not be found');
		}

		$decoded = self::convertKeys($data);
		$decoded = self::convertToObject($decoded);

		try {
			$rc = new ReflectionClass($entityClass);

			$constructor = $rc->getConstructor();

			$entity = $constructor !== null
				? $rc->newInstanceArgs(
					self::autowireArguments($constructor, $decoded),
				)
				: new $entityClass();
		} catch (Throwable $ex) {
			throw new Exceptions\InvalidState('Transformer could not be created: ' . $ex->getMessage(), 0, $ex);
		}

		$properties = self::getProperties($rc);

		foreach ($properties as $rp) {
			$varAnnotation = self::parseVarAnnotation($rp);

			if (
				in_array($rp->getName(), array_keys(get_object_vars($decoded)), true) === true
				&& property_exists($decoded, $rp->getName())
			) {
				$value = $decoded->{$rp->getName()};

				$methodName = 'set' . ucfirst($rp->getName());

				if ($varAnnotation === 'int') {
					$value = (int) $value;
				} elseif ($varAnnotation === 'float') {
					$value = (float) $value;
				} elseif ($varAnnotation === 'bool') {
					$value = (bool) $value;
				} elseif ($varAnnotation === 'string') {
					$value = (string) $value;
				}

				try {
					$rm = new ReflectionMethod($entityClass, $methodName);

					if ($rm->isPublic()) {
						$callback = [$entity, $methodName];

						// Try to call entity setter
						if (is_callable($callback)) {
							call_user_func_array($callback, [$value]);
						}
					}
				} catch (ReflectionException) {
					continue;
				} catch (Throwable $ex) {
					throw new Exceptions\InvalidState('Transformer could not be created: ' . $ex->getMessage(), 0, $ex);
				}
			}
		}

		return $entity;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function convertKeys(SimpleXMLElement $data): array
	{
		$keys = preg_replace_callback(
			'/_(.)/',
			static fn (array $m): string => strtoupper($m[1]),
			array_keys((array) $data),
		);

		if ($keys === null) {
			return [];
		}

		return array_combine($keys, (array) $data);
	}

	/**
	 * This method was inspired by same method in Nette framework
	 *
	 * @return array<int, mixed>
	 *
	 * @throws Exceptions\InvalidState
	 * @throws ReflectionException
	 */
	private static function autowireArguments(
		ReflectionMethod $method,
		stdClass $decoded,
	): array
	{
		$res = [];

		foreach ($method->getParameters() as $num => $parameter) {
			$parameterName = $parameter->getName();
			$parameterTypes = self::getParameterTypes($parameter);

			if (
				!$parameter->isVariadic()
				&& in_array($parameterName, array_keys(get_object_vars($decoded)), true) === true
			) {
				$parameterValue = $decoded->{$parameterName};

				foreach ($parameterTypes as $parameterType) {
					if ($parameterType === 'array' && is_string($method->getDocComment())) {
						$factory = phpDocumentor\Reflection\DocBlockFactory::createInstance();

						$rc = new ReflectionClass(Entities\API\Entity::class);

						$docblock = $factory->create(
							$method->getDocComment(),
							new phpDocumentor\Reflection\Types\Context($rc->getNamespaceName()),
						);

						foreach ($docblock->getTags() as $tag) {
							if (!$tag instanceof phpDocumentor\Reflection\DocBlock\Tags\Param) {
								continue;
							}

							$tagType = $tag->getType();

							if (
								$tag->getVariableName() === $parameterName
								&& $tagType instanceof phpDocumentor\Reflection\Types\Array_
							) {
								$arrayType = strval($tagType->getValueType());

								$subRes = [];

								if ($parameterValue instanceof SimpleXMLElement) {
									foreach ($parameterValue as $subParameterValue) {
										assert(is_subclass_of($arrayType, Entities\API\Entity::class));

										$subRes[] = self::build($arrayType, $subParameterValue);
									}
								}

								$res[$num] = $subRes;
							}
						}

						break;
					} elseif (
						class_exists($parameterType, false)
						&& is_subclass_of($parameterType, Entities\API\Entity::class)
						&& $parameterValue instanceof SimpleXMLElement
					) {
						$res[$num] = self::build($parameterType, $parameterValue);

						break;
					}

					$res[$num] = $parameterValue;
				}
			} elseif ($parameterName === 'id' && property_exists($decoded, 'id')) {
				$res[$num] = $decoded->id;

			} elseif (
				(
					$parameterTypes !== []
					&& $parameter->allowsNull()
				)
				|| $parameter->isOptional()
				|| $parameter->isDefaultValueAvailable()
			) {
				// !optional + defaultAvailable = func($a = NULL, $b) since 5.4.7
				// optional + !defaultAvailable = i.e. Exception::__construct, mysqli::mysqli, ...
				$res[$num] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
			}
		}

		return $res;
	}

	/**
	 * @return array<string>
	 */
	private static function getParameterTypes(ReflectionParameter $param): array
	{
		if ($param->hasType()) {
			$rt = $param->getType();

			if ($rt instanceof ReflectionNamedType) {
				$type = $rt->getName();

				return [strtolower(
					$type,
				) === 'self' && $param->getDeclaringClass() !== null ? $param->getDeclaringClass()
					->getName() : $type];
			} elseif ($rt instanceof ReflectionUnionType) {
				$types = [];

				foreach ($rt->getTypes() as $subType) {
					if ($subType instanceof ReflectionNamedType) {
						$type = $subType->getName();

						$types[] = strtolower(
							$type,
						) === 'self' && $param->getDeclaringClass() !== null ? $param->getDeclaringClass()
							->getName() : $type;
					}
				}

				return $types;
			}
		}

		return [];
	}

	/**
	 * @return array<ReflectionProperty>
	 */
	private static function getProperties(Reflector $rc): array
	{
		if (!$rc instanceof ReflectionClass) {
			return [];
		}

		$properties = [];

		foreach ($rc->getProperties() as $rcProperty) {
			$properties[] = $rcProperty;
		}

		if ($rc->getParentClass() !== false) {
			$properties = array_merge($properties, self::getProperties($rc->getParentClass()));
		}

		return $properties;
	}

	private static function parseVarAnnotation(ReflectionProperty $rp): string|null
	{
		if ($rp->getDocComment() === false) {
			return null;
		}

		$factory = phpDocumentor\Reflection\DocBlockFactory::createInstance();
		$docblock = $factory->create($rp->getDocComment());

		foreach ($docblock->getTags() as $tag) {
			if ($tag->getName() === 'var') {
				return trim((string) $tag);
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $array
	 */
	private static function convertToObject(array $array): stdClass
	{
		$converted = new stdClass();

		foreach ($array as $key => $value) {
			$converted->{$key} = $value;
		}

		return $converted;
	}

}
