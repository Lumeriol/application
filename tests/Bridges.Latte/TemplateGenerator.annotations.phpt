<?php

/**
 * Test: TemplateGenerator - annotations
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


function createTemplateFile(string $content = ''): string
{
	$fileName = getTempDir() . '/template_' . uniqid() . '.latte';
	file_put_contents($fileName, $content);
	return $fileName;
}


test('adds @property-read annotation to Control without phpDoc', function () {
	$control = createTempControl('DocControl');
	$latte = new Latte\Engine;

	new TemplateGenerator($latte, null, $control);

	$content = file_get_contents(getTempDir() . '/DocControl.php');

	Assert::contains(<<<'XX'
		/**
		 * @property-read DocTemplate $template
		 */
		class DocControl
		XX, $content);
});


test('adds @property-read annotation to final Control without phpDoc', function () {
	$className = 'FinalDocControl';
	$control = createTempControl($className, <<<PHP
		<?php

		declare(strict_types=1);

		final class $className extends Nette\\Application\\UI\\Control
		{
		}
		PHP);

	$latte = new Latte\Engine;

	new TemplateGenerator($latte, null, $control);

	$content = file_get_contents(getTempDir() . "/$className.php");

	Assert::contains(<<<'XX'
		/**
		 * @property-read FinalDocTemplate $template
		 */
		final class FinalDocControl
		XX, $content);
});


test('updates existing phpDoc with @property-read annotation', function () {
	$className = 'ExistingDocControl';
	$control = createTempControl($className, <<<XX
		<?php

		declare(strict_types=1);

		/**
		 * My control description.
		 */
		class $className extends Nette\\Application\\UI\\Control
		{
		}
		XX);

	$latte = new Latte\Engine;

	new TemplateGenerator($latte, null, $control);

	$content = file_get_contents(getTempDir() . "/$className.php");

	Assert::contains(<<<'XX'
		/**
		 * My control description.
		 * @property-read ExistingDocTemplate $template
		 */
		XX, $content);
});


test('skips phpDoc update if @property annotation already exists', function () {
	$className = 'HasPropertyControl';
	$originalContent = <<<XX
		<?php

		declare(strict_types=1);

		/**
		 * @property-read MyTemplate \$template
		 */
		class $className extends Nette\\Application\\UI\\Control
		{
		}
		XX;

	$control = createTempControl($className, $originalContent);
	$latte = new Latte\Engine;

	new TemplateGenerator($latte, null, $control);

	$content = file_get_contents(getTempDir() . "/$className.php");

	Assert::same($originalContent, $content);
});


test('adds {templateType} to template file on render', function () {
	$control = createTempControl('RenderControl');
	$latte = new Latte\Engine;
	$latte->setTempDirectory(getTempDir());

	$generator = new TemplateGenerator($latte, null, $control);

	$templateFile = createTemplateFile('<h1>Test</h1>');
	$generator->setFile($templateFile);

	ob_start();
	$generator->render();
	ob_end_clean();

	$content = file_get_contents($templateFile);

	Assert::contains('{templateType RenderTemplate}
<h1>Test</h1>', $content);
});


test('does not duplicate {templateType} on subsequent renders', function () {
	$control = createTempControl('NoDuplicateControl');
	$latte = new Latte\Engine;
	$latte->setTempDirectory(getTempDir());

	$generator = new TemplateGenerator($latte, null, $control);

	$templateFile = createTemplateFile('<h1>Test</h1>');
	$generator->setFile($templateFile);

	ob_start();
	$generator->render();
	$generator->render();
	ob_end_clean();

	$content = file_get_contents($templateFile);

	Assert::same(1, substr_count($content, '{templateType'));
});
