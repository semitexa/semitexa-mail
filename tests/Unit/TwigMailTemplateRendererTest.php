<?php

declare(strict_types=1);

namespace Semitexa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Mail\Application\Service\TwigMailTemplateRenderer;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateCatalog;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\ArrayLoader;

/**
 * Mail templates — <handle>.subject.txt.twig, .body.html.twig and an optional
 * .body.txt.twig — rendered by the real renderer over an in-memory loader
 * (tk-parity-proof-gaps). The registry's catalog is process-wide, so the one
 * found is put back after every test.
 */
final class TwigMailTemplateRendererTest extends TestCase
{
    private ?ModuleTemplateCatalog $previous = null;

    protected function setUp(): void
    {
        $previous = (new \ReflectionProperty(ModuleTemplateRegistry::class, 'catalog'))->getValue();
        $this->previous = $previous instanceof ModuleTemplateCatalog ? $previous : null;
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ModuleTemplateRegistry::class, 'catalog'))->setValue(null, $this->previous);
    }

    #[Test]
    public function the_subject_and_both_bodies_come_from_their_templates(): void
    {
        $this->templates([
            'mail/welcome.subject.txt.twig' => "  Welcome, {{ name }}\n",
            'mail/welcome.body.html.twig' => '<p>Hi {{ name }}</p>',
            'mail/welcome.body.txt.twig' => 'Hi {{ name }}, in plain text',
        ]);

        $rendered = (new TwigMailTemplateRenderer())->render('welcome', ['name' => 'Ada']);

        self::assertSame(['subject' => 'Welcome, Ada', 'htmlBody' => '<p>Hi Ada</p>', 'textBody' => 'Hi Ada, in plain text'], $rendered);
    }

    #[Test]
    public function without_a_text_template_the_text_body_is_derived_from_the_html(): void
    {
        $this->templates([
            'mail/receipt.subject.txt.twig' => 'Receipt',
            'mail/receipt.body.html.twig' => '<h1>Paid</h1><p>Total: 10&nbsp;&euro;</p><p>Thanks</p>',
        ]);

        $rendered = (new TwigMailTemplateRenderer())->render('receipt', []);

        self::assertSame("Paid\nTotal: 10\u{00A0}€\nThanks", $rendered['textBody']);
    }

    #[Test]
    public function a_missing_subject_template_is_an_error_that_names_it(): void
    {
        $this->templates(['mail/welcome.body.html.twig' => '<p>Hi</p>']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mail/welcome.subject.txt.twig');

        (new TwigMailTemplateRenderer())->render('welcome', []);
    }

    #[Test]
    public function a_body_template_that_does_not_exist_is_simply_absent(): void
    {
        $this->templates([
            'mail/notice.subject.txt.twig' => 'Notice',
            'mail/notice.body.txt.twig' => 'Plain only',
        ]);

        $rendered = (new TwigMailTemplateRenderer())->render('notice', []);

        self::assertNull($rendered['htmlBody']);
        self::assertSame('Plain only', $rendered['textBody']);
    }

    /**
     * REGRESSION. A body template that exists but fails — a typo, a missing
     * partial — was caught with every other error and became "no HTML body":
     * the mail went out without its body, and nothing said why.
     */
    #[Test]
    public function a_body_template_that_exists_but_fails_is_an_error_not_an_empty_body(): void
    {
        $this->templates([
            'mail/welcome.subject.txt.twig' => 'Welcome',
            'mail/welcome.body.html.twig' => '<p>{% include "mail/partials/missing.html.twig" %}</p>',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mail/welcome.body.html.twig');

        (new TwigMailTemplateRenderer())->render('welcome', []);
    }

    /**
     * REGRESSION. The real catalog registers module templates only under
     * namespaces (`@<alias>/...`), never in Twig's default namespace, so the
     * un-namespaced `mail/<handle>...` the renderer asked for could not exist in
     * production. A plain handle is now looked up in the modules the catalog knows.
     */
    #[Test]
    public function a_plain_handle_is_found_in_the_module_that_provides_it(): void
    {
        $this->templates([
            '@Shop/mail/welcome.subject.txt.twig' => 'Welcome, {{ name }}',
            '@Shop/mail/welcome.body.html.twig' => '<p>Hi {{ name }}</p>',
            '@Shop/mail/welcome.body.txt.twig' => 'Hi {{ name }}',
        ], ['Blog' => ['Blog'], 'Shop' => ['Shop']]);

        $rendered = (new TwigMailTemplateRenderer())->render('welcome', ['name' => 'Ada']);

        self::assertSame(['subject' => 'Welcome, Ada', 'htmlBody' => '<p>Hi Ada</p>', 'textBody' => 'Hi Ada'], $rendered);
    }

    #[Test]
    public function a_handle_with_a_namespace_names_its_module_explicitly(): void
    {
        $this->templates([
            '@Blog/mail/welcome.subject.txt.twig' => 'From Blog',
            '@Shop/mail/welcome.subject.txt.twig' => 'From Shop',
            '@Shop/mail/welcome.body.html.twig' => '<p>Shop body</p>',
        ], ['Blog' => ['Blog'], 'Shop' => ['Shop']]);

        $rendered = (new TwigMailTemplateRenderer())->render('@Shop/welcome', []);

        self::assertSame('From Shop', $rendered['subject']);
        self::assertSame('<p>Shop body</p>', $rendered['htmlBody']);
    }

    /**
     * Two modules ship the same handle: the first in the catalog's module order
     * wins — the order the catalog itself resolves layouts in (project modules
     * under src/modules first, then registered packages), so a project module
     * overrides a package. All three parts come from that one module: the second
     * module's body is never mixed in.
     */
    #[Test]
    public function when_two_modules_provide_a_handle_the_first_in_catalog_order_wins(): void
    {
        $this->templates([
            '@Shop/mail/welcome.subject.txt.twig' => 'From Shop',
            '@Blog/mail/welcome.subject.txt.twig' => 'From Blog',
            '@Blog/mail/welcome.body.html.twig' => '<p>Blog body</p>',
        ], ['Shop' => ['Shop'], 'Blog' => ['Blog']]);

        $rendered = (new TwigMailTemplateRenderer())->render('welcome', []);

        self::assertSame('From Shop', $rendered['subject']);
        self::assertNull($rendered['htmlBody']);
    }

    #[Test]
    public function a_handle_no_module_provides_is_an_error_that_names_the_handle(): void
    {
        $this->templates([
            '@Shop/mail/receipt.subject.txt.twig' => 'Receipt',
        ], ['Shop' => ['Shop']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("no module provides mail template 'welcome'");

        (new TwigMailTemplateRenderer())->render('welcome', []);
    }

    /** @param array<string, string> $templates
     *  @param array<string, list<string>> $modules module name => aliases, in catalog order */
    private function templates(array $templates, array $modules = []): void
    {
        $loader = new ArrayLoader($templates);
        $catalog = new ModuleTemplateCatalog();
        $reflection = new \ReflectionClass($catalog);
        $reflection->getProperty('twig')->setValue($catalog, new TwigEnvironment($loader, ['cache' => false, 'autoescape' => 'html']));
        $reflection->getProperty('loader')->setValue($catalog, $loader);
        $modulePaths = [];
        foreach ($modules as $name => $aliases) {
            $modulePaths[$name] = ['aliases' => $aliases, 'path' => '/nonexistent/' . $name, 'type' => 'standard'];
        }
        $reflection->getProperty('modulePaths')->setValue($catalog, $modulePaths);
        $reflection->getProperty('initialized')->setValue($catalog, true);
        ModuleTemplateRegistry::setCatalog($catalog);
    }
}
