<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Bridges\ApplicationLatte;

use Latte;
use Nette\Application\UI;
use Nette\Utils\FileSystem;
use Nette\Utils\Helpers;
use Nette\Utils\Type;
use Nette\Utils\Validators;
use function is_object;


/**
 * On-the-fly template class generator.
 */
final class TemplateGenerator extends Template
{
	private string $className;
	private ?self $parent = null;
	private array $params = [];

	/** @var array<string, array{Type, ?string}> */
	private array $properties = [];


	public function __construct(
		Latte\Engine $latte,
		?string $className = null,
		?UI\Control $control = null,
	) {
		parent::__construct($latte);

		$this->className = $className && $className !== DefaultTemplate::class
			? $className
			: preg_replace('#Control|Presenter$#', '', $control::class) . 'Template';

		if (!class_exists($this->className)) {
			$this->createTemplateClass($control);
			$this->updateControlPhpDoc($control);
		}
		$this->loadTemplateClass();
	}


	public function render(?string $file = null, array $params = []): void
	{
		$this->updateTemplate($file ?? $this->getFile());
		$this->getLatte()->render($file ?? $this->getFile(), $params + $this->params);
	}


	public function addDefaultVariable(string $name, mixed $value): void
	{
		$owner = $this->findPropertyOwner($name) ?? $this;
		if (!isset($owner->properties[$name])) {
			if (is_object($value)) {
				if (PHP_VERSION_ID >= 80400) {
					$rc = new \ReflectionClass($value);
					$rc->initializeLazyObject($value);
					$value = ($rc)->newLazyProxy(fn() => $this->ensureProperty($name, $value));
				}

			} else {
				$value = new class (fn() => $this->ensureProperty($name, $value)) implements \IteratorAggregate {
					public function __construct(
						private \Closure $cb,
					) {
					}


					public function __toString(): string
					{
						return ($this->cb)(); // basePath & baseUrl
					}


					public function getIterator(): \Traversable
					{
						yield from ($this->cb)(); // flashes
					}
				};
			}
		}

		$this->params[$name] = $value;
	}


	private function ensureProperty(string $name, mixed $value): mixed
	{
		[$declaredType, $phpDoc] = $this->properties[$name] ?? null;
		$actualType = Type::fromValue($value);
		// TODO: support for generics
		if (!$declaredType) {
			$this->properties[$name] = [$actualType, null];
			$this->updateTemplateClass($name);
		} elseif (!$declaredType->allows($actualType)) {
			$this->properties[$name] = [$declaredType->with($actualType), $phpDoc];
			$this->updateTemplateClass($name);
		}
		return $value;
	}


	private function findPropertyOwner(string $name): ?self
	{
		return match (true) {
			isset($this->properties[$name]) => $this,
			$this->parent !== null => $this->parent->findPropertyOwner($name),
			default => null,
		};
	}


	/********************* generator ****************d*g**/


	private function createTemplateClass(UI\Control $control): void
	{
		[$namespace, $shortName] = Helpers::splitClassName($this->className);
		$namespaceCode = $namespace ? PHP_EOL . "namespace $namespace;" . PHP_EOL : '';
		$fileName = dirname((new \ReflectionClass($control))->getFileName()) . '/' . $shortName . '.php';
		file_put_contents($fileName, <<<XX
			<?php

			declare(strict_types=1);
			$namespaceCode
			use Nette\\Bridges\\ApplicationLatte\\Template;

			class $shortName extends Template
			{
			}
			XX);
		require $fileName;
	}


	private function loadTemplateClass(): void
	{
		$rc = new \ReflectionClass($this->className);
		foreach ($rc->getProperties() as $prop) {
			if ($prop->getDeclaringClass() == $rc) { // intentionally ==
				$this->properties[$prop->getName()] = [Type::fromReflection($prop),	$prop->getDocComment() ?: null];
			}
		}

		$parent = $rc->getParentClass()->getName();
		if ($parent !== Template::class) {
			$this->parent = new self($this->getLatte(), $parent);
		}
	}


	private function updateTemplateClass(string $name): void
	{
		$rc = new \ReflectionClass($this->className);
		$content = FileSystem::read($rc->getFileName());
		[$type, $phpDoc] = $this->properties[$name];
		$type = $this->relativizeType((string) $type, $rc->getNamespaceName());
		$declaration = ($phpDoc ? $phpDoc . PHP_EOL : '') . "\tpublic $type \$$name";

		// replace
		$content = preg_replace(
			'/^(?>\s*\/\*\*.*?\*\/)?\s*public\s+[^$;]*\s*\$' . $name . '\b/m',
			$declaration,
			$content,
			count: $count,
		);
		if (!$count) {
			if ($pos = strrpos($content, '}')) { // or append
				$content = substr_replace($content, $declaration . ';' . PHP_EOL, $pos, 0);
			} else {
				throw new \RuntimeException("Cannot update class file for {$this->className}, invalid syntax.");
			}
		}
		file_put_contents($rc->getFileName(), $content);
	}


	private function relativizeType(string $type, string $namespace): string
	{
		return preg_replace_callback(
			'~[\w\x7f-\xff\\\]+~',
			function ($m) use ($namespace) {
				$name = $m[0];
				return match (true) {
					Validators::isBuiltinType($name) => $name,
					str_starts_with($name, $namespace . '\\') => substr($name, strlen($namespace) + 1),
					default => '\\' . $name,
				};
			},
			$type,
		);
	}


	private function updateControlPhpDoc(UI\Control $control): void
	{
		$rc = new \ReflectionClass($control);
		$content = FileSystem::read($rc->getFileName());
		$doc = $rc->getDocComment();
		$nl = PHP_EOL;
		$annotation = '* @property-read ' . Helpers::splitClassName($this->className)[1] . ' $template';

		if (!$doc) {
			$content = preg_replace(
				'/^((final\s+)?class\s+' . $rc->getShortName() . ')/m',
				"/**$nl $annotation$nl */$nl$1",
				$content,
			);
		} elseif (!preg_match('/@property(-read)?\s+.*\$template/', $doc)) {
			$newDoc = preg_replace('~(\s*)\*/\s*$~', "$1$annotation$0", $doc, 1);
			$content = str_replace($doc, $newDoc, $content);
		} else {
			return;
		}

		file_put_contents($rc->getFileName(), $content);
	}


	private function updateTemplate(string $file): void
	{
		$content = FileSystem::read($file);
		if (!str_contains($content, '{templateType ')) {
			$content = '{templateType ' . $this->className . '}' . PHP_EOL . $content;
			file_put_contents($file, $content);
		}
	}


	/********************* template parameters ****************d*g**/


	public function getParameters(): array
	{
		return $this->params;
	}


	public function __set($name, $value): void
	{
		($this->findPropertyOwner($name) ?? $this)->ensureProperty($name, $value);
		$this->params[$name] = $value;
	}


	public function &__get($name)
	{
		if (!array_key_exists($name, $this->params)) {
			trigger_error("The variable '$name' does not exist in template.", E_USER_WARNING);
		}

		return $this->params[$name];
	}


	public function __isset($name)
	{
		return isset($this->params[$name]);
	}


	public function __unset(string $name): void
	{
		unset($this->params[$name]);
	}
}
