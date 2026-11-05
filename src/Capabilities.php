<?php

declare(strict_types=1);

namespace Semitexa\Mail;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * Without this the package is invisible to anyone whose project has not
 * installed it - which is precisely the audience worth telling, since they are
 * the ones about to build it by hand. The convention is one `Capabilities` class
 * per package: a definite place to look, and a definite place for a guard to
 * check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'mail.outbound',
    summary: 'Outbound email with SMTP transport, Twig-rendered bodies, queue-safe delivery and storage-backed attachments.',
    useWhen: 'The application has to send mail a person will read - confirmations, notifications, reports.',
    avoidWhen: 'You need transactional delivery guarantees from a provider API you already integrate elsewhere.',
    replaces: [
        'a mail() or raw SMTP call in a handler, blocking the response until the server answers',
        'string concatenation for the body, and base64 attachment handling written by hand',
    ],
    usage: <<<'USAGE'
        #[InjectAsReadonly]
        protected MailServiceInterface $mail;

        $envelope = new MailEnvelope();
        $envelope->to = [new MailRecipient('ada@example.com', 'Ada Lovelace')];
        $envelope->templateHandle = 'welcome';
        $envelope->variables = ['name' => 'Ada'];
        $envelope->idempotencyKey = 'welcome:' . $userId;

        $result = $this->mail->send($envelope);
        if (!$result->succeeded()) {
            // $result->status, $result->errorCode, $result->errorMessage
        }

        // The template lives in the module's templates: mail/welcome.subject.txt.twig is required,
        // with mail/welcome.body.html.twig next to it. Inline content is $envelope->subject and
        // ->htmlBody instead. A body holding a secret, such as a reset link, sets ->sensitive.
        // send() queues by default. With EVENTS_ASYNC=0 that delivers at once; on a real queue
        // bin/semitexa mail:work delivers it, so check $result before telling anyone it was sent.
        USAGE,
)]
final class Capabilities
{
}
