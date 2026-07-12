<?php

declare(strict_types=1);

namespace Blackout\Qbittorrent\DTO;

use BackedEnum;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;

use Blackout\Qbittorrent\Client;
use Blackout\Qbittorrent\Attribute\Relation;

abstract class Base implements \JsonSerializable
{
	protected array $_relations = [];

	public function __construct(
		array $data = [],
		protected ?Client $qbittorrent = null,
	) {
		$this->hydrate($data);
	}

	final protected function hydrate(array $data): void
	{
		$reflection = new ReflectionClass($this);

		foreach ($reflection->getProperties() as $property)
		{
			if (!$property->isPublic())
			{
				continue;
			}

			// skip lazy relation properties
			if ($property->hasHook(\PropertyHookType::Get))
			{
				continue;
			}

			$name = $property->getName();

			if (!array_key_exists($name, $data))
			{
				continue;
			}

			$property->setValue(
				$this,
				$this->castProperty(
					$property,
					$data[$name],
				),
			);
		}
	}

	final protected function castProperty(
		ReflectionProperty $property,
		mixed $value,
	): mixed
	{
		if ($value === null)
		{
			return null;
		}

		$type = $property->getType();

		if (!$type instanceof ReflectionNamedType)
		{
			return $value;
		}

		$typeName = $type->getName();

		// enum
		if (
			enum_exists($typeName)
			&& is_subclass_of($typeName, BackedEnum::class)
		) {
			return $typeName::from($value);
		}

		// nested data object
		if (is_subclass_of($typeName, self::class))
		{
			return new $typeName(
				$value,
				$this->qbittorrent,
			);
		}

		return match ($typeName)
		{
			'int' => (int) $value,
			'float' => (float) $value,
			'bool' => (bool) $value,
			'string' => (string) $value,
			'array' => (array) $value,
			default => $value,
		};
	}

	protected function relation(
		string $property,
	): mixed
	{
		if (array_key_exists($property, $this->_relations))
		{
			return $this->_relations[$property];
		}

		$reflection = new ReflectionProperty(
			$this,
			$property,
		);

		$attributes = $reflection->getAttributes(
			Relation::class,
		);

		if ($attributes === [])
		{
			throw new RuntimeException(
				"Missing relation attribute [$property]",
			);
		}

		$relation = $attributes[0]->newInstance();

		$data = $this->qbittorrent->{$relation->method}(
			$this->hash,
		);

		$class = $relation->class;

		return $this->_relations[$property] = array_map(
			fn ($v) => $v instanceof $class ? $v : new $class(
				$v,
				$this->qbittorrent,
			),
			$data,
		);
	}

	public function jsonSerialize(): mixed
	{
		$data = [];

		$reflection = new ReflectionClass($this);

		foreach ($reflection->getProperties() as $property)
		{
			if (
				$property
					->getDeclaringClass()
					->getName() !== static::class
			) {
				continue;
			}

			if (!$property->isPublic())
			{
				continue;
			}

			$name = $property->getName();

			// hooked/lazy property
			if ($property->hasHook(\PropertyHookType::Get))
			{
				// only serialize loaded relations
				if (!array_key_exists($name, $this->_relations))
				{
					continue;
				}

				$data[$name] =
					$this->_relations[$name];

				continue;
			}

			if (!$property->isInitialized($this))
			{
				continue;
			}

			$data[$name] =
				$property->getValue($this);
		}

		return $data;
	}

	public function __debugInfo(): ?array
	{
		return $this->jsonSerialize();
	}
}
