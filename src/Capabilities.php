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
)]
final class Capabilities
{
}
