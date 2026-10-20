<?php

declare(strict_types=1);

namespace Semitexa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Locale\Application\Service\I18n\TranslationCatalog;
use Semitexa\Locale\Application\Service\I18n\TranslationService;
use Semitexa\Locale\Context\LocaleContextStore;
use Semitexa\Locale\Context\LocaleManager;
use Semitexa\Mail\Application\Service\TwigMailTemplateRenderer;
use Semitexa\Ssr\Application\Service\Extension\LocaleTwigExtension;
use Semitexa\Ssr\Application\Service\I18n\Translator;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateCatalog;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

/**
 * Mail templates — <handle>.subject.txt.twig, .body.html.twig and an optional
 * .body.txt.twig — rendered by the real renderer over an in-memory loader
 * (tk-parity-proof-gaps). The registry's catalog is process-wide, so the one
 * found is put back after every test.
 */
final class TwigMailTemplateRendererTest extends TestCase
{
    private ?ModuleTemplateCatalog $previous = null;

    /** @var array<string, mixed> Translator's static state as found, unset service included */
    private array $translator = [];

    private string $locale = 'en';

    /** @var list<string> */
    private array $supported = [];

    protected function setUp(): void
    {
        $previous = (new \ReflectionProperty(ModuleTemplateRegistry::class, 'catalog'))->getValue();
        $this->previous = $previous instanceof ModuleTemplateCatalog ? $previous : null;
        // By reflection: getService() would build a service that was not there.
        $this->translator = (new \ReflectionClass(Translator::class))->getStaticProperties();
        $this->locale = LocaleContextStore::getLocale();
        $this->supported = array_values(LocaleContextStore::getSupportedLocales());
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ModuleTemplateRegistry::class, 'catalog'))->setValue(null, $this->previous);
        $translator = new \ReflectionClass(Translator::class);
        foreach ($this->translator as $name => $value) {
            $translator->setStaticPropertyValue($name, $value);
        }
        LocaleContextStore::setLocale($this->locale);
        LocaleContextStore::setSupportedLocales($this->supported);
    }

    /**
     * REGRESSION (tk-mail-renderer-locale). The locale a mail is sent in was
     * passed to the renderer and ignored: every mail was rendered in the
     * locale of whoever sent it (the visitor's request, or the worker's
     * default), so a Ukrainian customer got the receipt in the language of
     * the admin who pressed the button. The mail now renders in its own
     * locale, and the sender's locale is put back after it.
     */
    #[Test]
    public function a_mail_is_rendered_in_the_locale_it_is_given_and_the_senders_locale_is_kept(): void
    {
        $this->translations([
            'en' => ['mail.welcome' => 'Welcome, {{name}}'],
            'uk' => ['mail.welcome' => 'Вітаємо, {{name}}'],
            'de' => ['mail.welcome' => 'Willkommen, {{name}}'],
            'fr' => ['mail.welcome' => 'Bienvenue, {{name}}'],
        ]);
        $this->welcomeTemplates();
        // The sender's own locale is not the default, so putting back the
        // default instead of the sender's would show.
        LocaleContextStore::setLocale('fr');
        $renderer = $this->renderer();

        $uk = $renderer->render('welcome', ['name' => 'Ada'], 'uk');
        $de = $renderer->render('welcome', ['name' => 'Ada'], 'de');
        $sendersOwn = $renderer->render('welcome', ['name' => 'Ada']);

        self::assertSame(['subject' => 'Вітаємо, Ada', 'htmlBody' => '<p lang="uk">Вітаємо, Ada</p>'], ['subject' => $uk['subject'], 'htmlBody' => $uk['htmlBody']]);
        self::assertSame(['subject' => 'Willkommen, Ada', 'htmlBody' => '<p lang="de">Willkommen, Ada</p>'], ['subject' => $de['subject'], 'htmlBody' => $de['htmlBody']]);
        self::assertSame('Bienvenue, Ada', $sendersOwn['subject'], 'no locale: the sender\'s');
        self::assertSame('fr', LocaleContextStore::getLocale(), 'the sender\'s locale is put back');
    }

    /**
     * An envelope locale is written as a person or a database has it: uk-UA,
     * uk_UA, UK. Like the Accept-Language resolver, the renderer takes the
     * language a site supports: the full tag when it is supported, else its
     * primary subtag when that is.
     */
    #[Test]
    public function a_regional_or_differently_written_locale_renders_in_the_supported_language(): void
    {
        $this->translations([
            'en' => ['mail.welcome' => 'Welcome, {{name}}'],
            'uk' => ['mail.welcome' => 'Вітаємо, {{name}}'],
        ]);
        $this->welcomeTemplates();
        LocaleContextStore::setSupportedLocales(['en', 'uk']);
        $renderer = $this->renderer();

        foreach (['uk-UA', 'uk_UA', 'UK', ' uk-ua '] as $given) {
            self::assertSame('<p lang="uk">Вітаємо, Ada</p>', $renderer->render('welcome', ['name' => 'Ada'], $given)['htmlBody'], $given);
        }
    }

    /** zh-Hant is not zh-HANT, and a site that wrote pt-br wants pt-br: matched in any case, answered as the site spells it (review). */
    #[Test]
    public function a_supported_locale_is_matched_in_any_case_and_answered_as_the_site_spells_it(): void
    {
        $this->translations([
            'en' => ['mail.welcome' => 'Welcome, {{name}}'],
            'zh-Hant' => ['mail.welcome' => '歡迎, {{name}}'],
            'pt-br' => ['mail.welcome' => 'Bem-vinda, {{name}}'],
        ]);
        $this->welcomeTemplates();
        LocaleContextStore::setSupportedLocales(['en', 'zh-Hant', 'pt-br']);
        $renderer = $this->renderer();

        self::assertSame('<p lang="zh-Hant">歡迎, Ada</p>', $renderer->render('welcome', ['name' => 'Ada'], 'zh-hant')['htmlBody']);
        self::assertSame('<p lang="pt-br">Bem-vinda, Ada</p>', $renderer->render('welcome', ['name' => 'Ada'], 'pt-BR')['htmlBody']);
    }

    /** A regional tag the site supports is kept whole; a primary subtag it does not support is not taken. */
    #[Test]
    public function the_primary_subtag_is_taken_only_when_the_full_tag_is_not_supported_and_it_is(): void
    {
        $this->translations([
            'en' => ['mail.welcome' => 'Welcome, {{name}}'],
            'pt' => ['mail.welcome' => 'Bem-vindo, {{name}}'],
            'pt-BR' => ['mail.welcome' => 'Bem-vinda, {{name}}'],
        ]);
        $this->welcomeTemplates();
        LocaleContextStore::setSupportedLocales(['en', 'pt', 'pt-BR']);
        $renderer = $this->renderer();

        self::assertSame('<p lang="pt-BR">Bem-vinda, Ada</p>', $renderer->render('welcome', ['name' => 'Ada'], 'pt_br')['htmlBody']);
        self::assertSame('<p lang="pt">Bem-vindo, Ada</p>', $renderer->render('welcome', ['name' => 'Ada'], 'pt-PT')['htmlBody']);

        LocaleContextStore::setSupportedLocales(['en']);
        self::assertSame('<p lang="pt-PT">Welcome, Ada</p>', $renderer->render('welcome', ['name' => 'Ada'], 'pt-PT')['htmlBody'], 'unsupported: as given, normalised, and the catalog\'s fallback');
    }

    /** A template that fails still puts the sender's locale back. */
    #[Test]
    public function the_senders_locale_is_put_back_when_rendering_fails(): void
    {
        $this->templates(['mail/welcome.subject.txt.twig' => '{{ missing() }}']);
        LocaleContextStore::setLocale('fr');

        try {
            $this->renderer()->render('welcome', [], 'uk');
            self::fail('the broken template rendered');
        } catch (\RuntimeException) {
        }

        self::assertSame('fr', LocaleContextStore::getLocale());
    }

    private function welcomeTemplates(): void
    {
        $this->templates([
            'mail/welcome.subject.txt.twig' => "{{ trans('mail.welcome', {name: name}) }}",
            'mail/welcome.body.html.twig' => '<p lang="{{ locale() }}">{{ trans(\'mail.welcome\', {name: name}) }}</p>',
        ]);
    }

    private function renderer(): TwigMailTemplateRenderer
    {
        $renderer = new TwigMailTemplateRenderer();
        // What the container injects.
        (new \ReflectionProperty($renderer, 'localeContext'))->setValue($renderer, new LocaleManager());

        return $renderer;
    }

    /** @param array<string, array<string, string>> $byLocale */
    private function translations(array $byLocale): void
    {
        $catalog = new TranslationCatalog();
        foreach ($byLocale as $locale => $messages) {
            $catalog->addMessages($locale, 'Mail', $messages);
        }
        Translator::setService(new TranslationService($catalog, LocaleManager::getInstance()));
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
        $twig = new TwigEnvironment($loader, ['cache' => false, 'autoescape' => 'html']);
        // The framework's own trans() and locale(), as the catalog registers them.
        $locale = new LocaleTwigExtension();
        $twig->addFunction(new TwigFunction('trans', $locale->translate(...)));
        $twig->addFunction(new TwigFunction('locale', $locale->currentLocale(...)));
        $reflection->getProperty('twig')->setValue($catalog, $twig);
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
