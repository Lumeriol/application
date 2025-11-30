<?php

/**
 * Test: TemplateGenerator
 */

declare(strict_types=1);

use Nette\Application\UI;
use Nette\Bridges\ApplicationLatte\TemplateGenerator;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


function createTempControl(string $className, ?string $content = null): UI\Control
{
	$fileName = getTempDir() . "/$className.php";
	file_put_contents($fileName, $content ?? <<<XX
		<?php

		declare(strict_types=1);

		class $className extends Nette\\Application\\UI\\Control
		{
		}
		XX);
	require $fileName;
	return new $className;
}


test('generates template class name from Control', function () {
	$control = createTempControl('TestControl');
	$latte = new Latte\Engine;

	$generator = new TemplateGenerator($latte, null, $control);

	Assert::true(file_exists(getTempDir() . "/TestTemplate.php"));
});


test('generates template class name from Presenter', function () {
	$className = 'ProductPresenter';
	$fileName = getTempDir() . "/$className.php";
	file_put_contents($fileName, <<<XX
		<?php

		declare(strict_types=1);

		class $className extends Nette\\Application\\UI\\Presenter
		{
		}
		XX);
	require $fileName;
	$presenter = new $className;

	$latte = new Latte\Engine;

	$generator = new TemplateGenerator($latte, null, $presenter);

	Assert::true(file_exists(getTempDir() . "/ProductTemplate.php"));
});


test('creates template class file with proper structure', function () {
	$control = createTempControl('MyControl');
	$latte = new Latte\Engine;

	$generator = new TemplateGenerator($latte, null, $control);

	$content = file_get_contents(getTempDir() . "/MyTemplate.php");

	Assert::contains(<<<'XX'
		declare(strict_types=1);
		
		use Nette\Bridges\ApplicationLatte\Template;
		
		class MyTemplate extends Template
		XX, $content);
});


test('tracks property type on first assignment', function () {
	$control = createTempControl('PropControl');
	$latte = new Latte\Engine;

	$generator = new TemplateGenerator($latte, null, $control);

	$generator->title = 'Hello World';

	$content = file_get_contents(getTempDir() . "/PropTemplate.php");

	Assert::contains('public string $title', $content);
});


test('updates property type when union type is needed', function () {
	$control = createTempControl('UnionControl');
	$latte = new Latte\Engine;

	$generator = new TemplateGenerator($latte, null, $control);

	$generator->value = 'string';
	$generator->value = 123;

	$content = file_get_contents(getTempDir() . "/UnionTemplate.php");

	Assert::contains('string|int', $content);
});


test('tracks object property types', function () {
	$control = createTempControl('ObjectControl');
	$latte = new Latte\Engine;

	$generator = new TemplateGenerator($latte, null, $control);

	$generator->user = new stdClass;

	$content = file_get_contents(getTempDir() . "/ObjectTemplate.php");

	Assert::contains('stdClass', $content);
});


test('tracks nullable property types', function () {
	$control = createTempControl('NullableControl');
	$latte = new Latte\Engine;

	$generator = new TemplateGenerator($latte, null, $control);

	$generator->optional = null;

	$content = file_get_contents(getTempDir() . "/NullableTemplate.php");

	Assert::contains('null', $content);
});


test('uses existing template class if it already exists', function () {
	$className = 'PreExistingControl';
	$control = createTempControl($className);

	$templateClassName = 'PreExistingTemplate';
	$templateFile = getTempDir() . "/$templateClassName.php";

	file_put_contents($templateFile, <<<XX
		<?php

		declare(strict_types=1);

		class $templateClassName extends Nette\\Bridges\\ApplicationLatte\\Template
		{
			public string \$predefinedProperty;
		}
		XX);

	require $templateFile;

	$latte = new Latte\Engine;

	$generator = new TemplateGenerator($latte, $templateClassName, $control);

	// The class should load existing properties
	$generator->predefinedProperty = 'value';

	// Should not create a new file
	$files = glob(getTempDir() . "/$templateClassName*.php");
	Assert::count(1, $files);
});


test('does not track properties in addDefaultVariable', function () {
	$control = createTempControl('DefaultVarControl');
	$latte = new Latte\Engine;

	$generator = new TemplateGenerator($latte, null, $control);

	$generator->addDefaultVariable('baseUrl', '/app');

	$content = file_get_contents(getTempDir() . "/DefaultVarTemplate.php");

	// Default variables should not be added to the template class
	Assert::notContains('$baseUrl', $content);
});


test('relativizes types in same namespace', function () {
	$namespace = 'App\Presentation\Product';

	// Create namespace directory
	$namespaceDir = getTempDir() . "/App/Presentation/Product";
	@mkdir($namespaceDir, 0o777, true);

	$className = 'ProductControl';
	$fileName = "$namespaceDir/$className.php";

	file_put_contents($fileName, <<<XX
		<?php

		declare(strict_types=1);

		namespace $namespace;

		class $className extends \\Nette\\Application\\UI\\Control
		{
		}
		XX);

	require $fileName;

	$fullClassName = "$namespace\\$className";
	$control = new $fullClassName;

	$latte = new Latte\Engine;

	$generator = new TemplateGenerator($latte, null, $control);

	// Create a type from the same namespace
	$typeClassName = 'ProductRow';
	$typeFileName = "$namespaceDir/$typeClassName.php";

	file_put_contents($typeFileName, <<<XX
		<?php

		declare(strict_types=1);

		namespace $namespace;

		class $typeClassName
		{
		}
		XX);

	require $typeFileName;

	$fullTypeClassName = "$namespace\\$typeClassName";
	$generator->product = new $fullTypeClassName;

	$content = file_get_contents("$namespaceDir/ProductTemplate.php");

	// Should use relative name without namespace prefix
	Assert::contains('public ProductRow $product', $content);
	Assert::notContains('public \App\Presentation\Product\ProductRow $product', $content);
});


test('adds leading backslash for types from different namespace', function () {
	$namespace = 'App\Presentation\Admin';

	// Create namespace directory
	$namespaceDir = getTempDir() . "/App/Presentation/Admin";
	@mkdir($namespaceDir, 0o777, true);

	$className = 'AdminControl';
	$fileName = "$namespaceDir/$className.php";

	file_put_contents($fileName, <<<XX
		<?php

		declare(strict_types=1);

		namespace $namespace;

		class $className extends \\Nette\\Application\\UI\\Control
		{
		}
		XX);

	require $fileName;

	$fullClassName = "$namespace\\$className";
	$control = new $fullClassName;

	$latte = new Latte\Engine;

	$generator = new TemplateGenerator($latte, null, $control);
	$generator->date = new DateTime;

	$content = file_get_contents("$namespaceDir/AdminTemplate.php");

	// Should use fully qualified name with leading backslash
	Assert::contains('\DateTime', $content);
});


test('loads phpDoc from existing template class', function () {
	$className = 'PhpDocLoadControl';
	$control = createTempControl($className);

	$templateClassName = 'PhpDocLoadTemplate';
	$templateFile = getTempDir() . "/$templateClassName.php";

	file_put_contents($templateFile, <<<XX
		<?php

		declare(strict_types=1);

		class $templateClassName extends Nette\\Bridges\\ApplicationLatte\\Template
		{
			/** @var string User's full name */
			public string \$fullName;
		}
		XX);

	require $templateFile;

	$latte = new Latte\Engine;

	$generator = new TemplateGenerator($latte, $templateClassName, $control);

	// Assign different type to trigger update
	$generator->fullName = 123;

	$content = file_get_contents($templateFile);

	Assert::contains(<<<XX
		/** @var string User's full name */
			public string|int \$fullName
		XX, $content);
});


test('preserves phpDoc when updating property type', function () {
	$className = 'PhpDocUpdateControl';
	$control = createTempControl($className);

	$templateClassName = 'PhpDocUpdateTemplate';
	$templateFile = getTempDir() . "/$templateClassName.php";

	file_put_contents($templateFile, <<<XX
		<?php

		declare(strict_types=1);

		class $templateClassName extends Nette\\Bridges\\ApplicationLatte\\Template
		{
			/** @var string Product identifier */
			public string \$productId;
		}
		XX);

	require $templateFile;

	$latte = new Latte\Engine;

	$generator = new TemplateGenerator($latte, $templateClassName, $control);

	// Update with different type to trigger union type
	$generator->productId = 'ABC123';
	$generator->productId = 456;

	$content = file_get_contents($templateFile);

	Assert::contains(<<<'XX'
		/** @var string Product identifier */
			public string|int $productId
		XX, $content);
});


test('removes old phpDoc when property is recreated', function () {
	$className = 'PhpDocRemoveControl';
	$control = createTempControl($className);

	$templateClassName = 'PhpDocRemoveTemplate';
	$templateFile = getTempDir() . "/$templateClassName.php";

	file_put_contents($templateFile, <<<XX
		<?php

		declare(strict_types=1);

		class $templateClassName extends Nette\\Bridges\\ApplicationLatte\\Template
		{
			/** @var string Old documentation */
			public string \$value;

			/** @var int Another property */
			public int \$count;
		}
		XX);

	require $templateFile;

	$latte = new Latte\Engine;

	$generator = new TemplateGenerator($latte, $templateClassName, $control);

	// Update value property with different type
	$generator->value = 42;

	$content = file_get_contents($templateFile);

	Assert::contains(<<<'XX'
		/** @var string Old documentation */
			public string|int $value
		XX, $content);
	Assert::contains(<<<'XX'
		/** @var int Another property */
			public int $count
		XX, $content);
});


test('handles property without phpDoc correctly', function () {
	$className = 'NoPhpDocControl';
	$control = createTempControl($className);

	$templateClassName = 'NoPhpDocTemplate';
	$templateFile = getTempDir() . "/$templateClassName.php";

	file_put_contents($templateFile, <<<XX
		<?php

		declare(strict_types=1);

		class $templateClassName extends Nette\\Bridges\\ApplicationLatte\\Template
		{
			public string \$title;

			/** @var string Page description for SEO */
			public string \$description;
		}
		XX);

	require $templateFile;

	$latte = new Latte\Engine;

	$generator = new TemplateGenerator($latte, $templateClassName, $control);

	// Update property without phpDoc with different type
	$generator->title = 999;

	// Update property with phpDoc with different type
	$generator->description = 123;

	$content = file_get_contents($templateFile);

	Assert::notContains('title */', $content);
	Assert::contains('public string|int $title', $content);
	Assert::contains(<<<'XX'
		/** @var string Page description for SEO */
			public string|int $description
		XX, $content);
});
