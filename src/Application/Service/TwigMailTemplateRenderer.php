<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Service;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Mail\Domain\Contract\MailTemplateRendererInterface;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Twig\Environment as TwigEnvironment;

#[SatisfiesServiceContract(of: MailTemplateRendererInterface::class)]
final class TwigMailTemplateRenderer implements MailTemplateRendererInterface
{
    /**
     * Templates live inside a module, under its template directory
     * (Application/View/templates in a src/modules module):
     *   mail/<handle>.subject.txt.twig
     *   mail/<handle>.body.html.twig
     *   mail/<handle>.body.txt.twig   (optional, derived from the HTML if absent)
     *
     * The template catalog registers a module's directory only under its
     * namespaces (`@<alias>/...`), never in Twig's default namespace, so a
     * handle is resolved to a namespace first:
     *   - `@Alias/welcome` names the module explicitly: `@Alias/mail/welcome...`.
     *   - `welcome` first tries the un-namespaced `mail/welcome...` (an app that
     *     adds a default path keeps working), then the modules the catalog
     *     knows, in the catalog's own order (project modules, then registered
     *     packages — the order it resolves layouts in). The first module whose
     *     namespace has `mail/welcome.subject.txt.twig` provides all three parts.
     *
     * @param array<string, mixed> $variables
     * @return array{subject: string, htmlBody: ?string, textBody: ?string}
     */
    public function render(string $templateHandle, array $variables, ?string $locale = null): array
    {
        $twig = ModuleTemplateRegistry::getTwig();
        $base = $this->resolveBase($twig, $templateHandle);

        $subject  = $this->renderRequired($twig, "{$base}.subject.txt.twig", $variables);
        $htmlBody = $this->tryRender($twig, "{$base}.body.html.twig", $variables);
        $textBody = $this->tryRender($twig, "{$base}.body.txt.twig", $variables);

        if ($textBody === null && $htmlBody !== null) {
            $textBody = $this->htmlToPlainText($htmlBody);
        }

        return [
            'subject'  => trim($subject),
            'htmlBody' => $htmlBody !== '' ? $htmlBody : null,
            'textBody' => $textBody !== '' ? $textBody : null,
        ];
    }

    /**
     * The template name without its `.subject.txt.twig` / `.body.*.twig` suffix,
     * e.g. `@Shop/mail/welcome` or `mail/welcome`.
     */
    private function resolveBase(TwigEnvironment $twig, string $templateHandle): string
    {
        if (str_starts_with($templateHandle, '@') && str_contains($templateHandle, '/')) {
            [$namespace, $handle] = explode('/', $templateHandle, 2);

            return "{$namespace}/mail/{$handle}";
        }

        $loader = $twig->getLoader();
        $plain = "mail/{$templateHandle}";
        if ($loader->exists("{$plain}.subject.txt.twig")) {
            return $plain;
        }

        foreach (ModuleTemplateRegistry::getModulePaths() as $module => $config) {
            $aliases = array_values(array_unique(array_merge([(string) $module], $config['aliases'])));
            foreach ($aliases as $alias) {
                $candidate = "@{$alias}/{$plain}";
                if ($loader->exists("{$candidate}.subject.txt.twig")) {
                    return $candidate;
                }
            }
        }

        throw new \RuntimeException(
            "Failed to render required mail template '{$plain}.subject.txt.twig': "
            . "no module provides mail template '{$templateHandle}'.",
        );
    }

    private function renderRequired(TwigEnvironment $twig, string $template, array $variables): string
    {
        try {
            return $twig->render($template, $variables);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Failed to render required mail template '{$template}': {$e->getMessage()}",
                0,
                $e,
            );
        }
    }

    /**
     * A body template is optional: one that does not exist is absent. One that
     * exists and fails is an error — catching that too sent the mail without
     * its body and said nothing.
     */
    private function tryRender(TwigEnvironment $twig, string $template, array $variables): ?string
    {
        if (!$twig->getLoader()->exists($template)) {
            return null;
        }

        return $this->renderRequired($twig, $template, $variables);
    }

    private function htmlToPlainText(string $html): string
    {
        $text = str_replace(
            ['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>'],
            "\n",
            $html,
        );
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/\n{3,}/', "\n\n", trim($text)) ?? trim($text);
    }
}
