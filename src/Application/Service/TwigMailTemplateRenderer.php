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
     * Templates live at:
     *   Application/View/templates/mail/<handle>.subject.txt.twig
     *   Application/View/templates/mail/<handle>.body.html.twig
     *   Application/View/templates/mail/<handle>.body.txt.twig  (optional, derived from HTML if absent)
     *
     * @param array<string, mixed> $variables
     * @return array{subject: string, htmlBody: ?string, textBody: ?string}
     */
    public function render(string $templateHandle, array $variables, ?string $locale = null): array
    {
        $twig = ModuleTemplateRegistry::getTwig();

        $subject  = $this->renderRequired($twig, "mail/{$templateHandle}.subject.txt.twig", $variables);
        $htmlBody = $this->tryRender($twig, "mail/{$templateHandle}.body.html.twig", $variables);
        $textBody = $this->tryRender($twig, "mail/{$templateHandle}.body.txt.twig", $variables);

        if ($textBody === null && $htmlBody !== null) {
            $textBody = $this->htmlToPlainText($htmlBody);
        }

        return [
            'subject'  => trim($subject),
            'htmlBody' => $htmlBody !== '' ? $htmlBody : null,
            'textBody' => $textBody !== '' ? $textBody : null,
        ];
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

    private function tryRender(TwigEnvironment $twig, string $template, array $variables): ?string
    {
        try {
            return $twig->render($template, $variables);
        } catch (\Throwable) {
            return null;
        }
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
