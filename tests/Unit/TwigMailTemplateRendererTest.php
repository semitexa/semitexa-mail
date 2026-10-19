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

    /** @param array<string, string> $templates */
    private function templates(array $templates): void
    {
        $loader = new ArrayLoader($templates);
        $catalog = new ModuleTemplateCatalog();
        $reflection = new \ReflectionClass($catalog);
        $reflection->getProperty('twig')->setValue($catalog, new TwigEnvironment($loader, ['cache' => false, 'autoescape' => 'html']));
        $reflection->getProperty('loader')->setValue($catalog, $loader);
        $reflection->getProperty('initialized')->setValue($catalog, true);
        ModuleTemplateRegistry::setCatalog($catalog);
    }
}
