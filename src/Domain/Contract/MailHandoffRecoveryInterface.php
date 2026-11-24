<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Contract;

use Semitexa\Mail\Domain\Model\MailMessage;

/**
 * The mails the queue accepted and then lost: written, handed to the queue, and
 * never seen by a worker. A publish can vanish after it returned, so a row can
 * sit `queued` with no attempt for weeks. Kept apart from
 * MailRepositoryInterface so a project's own repository does not break; a
 * project rescuing such mails used to read mail_messages and mail_attempts
 * itself and write the row by its binary id.
 */
interface MailHandoffRecoveryInterface
{
    /**
     * Mails `queued` or `pending` with no attempt at all, created and last
     * changed before $untouchedSince, across every tenant, oldest first: up to
     * $limit, at most 500. A mail with an attempt is the worker's (its retries
     * own it), and one for the `null` driver goes nowhere, so neither is here.
     * Also a mail `sending` with no attempt, last changed before
     * $untouchedSince and longer ago than the takeover window (15 minutes):
     * the worker that took it died before trying, and when its queue message
     * is gone too nothing else finds the mail.
     *
     * @return list<MailMessage>
     */
    public function lostHandoffs(\DateTimeImmutable $untouchedSince, int $limit): array;

    /**
     * Take $message for one more publish: true for exactly one caller while it
     * is still listed by lostHandoffs() for $untouchedSince. A `sending` one
     * is put back to `queued`: its worker is presumed dead, and the message
     * published next finds a mail waiting. Marks it changed now, so the next sweep leaves it for a
     * window instead of publishing a fresh copy every time. $untouchedSince
     * must be in the past: a claim marks the row with now, and a later cutoff
     * would let the next caller take it too (InvalidArgumentException).
     */
    public function claimLostHandoff(MailMessage $message, \DateTimeImmutable $untouchedSince): bool;
}
