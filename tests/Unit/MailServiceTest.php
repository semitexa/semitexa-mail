<?php

declare(strict_types=1);

namespace Semitexa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;
use Semitexa\Core\Queue\QueueStatsRecorder;
use Semitexa\Core\Queue\QueueStatsStoreInterface;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Mail\Application\Service\AttachmentResolver;
use Semitexa\Mail\Application\Service\FakeMailTransport;
use Semitexa\Mail\Application\Service\MailService;
use Semitexa\Mail\Application\Service\MailTransportRegistry;
use Semitexa\Mail\Application\Service\MailWorker;
use Semitexa\Mail\Domain\Contract\MailAttemptRepositoryInterface;
use Semitexa\Mail\Domain\Contract\MailerConfigResolverInterface;
use Semitexa\Mail\Domain\Contract\MailRecoveryInterface;
use Semitexa\Mail\Domain\Contract\MailRepositoryInterface;
use Semitexa\Mail\Domain\Contract\MailTemplateRendererInterface;
use Semitexa\Mail\Domain\Contract\MailTransportInterface;
use Semitexa\Mail\Domain\Enum\MailDispatchStatus;
use Semitexa\Mail\Domain\Enum\MailSendMode;
use Semitexa\Mail\Domain\Enum\MailTransportStatus;
use Semitexa\Mail\Domain\Model\MailAttempt;
use Semitexa\Mail\Domain\Model\MailEnvelope;
use Semitexa\Mail\Domain\Model\MailerConfig;
use Semitexa\Mail\Domain\Model\MailMessage;
use Semitexa\Mail\Domain\Model\MailRecipient;
use Semitexa\Mail\Domain\Model\MailSendOptions;
use Semitexa\Mail\Domain\Model\MailTransportResult;
use Semitexa\Mail\Domain\Model\PreparedMailMessage;
use Semitexa\Mail\Domain\Model\QueuedMailMessage;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Sending mail, proven through MailService and MailWorker themselves
 * (tk-parity-proof-gaps): until this test only header injection and the
 * sandbox transport were covered, and FakeMailTransport — the package's own
 * test double — was used by nothing.
 */
final class MailServiceTest extends TestCase
{
    private MailServiceMessages $messages;

    private MailServiceAttempts $attempts;

    private FakeMailTransport $fake;

    private string|false $eventsAsync = false;

    private string|false $eventsTransport = false;

    /** @var array<class-string, array<string, mixed>> the process-global registries as the test found them */
    private array $registries = [];

    protected function setUp(): void
    {
        // The test doubles' own statics too: what one test left in a spy is
        // put back for whatever ran before this class, not wiped.
        foreach ([MailTransportRegistry::class, QueueTransportRegistry::class, \Semitexa\Core\Log\StaticLoggerBridge::class, MailServiceQueueSpy::class, MailServiceDelayedQueueSpy::class, MailServiceConfig::class, MailServiceRenderer::class] as $class) {
            $this->registries[$class] = self::statics($class);
        }
        MailTransportRegistry::reset();
        $this->fake = new FakeMailTransport();
        MailTransportRegistry::register($this->fake);
        $this->messages = new MailServiceMessages();
        $this->attempts = new MailServiceAttempts();
        QueueTransportRegistry::reset();
        MailServiceQueueSpy::$published = [];
        MailServiceQueueSpy::$refuse = false;
        MailServiceDelayedQueueSpy::$delayed = [];
        MailServiceConfig::$mailerKeys = [];
        $this->eventsAsync = getenv('EVENTS_ASYNC');
        $this->eventsTransport = getenv('EVENTS_TRANSPORT');
    }

    protected function tearDown(): void
    {
        // Put back, not reset: a registration made before this class ran
        // is still there for the tests after it.
        foreach ($this->registries as $class => $values) {
            foreach ($values as $name => $value) {
                (new \ReflectionProperty($class, $name))->setValue(null, $value);
            }
        }
        putenv($this->eventsTransport === false ? 'EVENTS_TRANSPORT' : 'EVENTS_TRANSPORT=' . $this->eventsTransport);
        putenv($this->eventsAsync === false ? 'EVENTS_ASYNC' : 'EVENTS_ASYNC=' . $this->eventsAsync);
    }

    #[Test]
    public function a_sync_send_delivers_through_the_transport_and_records_it(): void
    {
        $result = $this->service()->send($this->envelope(subject: 'Welcome', text: 'Hello Ada'), $this->now());

        self::assertSame(MailDispatchStatus::Sent, $result->status);
        self::assertSame(1, $this->fake->count());
        $sent = $this->fake->getLastMessage();
        self::assertSame('Welcome', $sent?->subject);
        self::assertSame('Hello Ada', $sent?->textBody);
        self::assertSame('noreply@example.test', $sent?->from->email, 'the configured sender when the envelope names none');
        self::assertSame(['ada@example.test'], array_map(static fn (MailRecipient $r): string => $r->email, $sent?->to ?? []));
        self::assertSame('sent', $this->messages->byId($result->messageId)?->getStatus());
        self::assertCount(1, $this->attempts->saved, 'every delivery attempt is recorded');
    }

    /** The stored row kept a live reset link where every operator screen and backup could read it. */
    #[Test]
    public function a_sensitive_mail_is_sent_whole_and_its_stored_bodies_are_erased(): void
    {
        $envelope = $this->envelope(subject: 'Reset', text: 'https://example.test/reset?token=secret');
        $envelope->htmlBody = '<a href="https://example.test/reset?token=secret">Reset</a>';
        $envelope->sensitive = true;

        $result = $this->service()->send($envelope, $this->now());

        self::assertSame('https://example.test/reset?token=secret', $this->fake->getLastMessage()?->textBody, 'the recipient gets the link');
        self::assertSame('<a href="https://example.test/reset?token=secret">Reset</a>', $this->fake->getLastMessage()->htmlBody, 'in both bodies');
        $row = $this->messages->byId($result->messageId);
        self::assertNotNull($row);
        self::assertSame('sent', $row->getStatus());
        self::assertSame(\Semitexa\Mail\Domain\Model\SensitiveMailBodies::ERASED, $row->getTextBody());
        self::assertSame(\Semitexa\Mail\Domain\Model\SensitiveMailBodies::ERASED, $row->getHtmlBody());
        self::assertSame('Reset', $row->getSubject(), 'only the bodies go');

        $plain = $this->service()->send($this->envelope(subject: 'Hi', text: 'Hello'), $this->now());
        self::assertSame('Hello', $this->messages->byId($plain->messageId)?->getTextBody(), 'an ordinary mail keeps its body');
    }

    /** Sent in the call, a rolled-back order still mailed its customer, and a replayed one mailed them twice. */
    #[Test]
    public function inside_a_transaction_the_mail_leaves_only_once_it_commits(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true), connectionName: 'mail_tx_probe');
        $service = $this->service(orm: $orm);

        $result = $orm->getTransactionManager()->run(function () use ($service) {
            $result = $service->send($this->envelope(subject: 'Receipt', text: 'Paid'), $this->now());
            self::assertSame(0, $this->fake->count(), 'nothing leaves before the commit');

            return $result;
        });

        self::assertSame(MailDispatchStatus::Queued, $result->status, 'accepted, not yet sent, when send() returned');
        self::assertSame(1, $this->fake->count());
        self::assertSame('sent', $this->messages->byId($result->messageId)?->getStatus());
        $orm->shutdown();
    }

    /** Both resends saw `failed` and both held back a delivery: the mail went out twice. */
    #[Test]
    public function a_failed_mail_resent_twice_in_one_transaction_goes_out_once(): void
    {
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Failed, errorCode: 'network_error', errorMessage: 'refused')));
        $envelope = $this->envelope(subject: 'Receipt', text: 'Paid');
        $envelope->idempotencyKey = 'receipt-7';
        self::assertSame(MailDispatchStatus::Failed, $this->service(driver: 'scripted')->send($envelope, $this->now())->status);

        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true), connectionName: 'mail_tx_probe');
        $service = $this->service(orm: $orm);
        $orm->getTransactionManager()->run(function () use ($service, $envelope): void {
            $service->send($envelope, $this->now());
            $service->send(clone $envelope, $this->now());
        });

        self::assertSame(1, $this->fake->count());
        $orm->shutdown();
    }

    /** A claim leaves the row pending, as a held-back mail is: the claimant takes it like any delivery. */
    #[Test]
    public function a_claimed_row_taken_by_a_held_back_delivery_first_is_not_sent_by_the_claimant(): void
    {
        $this->messages->save(new MailMessage(
            id: 'receipt-old', tenantId: null, status: 'sending', driver: 'fake', fromEmail: 'noreply@example.test', subject: 'Receipt',
            to: [['email' => 'ada@example.test', 'name' => 'Ada']], textBody: 'Paid', idempotencyKey: 'receipt-old',
            updatedAt: new \DateTimeImmutable('-1 hour'),
        ));
        // Between the claim and the claimant's delivery, a held-back delivery takes the row.
        $takenByHeldBack = false;
        $this->messages->afterClaim = function (MailServiceMessages $m) use (&$takenByHeldBack): void {
            $takenByHeldBack = $m->takeForDelivery($m->byId('receipt-old') ?? throw new \LogicException('receipt-old is not stored'));
        };
        $envelope = $this->envelope(subject: 'Receipt', text: 'Paid');
        $envelope->idempotencyKey = 'receipt-old';

        self::assertSame(MailDispatchStatus::Queued, $this->service()->send($envelope, $this->now())->status);
        self::assertTrue($takenByHeldBack, 'the claim succeeded, and the held-back delivery took the row after it');
        self::assertSame(0, $this->fake->count(), 'the held-back delivery sends it, not the claimant');
    }

    /** A long transaction's mail waits in `pending`; a resend that took it over meanwhile delivered it. */
    #[Test]
    public function a_held_back_mail_taken_over_meanwhile_is_not_delivered_again(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true), connectionName: 'mail_tx_probe');
        $service = $this->service(orm: $orm);

        $orm->getTransactionManager()->run(function () use ($service): void {
            $row = $this->messages->byId($service->send($this->envelope(subject: 'Receipt', text: 'Paid'), $this->now())->messageId);
            self::assertNotNull($row);
            // What a resend that took the row over leaves behind.
            $this->messages->save($row->with(['status' => 'sent']));
        });

        self::assertSame(0, $this->fake->count());
        $orm->shutdown();
    }

    #[Test]
    public function a_rolled_back_transaction_sends_nothing(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true), connectionName: 'mail_tx_probe');
        $service = $this->service(orm: $orm);

        try {
            $orm->getTransactionManager()->run(function () use ($service): void {
                $service->send($this->envelope(subject: 'Receipt', text: 'Paid'), $this->now());
                throw new \RuntimeException('the order failed');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $this->fake->count());
        $orm->shutdown();
    }

    /** A crash between storing and delivering left a row that answered Queued for good. */
    #[Test]
    public function a_resend_takes_over_a_row_its_sender_abandoned_and_leaves_a_live_one_alone(): void
    {
        foreach (['receipt-old' => '-1 hour', 'receipt-live' => '-1 minute'] as $key => $age) {
            // Stored with an id: a first save through with() stamps the row as changed now.
            $this->messages->save(new MailMessage(
                id: $key, tenantId: null, status: 'sending', driver: 'fake', fromEmail: 'noreply@example.test', subject: 'Receipt',
                to: [['email' => 'ada@example.test', 'name' => 'Ada']], textBody: 'Paid', idempotencyKey: $key,
                updatedAt: new \DateTimeImmutable($age),
            ));
        }
        $old = $this->envelope(subject: 'Receipt', text: 'Paid');
        $old->idempotencyKey = 'receipt-old';
        $live = clone $old;
        $live->idempotencyKey = 'receipt-live';

        self::assertSame(MailDispatchStatus::Sent, $this->service()->send($old, $this->now())->status);
        self::assertSame(MailDispatchStatus::Queued, $this->service()->send($live, $this->now())->status, 'a worker may still be on it');
        self::assertSame(MailDispatchStatus::Sent, $this->service()->send($old, $this->now())->status, 'and it is sent once');
        self::assertSame(1, $this->fake->count());
    }

    #[Test]
    public function a_mail_with_no_recipient_is_refused_before_anything_is_stored(): void
    {
        $envelope = $this->envelope(subject: 'x', text: 'y');
        $envelope->to = [];

        $result = $this->service()->send($envelope, $this->now());

        self::assertSame(MailDispatchStatus::Failed, $result->status);
        self::assertSame('invalid_recipient', $result->errorCode);
        self::assertNull($result->messageId);
        self::assertSame(0, $this->messages->count(), 'nothing stored');
        self::assertSame(0, $this->fake->count());
    }

    /** A recipient's name that is not UTF-8 threw a JsonException out of send() as the row was stored. */
    #[Test]
    public function a_recipient_name_that_is_not_utf8_fails_the_mail_before_anything_is_stored(): void
    {
        $envelope = $this->envelope(subject: 'x', text: 'y');
        $envelope->cc = [new MailRecipient('bob@example.test', "Bob \xFF")];

        $result = $this->service()->send($envelope, $this->now());

        self::assertSame(MailDispatchStatus::Failed, $result->status);
        self::assertSame('invalid_message', $result->errorCode);
        self::assertSame('The name of cc recipient bob@example.test is not valid UTF-8.', $result->errorMessage);
        self::assertSame(0, $this->messages->count(), 'nothing stored');
        self::assertSame(0, $this->fake->count());
    }

    #[Test]
    public function an_inline_attachment_over_the_limit_is_refused_before_anything_is_stored(): void
    {
        $envelope = $this->envelope(subject: 'Big', text: 'x');
        $envelope->attachments = [new \Semitexa\Mail\Domain\Model\AttachmentReference('big.bin', inlineContents: str_repeat('x', \Semitexa\Mail\Domain\Model\AttachmentReference::MAX_INLINE_SIZE + 1))];

        $result = $this->service()->send($envelope, $this->now());

        self::assertSame(MailDispatchStatus::Failed, $result->status);
        self::assertSame('attachment_missing', $result->errorCode);
        self::assertNull($result->messageId);
        self::assertSame([], $this->attempts->saved);
        self::assertSame(0, $this->messages->count(), 'nothing stored');
        self::assertSame(0, $this->fake->count());
    }

    /** Past the column's 998 characters a strict MySQL refused the insert, and send() threw. */
    #[Test]
    public function a_subject_past_998_characters_fails_the_mail_instead_of_throwing(): void
    {
        $envelope = $this->envelope();
        $envelope->subject = str_repeat('é', 999);

        $result = $this->service()->send($envelope, $this->now());

        self::assertSame(MailDispatchStatus::Failed, $result->status);
        self::assertSame('invalid_message', $result->errorCode);
        self::assertNull($this->fake->getLastMessage(), 'nothing sent');

        $envelope = $this->envelope();
        $envelope->subject = str_repeat('é', 998);
        self::assertSame(MailDispatchStatus::Sent, $this->service()->send($envelope, $this->now())->status, 'exactly 998 characters fit');
        self::assertSame(1, $this->fake->count());
        self::assertSame(str_repeat('é', 998), $this->fake->getLastMessage()?->subject);
    }

    /** The same for every column with a length, and for a value that is not UTF-8. */
    #[Test]
    public function a_field_mail_messages_cannot_hold_fails_the_mail(): void
    {
        $long = $this->envelope();
        $long->idempotencyKey = str_repeat('k', 256);
        $broken = $this->envelope();
        $broken->subject = "Caf\xE9";

        foreach (['idempotencyKey' => $long, 'subject' => $broken] as $field => $envelope) {
            $result = $this->service()->send($envelope, $this->now());
            self::assertSame('invalid_message', $result->errorCode, $field);
            self::assertStringContainsString($field, (string) $result->errorMessage);
        }
        self::assertNull($this->fake->getLastMessage());
    }

    /** A queue worker has no tenant: feature() in a site's mail answered with every site's setting. */
    #[Test]
    public function a_template_s_flags_answer_for_the_mail_s_site(): void
    {
        $envelope = $this->envelope();
        $envelope->templateHandle = 'welcome';
        $envelope->variables = ['name' => 'Ada'];
        $envelope->tenantId = 'acme';
        MailServiceRenderer::$flagSubject = null;

        // Sent from inside another subject's render: that one is put back, not cleared.
        $after = \Semitexa\Core\Feature\CurrentFeatureFlags::forSubjectDuring('globex', 'user-7', function () use ($envelope): ?array {
            $this->service()->send($envelope, $this->now());

            return \Semitexa\Core\Feature\CurrentFeatureFlags::subject();
        });

        self::assertSame(['acme', null], MailServiceRenderer::$flagSubject);
        self::assertSame(['globex', 'user-7'], $after, 'the sender\'s subject is put back after the render');
        self::assertNull(\Semitexa\Core\Feature\CurrentFeatureFlags::subject());
    }

    #[Test]
    public function a_template_renders_the_subject_and_bodies(): void
    {
        $envelope = $this->envelope();
        $envelope->templateHandle = 'welcome';
        $envelope->variables = ['name' => 'Ada'];

        $this->service()->send($envelope, $this->now());

        self::assertSame('Welcome, Ada', $this->fake->getLastMessage()?->subject);
        self::assertSame('<p>Hi Ada</p>', $this->fake->getLastMessage()?->htmlBody);
    }

    #[Test]
    public function the_same_idempotency_key_sends_once(): void
    {
        $first = $this->envelope(subject: 'Receipt', text: 'Paid');
        $first->tenantId = 't-1';
        $first->idempotencyKey = 'order-42-receipt';
        $again = clone $first;

        $one = $this->service()->send($first, $this->now());
        $two = $this->service()->send($again, $this->now());

        self::assertSame(1, $this->fake->count(), 'a retried request must not email twice');
        self::assertSame($one->messageId, $two->messageId);
    }

    #[Test]
    public function a_failed_mail_sent_again_under_its_key_is_retried_from_its_row(): void
    {
        // It inserted a second row, the unique index refused it, and send()
        // threw: a failed mail could not be retried under its key.
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Rejected, errorCode: 'mailbox_full', errorMessage: '552 mailbox full')));
        $envelope = $this->envelope(subject: 'Receipt', text: 'Paid');
        $envelope->tenantId = 't-1';
        $envelope->idempotencyKey = 'order-42-receipt';

        $failed = $this->service(driver: 'scripted')->send($envelope, $this->now());
        $retried = $this->service()->send(clone $envelope, $this->now());

        self::assertSame(MailDispatchStatus::Failed, $failed->status);
        self::assertSame(MailDispatchStatus::Sent, $retried->status);
        self::assertSame($failed->messageId, $retried->messageId, 'the same row, not a second one');
        self::assertSame(1, $this->fake->count());
        self::assertSame('sent', $this->messages->byId($retried->messageId)?->getStatus());
    }

    /** Two send() calls retrying one failed key at once both delivered it. */
    #[Test]
    public function of_two_resends_of_a_failed_mail_one_delivers_it(): void
    {
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Rejected, errorCode: 'mailbox_full', errorMessage: '552 mailbox full')));
        $envelope = $this->envelope(subject: 'Receipt', text: 'Paid');
        $envelope->idempotencyKey = 'order-44-receipt';
        $failed = $this->service(driver: 'scripted')->send($envelope, $this->now());
        $row = $this->messages->byId($failed->messageId);
        self::assertSame('failed', $row?->getStatus());

        // The other caller read the same failed row and claims it first; this
        // one read it before that claim, and still sees it failed.
        self::assertTrue($this->messages->claimForResend($row));
        $this->messages->staleRead = $row;
        $second = $this->service()->send(clone $envelope, $this->now());

        self::assertNull($this->messages->staleRead, 'the second resend read the failed row');
        self::assertSame(MailDispatchStatus::Queued, $second->status);
        self::assertSame(0, $this->fake->count(), 'the second resend delivered nothing');
    }

    /** No reply after the message went out: it may have arrived, and the transport says it is not sent again. */
    #[Test]
    public function a_mail_whose_outcome_is_unknown_is_not_sent_again_under_its_key(): void
    {
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Failed, errorCode: 'unknown_transport_error', errorMessage: 'No reply after the message was sent')));
        $envelope = $this->envelope(subject: 'Receipt', text: 'Paid');
        $envelope->idempotencyKey = 'order-45-receipt';

        $first = $this->service(driver: 'scripted')->send($envelope, $this->now());
        $again = $this->service()->send(clone $envelope, $this->now());

        self::assertSame(MailDispatchStatus::Failed, $again->status);
        self::assertSame('unknown_transport_error', $again->errorCode);
        self::assertSame(0, $this->fake->count());
    }

    #[Test]
    public function a_mail_the_server_refused_for_good_is_not_sent_again_under_its_key(): void
    {
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Failed, errorCode: 'invalid_recipient', errorMessage: '550 no such user')));
        $envelope = $this->envelope(subject: 'Receipt', text: 'Paid');
        $envelope->idempotencyKey = 'order-43-receipt';

        $first = $this->service(driver: 'scripted')->send($envelope, $this->now());
        $again = $this->service()->send(clone $envelope, $this->now());

        self::assertSame(MailDispatchStatus::Failed, $again->status);
        self::assertSame('invalid_recipient', $again->errorCode);
        self::assertSame($first->messageId, $again->messageId);
        self::assertSame(0, $this->fake->count(), 'a refused address is refused again');
    }

    #[Test]
    public function a_mail_deferred_at_once_gets_its_retry_on_the_queue_and_is_not_sent_again(): void
    {
        // A temporary failure of a mail sent at once was never retried; and
        // sent again under its key it would have run a second retry chain.
        $this->spyOnTheQueue();
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Deferred, errorCode: 'provider_4xx', errorMessage: '451 try later')));
        $envelope = $this->envelope(subject: 'Receipt', text: 'Paid');
        $envelope->idempotencyKey = 'order-44-receipt';

        $first = $this->service(driver: 'scripted')->send($envelope, $this->now());
        self::assertSame(MailDispatchStatus::Queued, $first->status, 'a retry is queued');
        self::assertCount(1, MailServiceQueueSpy::$published);
        $retry = json_decode(MailServiceQueueSpy::$published[0][1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $retry['attempts']);
        self::assertSame($first->messageId, $retry['messageId']);

        $again = $this->service()->send(clone $envelope, $this->now());

        self::assertSame(MailDispatchStatus::Queued, $again->status);
        self::assertCount(1, MailServiceQueueSpy::$published, 'no second retry');
        self::assertSame(0, $this->fake->count());
    }

    #[Test]
    public function a_mail_deferred_at_once_whose_retry_cannot_be_queued_is_sent_again_under_its_key(): void
    {
        // Left deferred, every later send() answered queued and nothing
        // delivered it.
        $this->spyOnTheQueue();
        MailServiceQueueSpy::$refuse = true;
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Deferred, errorCode: 'provider_4xx', errorMessage: '451 try later')));
        $envelope = $this->envelope(subject: 'Receipt', text: 'Paid');
        $envelope->idempotencyKey = 'order-46-receipt';

        $first = $this->service(driver: 'scripted')->send($envelope, $this->now());
        self::assertSame('enqueue_failed', $this->messages->byId($first->messageId)?->getStatus());

        MailServiceQueueSpy::$refuse = false;
        $again = $this->service()->send(clone $envelope, $this->now());

        self::assertSame(MailDispatchStatus::Sent, $again->status);
        self::assertSame(1, $this->fake->count());
    }

    #[Test]
    public function without_a_worker_a_deferred_mail_sent_again_is_delivered(): void
    {
        // With EVENTS_ASYNC=0 nothing holds a retry: answered "queued" it
        // would never be delivered.
        putenv('EVENTS_ASYNC=0');
        putenv('EVENTS_TRANSPORT');
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Deferred, errorCode: 'provider_4xx', errorMessage: '451 try later')));
        $envelope = $this->envelope(subject: 'Receipt', text: 'Paid');
        $envelope->idempotencyKey = 'order-45-receipt';

        $first = $this->service(driver: 'scripted')->send($envelope, $this->now());
        // Failed would be resent too (provider_4xx is not final): only a
        // deferred row takes the process-local branch.
        self::assertSame('deferred', $this->messages->byId($first->messageId)?->getStatus());
        $again = $this->service()->send(clone $envelope, $this->now());

        self::assertNotNull($first->messageId);
        self::assertSame($first->messageId, $again->messageId, 'the deferred row is sent, not a new one');
        self::assertSame(MailDispatchStatus::Sent, $again->status);
        self::assertSame(1, $this->fake->count());
        self::assertSame(1, $this->messages->count());
    }

    #[Test]
    public function a_resent_mail_carries_its_stored_attachments_not_the_new_envelopes(): void
    {
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Failed, errorCode: 'network_error', errorMessage: 'refused')));
        $envelope = $this->envelope(subject: 'Invoice', text: 'Attached');
        $envelope->idempotencyKey = 'invoice-7';
        $envelope->attachments = [new \Semitexa\Mail\Domain\Model\AttachmentReference('invoice.pdf', 'application/pdf', inlineContents: '%PDF-1.4')];

        $this->service(driver: 'scripted')->send($envelope, $this->now());
        $recovery = clone $envelope;
        $recovery->attachments = [];
        $this->service()->send($recovery, $this->now());

        $sent = $this->fake->getLastMessage();
        self::assertNotNull($sent);
        self::assertCount(1, $sent->attachments, 'the PDF the row lists is still attached');
    }

    #[Test]
    public function system_mail_with_a_key_sends_once_too(): void
    {
        // Without a tenant the key was never looked up.
        $envelope = $this->envelope(subject: 'Digest', text: 'x');
        $envelope->idempotencyKey = 'digest-2026-10-21';

        $one = $this->service()->send($envelope, $this->now());
        $two = $this->service()->send(clone $envelope, $this->now());

        self::assertSame(1, $this->fake->count());
        self::assertSame($one->messageId, $two->messageId);
        self::assertSame(MailDispatchStatus::Sent, $two->status, 'a sent mail answers sent, not queued');
    }

    #[Test]
    public function a_queued_send_publishes_the_message_and_does_not_deliver_yet(): void
    {
        $this->spyOnTheQueue();

        $result = $this->service()->send($this->envelope(subject: 'Later', text: 'x'), new MailSendOptions(mode: MailSendMode::Queued));

        self::assertSame(MailDispatchStatus::Queued, $result->status);
        self::assertSame(0, $this->fake->count());
        self::assertCount(1, MailServiceQueueSpy::$published);
        self::assertSame('mail', MailServiceQueueSpy::$published[0][0]);
        self::assertStringContainsString((string) $result->messageId, MailServiceQueueSpy::$published[0][1]);
        self::assertSame('queued', $this->messages->byId($result->messageId)?->getStatus());
    }

    /**
     * REGRESSION (tk-queued-mail-sync-without-async). With EVENTS_ASYNC=0 the
     * queue is in-memory, one per process, and no mail:work can read it: a
     * queued mail was marked queued and never sent. Without async events it
     * is sent at once, and the result says sent.
     */
    #[Test]
    public function without_async_events_a_queued_send_is_delivered_at_once(): void
    {
        putenv('EVENTS_ASYNC=0');
        putenv('EVENTS_TRANSPORT');

        $result = $this->service()->send($this->envelope(subject: 'Later', text: 'Hello now'), new MailSendOptions(mode: MailSendMode::Queued));

        self::assertSame(MailDispatchStatus::Sent, $result->status);
        self::assertSame(1, $this->fake->count());
        self::assertSame('Hello now', $this->fake->getLastMessage()?->textBody);
        self::assertSame('sent', $this->messages->byId($result->messageId)?->getStatus());
    }

    #[Test]
    public function a_refused_delivery_is_failed_and_says_why(): void
    {
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Rejected, errorCode: 'mailbox_full', errorMessage: '552 mailbox full')));

        $result = $this->service(driver: 'scripted')->send($this->envelope(subject: 'Nope', text: 'x'), $this->now());

        self::assertSame(MailDispatchStatus::Failed, $result->status);
        self::assertSame('mailbox_full', $result->errorCode);
        self::assertSame('failed', $this->messages->byId($result->messageId)?->getStatus());
        self::assertSame('mailbox_full', $this->attempts->saved[0]->getErrorCode());
    }

    #[Test]
    public function the_worker_delivers_what_the_service_queued(): void
    {
        $this->spyOnTheQueue();
        $queued = $this->service()->send($this->envelope(subject: 'Later', text: 'Hello later'), new MailSendOptions(mode: MailSendMode::Queued));

        $this->worker()->processPayload(MailServiceQueueSpy::$published[0][1]);

        self::assertSame('Later', $this->fake->getLastMessage()?->subject);
        self::assertSame('noreply@example.test', $this->fake->getLastMessage()?->from->email);
        self::assertSame('sent', $this->messages->byId($queued->messageId)?->getStatus());
        self::assertCount(1, $this->attempts->saved);
    }

    #[Test]
    public function the_worker_resolves_the_mailer_the_sender_chose(): void
    {
        // It resolved the tenant's default mailer for every queued mail.
        $this->spyOnTheQueue();
        $envelope = $this->envelope(subject: 'News', text: 'x');
        $envelope->mailerKey = 'marketing';
        $this->service()->send($envelope, new MailSendOptions(mode: MailSendMode::Queued));
        MailServiceConfig::$mailerKeys = [];

        $this->worker()->processPayload(MailServiceQueueSpy::$published[0][1]);

        self::assertSame(['marketing'], MailServiceConfig::$mailerKeys, 'resolved once, with the sender\'s mailer');
    }

    #[Test]
    public function the_reply_to_name_reaches_the_message(): void
    {
        // The address was stored, the name dropped.
        $this->spyOnTheQueue();
        $envelope = $this->envelope(subject: 'Hi', text: 'x');
        $envelope->replyTo = new MailRecipient('support@example.test', 'Support Team');
        $this->service()->send($envelope, new MailSendOptions(mode: MailSendMode::Queued));

        $this->worker()->processPayload(MailServiceQueueSpy::$published[0][1]);

        self::assertSame('Support Team', $this->fake->getLastMessage()?->replyTo?->name);
    }

    #[Test]
    public function a_message_whose_row_is_not_committed_yet_is_looked_at_again_and_a_stale_one_dropped(): void
    {
        // send() in a transaction publishes before the commit: the worker
        // found no row and dropped the mail.
        QueueTransportRegistry::register('mail-test-delayed', new class implements \Semitexa\Core\Queue\QueueTransportFactoryInterface {
            public function create(): \Semitexa\Core\Queue\QueueTransportInterface
            {
                return new MailServiceDelayedQueueSpy();
            }
        });
        putenv('EVENTS_TRANSPORT=mail-test-delayed');
        MailServiceDelayedQueueSpy::$delayed = [];

        $queuedAt = date(DATE_ATOM, time() - 10);
        $this->worker()->processPayload((new QueuedMailMessage(messageId: 'not-committed-yet', queuedAt: $queuedAt))->toJson());
        $this->worker()->processPayload((new QueuedMailMessage(messageId: 'rolled-back', queuedAt: date(DATE_ATOM, time() - 60)))->toJson());

        self::assertCount(1, MailServiceDelayedQueueSpy::$delayed, 'the fresh one only');
        self::assertStringContainsString('not-committed-yet', MailServiceDelayedQueueSpy::$delayed[0][1]);
        self::assertSame(3, MailServiceDelayedQueueSpy::$delayed[0][2]);
        $again = QueuedMailMessage::fromJson(MailServiceDelayedQueueSpy::$delayed[0][1]);
        self::assertSame($queuedAt, $again->queuedAt, 'queuedAt kept, so the looking stops');
    }

    /**
     * A redelivery while another worker is on the mail — or was, and died —
     * was acknowledged as "being sent", and a crashed worker's mail was lost.
     * It is looked at again once that worker would be presumed dead.
     */
    #[Test]
    public function a_mail_another_worker_holds_is_looked_at_again_after_the_takeover_window(): void
    {
        QueueTransportRegistry::register('mail-test-delayed', new class implements \Semitexa\Core\Queue\QueueTransportFactoryInterface {
            public function create(): \Semitexa\Core\Queue\QueueTransportInterface
            {
                return new MailServiceDelayedQueueSpy();
            }
        });
        putenv('EVENTS_TRANSPORT=mail-test-delayed');
        MailServiceDelayedQueueSpy::$delayed = [];
        $id = $this->queuedMessage();
        $this->messages->heldByAnotherWorker[$id] = true;

        $this->worker()->processPayload((new QueuedMailMessage(messageId: $id))->toJson());

        self::assertSame(0, $this->fake->count(), 'not sent beside the other worker');
        self::assertCount(1, MailServiceDelayedQueueSpy::$delayed);
        self::assertSame(\Semitexa\Mail\Application\Service\MailService::ABANDONED_AFTER_SECONDS + 5, MailServiceDelayedQueueSpy::$delayed[0][2]);
        $copy = QueuedMailMessage::fromJson(MailServiceDelayedQueueSpy::$delayed[0][1]);
        self::assertTrue($copy->takeoverOnly);
        self::assertSame(0, $copy->attempts, 'the same attempt, not one more');

        // The holder ended it as deferred meanwhile: its own retry stands, the copy sends nothing.
        unset($this->messages->heldByAnotherWorker[$id]);
        $this->messages->save($this->messages->byId($id)->with(['status' => 'deferred']));
        $this->worker()->processPayload($copy->toJson());
        self::assertSame(0, $this->fake->count());
        self::assertSame('deferred', $this->messages->byId($id)?->getStatus());

        // Still `sending` (the holder died): taken over and sent.
        $this->messages->save($this->messages->byId($id)->with(['status' => 'sending']));
        $this->worker()->processPayload($copy->toJson());
        self::assertSame(1, $this->fake->count());
    }

    #[Test]
    public function a_redelivered_queue_message_does_not_send_twice(): void
    {
        $this->spyOnTheQueue();
        $this->service()->send($this->envelope(subject: 'Once', text: 'x'), new MailSendOptions(mode: MailSendMode::Queued));
        $payload = MailServiceQueueSpy::$published[0][1];

        $this->worker()->processPayload($payload);
        $this->worker()->processPayload($payload);

        self::assertSame(1, $this->fake->count(), 'at-least-once delivery from the queue must not mean two emails');
    }

    #[Test]
    public function a_temporary_failure_goes_back_on_the_queue_with_one_more_attempt(): void
    {
        $this->spyOnTheQueue();
        $id = $this->queuedMessage();
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Failed, errorCode: 'timeout', errorMessage: 'SMTP timed out')));

        $this->worker(driver: 'scripted')->processPayload((new QueuedMailMessage(messageId: $id, attempts: 0, maxRetries: 2, retryDelay: 0))->toJson());

        self::assertSame('deferred', $this->messages->byId($id)?->getStatus());
        self::assertCount(1, MailServiceQueueSpy::$published);
        self::assertSame('mail', MailServiceQueueSpy::$published[0][0]);
        self::assertSame(1, QueuedMailMessage::fromJson(MailServiceQueueSpy::$published[0][1])->attempts);
    }

    #[Test]
    public function a_retry_that_cannot_be_queued_is_not_left_deferred(): void
    {
        // The row said deferred with nothing left to deliver it.
        $this->spyOnTheQueue();
        $id = $this->queuedMessage();
        MailServiceQueueSpy::$refuse = true;
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Failed, errorCode: 'timeout', errorMessage: 'SMTP timed out')));

        $this->worker(driver: 'scripted')->processPayload((new QueuedMailMessage(messageId: $id, attempts: 0, maxRetries: 2, retryDelay: 0))->toJson());

        self::assertSame('enqueue_failed', $this->messages->byId($id)?->getStatus());
        self::assertSame('queue_unavailable', $this->messages->byId($id)?->getErrorCode());
    }

    #[Test]
    public function the_worker_counts_what_it_sent_retried_and_failed_for_queue_stats(): void
    {
        // mail:work recorded nothing, so queue:stats and the Queues app
        // listed the mail queue with no throughput at all.
        $store = new class implements QueueStatsStoreInterface {
            /** @var list<array<string, int>> */
            public array $added = [];
            public function add(string $queue, int $minute, array $deltas): void { $this->added[] = ['queue' => $queue] + $deltas; }
            public function sum(string $queue, int $fromMinute, int $toMinute): array { return []; }
            public function describe(): string { return 'test'; }
        };
        $counted = function (string $driver) use ($store): MailWorker {
            return $this->worker($driver)->withStats(new QueueStatsRecorder($store), 'mail');
        };
        $this->spyOnTheQueue();

        $sent = $this->queuedMessage();
        $counted('fake')->processPayload((new QueuedMailMessage(messageId: $sent))->toJson());
        $counted('fake')->processPayload((new QueuedMailMessage(messageId: $sent))->toJson()); // already sent: nothing
        $retried = $this->queuedMessage();
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Failed, errorCode: 'timeout', errorMessage: 'SMTP timed out')));
        $counted('scripted')->processPayload((new QueuedMailMessage(messageId: $retried, attempts: 0, maxRetries: 2, retryDelay: 0))->toJson());
        $failed = $this->queuedMessage();
        $counted('no-such-driver')->processPayload((new QueuedMailMessage(messageId: $failed, attempts: 1))->toJson());
        $unqueued = $this->queuedMessage();
        MailServiceQueueSpy::$refuse = true;
        $counted('scripted')->processPayload((new QueuedMailMessage(messageId: $unqueued, attempts: 0, maxRetries: 2, retryDelay: 0))->toJson());

        self::assertCount(4, $store->added);
        [$a, $b, $c, $d] = $store->added;
        self::assertSame(['mail', 1], [$a['queue'], $a['processed'] ?? null]);
        self::assertSame(1, $b['retried'] ?? null);
        self::assertArrayNotHasKey('processed', $b);
        self::assertSame(1, $c['dead_lettered'] ?? null);
        self::assertSame(1, $d['dead_lettered'] ?? null, 'a retry that could not be queued failed for good');
        self::assertArrayNotHasKey('wait_count', $a, 'queuedAt has whole seconds: no wait is better than a wrong one');
    }

    #[Test]
    public function an_unknown_driver_fails_the_mail_instead_of_leaving_it_sending(): void
    {
        $this->spyOnTheQueue();
        $id = $this->queuedMessage();

        $this->worker(driver: 'no-such-driver')->processPayload((new QueuedMailMessage(messageId: $id))->toJson());
        $sync = $this->service(driver: 'no-such-driver')->send($this->envelope(subject: 'x', text: 'y'), $this->now());

        self::assertSame('failed', $this->messages->byId($id)?->getStatus());
        self::assertSame('config_error', $this->messages->byId($id)?->getErrorCode());
        self::assertSame(MailDispatchStatus::Failed, $sync->status);
        self::assertSame('failed', $this->messages->byId($sync->messageId)?->getStatus());
    }

    #[Test]
    public function with_mail_retention_set_the_worker_sweeps_old_mail_at_most_hourly(): void
    {
        $retention = new class implements \Semitexa\Mail\Domain\Contract\MailRetentionInterface {
            /** @var list<\DateTimeImmutable> */
            public array $cutoffs = [];

            public function deleteFinishedBefore(\DateTimeImmutable $cutoff, int $limit): int
            {
                $this->cutoffs[] = $cutoff;

                return 0;
            }
        };
        $worker = new MailWorker($this->messages, $this->attempts, $this->config('fake'), (new \ReflectionClass(AttachmentResolver::class))->newInstanceWithoutConstructor(), $retention);
        $worker->setOutput(new NullOutput());
        $previous = getenv('MAIL_RETENTION_DAYS');
        putenv('MAIL_RETENTION_DAYS=30');
        try {
            $worker->processPayload('{"type":"other"}');
            $worker->processPayload('{"type":"other"}');
        } finally {
            $previous === false ? putenv('MAIL_RETENTION_DAYS') : putenv("MAIL_RETENTION_DAYS={$previous}");
        }

        self::assertCount(1, $retention->cutoffs, 'once, not on every message');
        self::assertEqualsWithDelta((new \DateTimeImmutable('-30 days'))->getTimestamp(), $retention->cutoffs[0]->getTimestamp(), 5);
    }

    /** @return iterable<string, array{string, int}> */
    public static function overwrites(): iterable
    {
        // [error code the slow worker gets, guarded write the fast one beats]
        yield 'sent before the slow worker marks it sending' => ['timeout', 1];
        yield 'sent before the slow worker writes deferred' => ['timeout', 2];
        yield 'sent before the slow worker writes failed' => ['invalid_recipient', 2];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('overwrites')]
    public function a_slower_worker_never_overwrites_a_sent_another_worker_wrote(string $errorCode, int $at): void
    {
        // A redelivered message processed twice at once: the slower worker's
        // sending, deferred or failed landed over the faster one's sent.
        $this->spyOnTheQueue();
        $id = $this->queuedMessage();
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Failed, errorCode: $errorCode, errorMessage: 'refused')));
        $this->messages->meanwhileAt = $at;
        $this->messages->meanwhile = static function (MailServiceMessages $messages) use ($id): void {
            $row = $messages->byId($id);
            if ($row !== null) {
                $messages->save($row->with(['status' => 'sent']));
            }
        };

        $this->worker(driver: 'scripted')->processPayload((new QueuedMailMessage(messageId: $id, attempts: 0, maxRetries: 2, retryDelay: 0))->toJson());

        self::assertSame('sent', $this->messages->byId($id)?->getStatus());
        self::assertSame([], MailServiceQueueSpy::$published, 'no retry for a mail already sent');
    }

    #[Test]
    public function a_permanent_failure_is_not_retried(): void
    {
        $this->spyOnTheQueue();
        $id = $this->queuedMessage();
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Rejected, errorCode: 'invalid_recipient', errorMessage: '550 no such user')));

        $this->worker(driver: 'scripted')->processPayload((new QueuedMailMessage(messageId: $id, maxRetries: 2, retryDelay: 0))->toJson());

        self::assertSame('failed', $this->messages->byId($id)?->getStatus());
        self::assertSame('invalid_recipient', $this->messages->byId($id)?->getErrorCode());
        self::assertSame([], MailServiceQueueSpy::$published);
    }

    #[Test]
    public function a_temporary_failure_on_the_last_attempt_fails_for_good(): void
    {
        $this->spyOnTheQueue();
        $id = $this->queuedMessage();
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Failed, errorCode: 'timeout', errorMessage: 'SMTP timed out')));

        $this->worker(driver: 'scripted')->processPayload((new QueuedMailMessage(messageId: $id, attempts: 2, maxRetries: 2, retryDelay: 0))->toJson());

        self::assertSame('failed', $this->messages->byId($id)?->getStatus());
        self::assertSame([], MailServiceQueueSpy::$published);
    }

    /** A takeover copy's retry kept its mark, met the `deferred` row its own worker wrote, and stopped there: never tried again. */
    #[Test]
    public function the_retry_of_a_takeover_copy_is_delivered(): void
    {
        $this->spyOnTheQueue();
        $id = $this->queuedMessage();
        // The worker that held it died mid-send.
        $this->messages->save(($this->messages->byId($id) ?? self::fail('not stored'))->with(['status' => 'sending']));
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Failed, errorCode: 'timeout', errorMessage: 'SMTP timed out')));

        $this->worker(driver: 'scripted')->processPayload((new QueuedMailMessage(messageId: $id, maxRetries: 2, retryDelay: 0, takeoverOnly: true))->toJson());

        self::assertSame('deferred', $this->messages->byId($id)?->getStatus());
        self::assertCount(1, MailServiceQueueSpy::$published);
        $retry = QueuedMailMessage::fromJson(MailServiceQueueSpy::$published[0][1]);
        self::assertFalse($retry->takeoverOnly, 'the retry is this worker\'s own');
        self::assertSame(1, $retry->attempts);

        $this->worker()->processPayload($retry->toJson());

        self::assertSame(1, $this->fake->count());
        self::assertSame('sent', $this->messages->byId($id)?->getStatus());
    }

    /** A partial refusal's warning named every refused address and the server's reply in the log. */
    #[Test]
    public function a_partial_refusal_is_logged_as_a_count_not_the_addresses(): void
    {
        $partial = new MailTransportResult(
            status: MailTransportStatus::Accepted,
            errorMessage: 'Not delivered to: typo@nowhere.test (550 5.1.1 no such user)',
            providerStatus: 'sent_partially',
            providerResponse: ['refused_recipients' => [['email' => 'typo@nowhere.test', 'smtp_code' => 550, 'smtp_response' => '550 5.1.1 no such user']]],
        );
        MailTransportRegistry::register(new MailServiceScriptedTransport($partial));
        $logged = new MailServiceLog();
        \Semitexa\Core\Log\StaticLoggerBridge::set($logged);

        $sync = $this->service(driver: 'scripted')->send($this->envelope(subject: 'Hi', text: 'x'), $this->now());
        $this->spyOnTheQueue();
        $id = $this->queuedMessage();
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $worker = $this->worker(driver: 'scripted');
        $worker->setOutput($output);
        $worker->processPayload((new QueuedMailMessage(messageId: $id))->toJson());

        self::assertSame(["Mail '{$sync->messageId}': 1 recipient(s) refused; who and why is on the mail row."], $logged->warnings);
        self::assertStringContainsString("Mail '{$id}': 1 recipient(s) refused; who and why is on the mail row.", $output->fetch());
        self::assertSame($partial->errorMessage, $this->messages->byId($id)?->getErrorMessage(), 'the row still names them');
    }

    /** @return array<string, mixed> every static property of $class, as it is now */
    private static function statics(string $class): array
    {
        $values = [];
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            $values[$property->getName()] = $property->getValue();
        }

        return $values;
    }

    private function spyOnTheQueue(): void
    {
        QueueTransportRegistry::register('mail-test-spy', new MailServiceQueueSpyFactory());
        putenv('EVENTS_TRANSPORT=mail-test-spy');
    }

    /** A message as MailService leaves it in the store; what it published is cleared. */
    private function queuedMessage(): string
    {
        $id = (string) $this->service()->send($this->envelope(subject: 'Retry me', text: 'x'), new MailSendOptions(mode: MailSendMode::Queued))->messageId;
        MailServiceQueueSpy::$published = [];

        return $id;
    }

    private function worker(string $driver = 'fake'): MailWorker
    {
        $worker = new MailWorker($this->messages, $this->attempts, $this->config($driver), (new \ReflectionClass(AttachmentResolver::class))->newInstanceWithoutConstructor());
        $worker->setOutput(new NullOutput());

        return $worker;
    }

    private function config(string $driver): MailServiceConfig
    {
        return new MailServiceConfig(new MailerConfig(driver: $driver, fromEmail: 'noreply@example.test', queue: 'mail'));
    }

    /** Delivered in the call: MailSendOptions queues unless told otherwise. */
    private function now(): MailSendOptions
    {
        return new MailSendOptions(mode: MailSendMode::Sync);
    }

    private function service(string $driver = 'fake', ?OrmManager $orm = null): MailService
    {
        $service = new MailService();
        foreach ([
            'configResolver' => $this->config($driver),
            'renderer' => new MailServiceRenderer(),
            'mailRepository' => $this->messages,
            'attemptRepository' => $this->attempts,
            'attachmentResolver' => (new \ReflectionClass(AttachmentResolver::class))->newInstanceWithoutConstructor(),
            ...($orm !== null ? ['orm' => $orm] : []),
        ] as $property => $value) {
            (new \ReflectionProperty(MailService::class, $property))->setValue($service, $value);
        }

        return $service;
    }

    private function envelope(?string $subject = null, ?string $text = null): MailEnvelope
    {
        $envelope = new MailEnvelope();
        $envelope->to = [new MailRecipient('ada@example.test', 'Ada')];
        $envelope->subject = $subject;
        $envelope->textBody = $text;

        return $envelope;
    }
}

final class MailServiceConfig implements MailerConfigResolverInterface
{
    public function __construct(private readonly MailerConfig $config)
    {
    }

    /** @var list<?string> the mailerKey of every resolve() */
    public static array $mailerKeys = [];

    public function resolve(?string $tenantId = null, ?string $mailerKey = null): MailerConfig
    {
        self::$mailerKeys[] = $mailerKey;

        return $this->config;
    }
}

final class MailServiceRenderer implements MailTemplateRendererInterface
{
    /** @var array{0: ?string, 1: ?string}|null whom feature() answered for in the last render */
    public static ?array $flagSubject = null;

    public function render(string $templateHandle, array $variables, ?string $locale = null): array
    {
        self::$flagSubject = \Semitexa\Core\Feature\CurrentFeatureFlags::subject();

        return ['subject' => 'Welcome, ' . $variables['name'], 'htmlBody' => '<p>Hi ' . $variables['name'] . '</p>', 'textBody' => 'Hi ' . $variables['name']];
    }
}

final class MailServiceMessages implements MailRepositoryInterface, MailRecoveryInterface, \Semitexa\Mail\Domain\Contract\MailSendingClaimInterface, \Semitexa\Mail\Domain\Contract\MailOutcomeGuardInterface, \Semitexa\Mail\Domain\Contract\MailResendClaimInterface
{
    /** @var array<string, MailMessage> */
    private array $byId = [];

    /** @var array<string, true> rows another (live) worker is sending */
    public array $heldByAnotherWorker = [];

    public function claimForSending(MailMessage $message, \DateTimeImmutable $staleBefore): bool
    {
        $stored = $this->byId[$message->getId()] ?? null;
        if ($stored === null || $stored->getStatus() === 'sent' || isset($this->heldByAnotherWorker[$message->getId()])) {
            return false;
        }
        $this->byId[$message->getId()] = $stored->with(['status' => 'sending']);

        return true;
    }

    private int $next = 0;

    public function count(): int
    {
        return count($this->byId);
    }

    public function byId(?string $id): ?MailMessage
    {
        return $id === null ? null : ($this->byId[$id] ?? null);
    }

    public function findById(int|string $id): ?MailMessage
    {
        return $this->byId[(string) $id] ?? null;
    }

    /** Answered once by the next key lookup: a row as another caller read it before a change. */
    public ?MailMessage $staleRead = null;

    public function findByIdempotencyKey(?string $tenantId, string $idempotencyKey): ?MailMessage
    {
        if ($this->staleRead !== null) {
            [$stale, $this->staleRead] = [$this->staleRead, null];

            return $stale;
        }
        foreach ($this->byId as $message) {
            // '' is system mail, as the real repository reads it.
            if (($message->getTenantId() ?? '') === ($tenantId ?? '') && $message->getIdempotencyKey() === $idempotencyKey) {
                return $message;
            }
        }

        return null;
    }

    public function save(MailMessage $entity): MailMessage
    {
        $saved = $entity->getId() === '' ? $entity->with(['id' => 'm-' . ++$this->next]) : $entity;
        $this->byId[$saved->getId()] = $saved;

        return $saved;
    }

    /** Called just before guarded write number $meanwhileAt: what another worker does in between. */
    public ?\Closure $meanwhile = null;

    public int $meanwhileAt = 1;

    private int $guardedWrites = 0;

    public function claimAbandoned(MailMessage $message, \DateTimeImmutable $staleBefore): bool
    {
        $row = $this->byId[$message->getId()] ?? null;
        if ($row === null || !in_array($row->getStatus(), ['pending', 'sending'], true) || $row->getUpdatedAt() === null || $row->getUpdatedAt() >= $staleBefore) {
            return false;
        }
        $this->byId[$row->getId()] = $row->with(['status' => 'pending', 'updatedAt' => new \DateTimeImmutable()]);
        if ($this->afterClaim !== null) {
            ($this->afterClaim)($this);
        }

        return true;
    }

    /** What happens right after a successful claim: another delivery reaching the row. */
    public ?\Closure $afterClaim = null;

    public function claimForResend(MailMessage $message): bool
    {
        $row = $this->byId[$message->getId()] ?? null;
        if ($row === null || $row->getStatus() !== $message->getStatus() || $row->getErrorCode() !== $message->getErrorCode()) {
            return false;
        }
        $this->byId[$row->getId()] = $row->with(['status' => 'pending', 'errorCode' => null, 'errorMessage' => null]);

        return true;
    }

    public function takeForDelivery(MailMessage $message): bool
    {
        $row = $this->byId[$message->getId()] ?? null;
        if ($row?->getStatus() !== 'pending') {
            return false;
        }
        $this->byId[$row->getId()] = $row->with(['status' => 'sending']);

        return true;
    }

    public function saveOutcomeUnlessSent(MailMessage $entity): bool
    {
        if ($this->meanwhile !== null && ++$this->guardedWrites === $this->meanwhileAt) {
            ($this->meanwhile)($this);
        }
        if (($this->byId[$entity->getId()] ?? null)?->getStatus() === 'sent') {
            return false;
        }
        $this->byId[$entity->getId()] = $entity;

        return true;
    }
}

final class MailServiceLog implements \Semitexa\Core\Log\LoggerInterface
{
    /** @var list<string> */
    public array $warnings = [];

    public function error(string $message, array $context = []): void {}
    public function critical(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void { $this->warnings[] = $message; }
    public function info(string $message, array $context = []): void {}
    public function notice(string $message, array $context = []): void {}
    public function debug(string $message, array $context = []): void {}
}

final class MailServiceAttempts implements MailAttemptRepositoryInterface
{
    /** @var list<MailAttempt> */
    public array $saved = [];

    public function save(MailAttempt $entity): MailAttempt
    {
        $this->saved[] = $entity;

        return $entity;
    }

    public function findByMessageId(string $messageId): array
    {
        return $this->saved;
    }

    public function countByMessageId(string $messageId): int
    {
        return count($this->saved);
    }
}

final class MailServiceScriptedTransport implements MailTransportInterface
{
    public function __construct(private readonly MailTransportResult $result)
    {
    }

    public function key(): string
    {
        return 'scripted';
    }

    public function deliver(PreparedMailMessage $message, MailerConfig $config): MailTransportResult
    {
        return $this->result;
    }
}

final class MailServiceQueueSpy implements QueueTransportInterface
{
    /** @var list<array{string, string}> */
    public static array $published = [];

    public static bool $refuse = false;

    public function publish(string $queueName, string $payload): void
    {
        if (self::$refuse) {
            throw new \RuntimeException('queue down');
        }
        self::$published[] = [$queueName, $payload];
    }

    public function consume(string $queueName, callable $callback): void
    {
    }
}

final class MailServiceQueueSpyFactory implements QueueTransportFactoryInterface
{
    public function create(): QueueTransportInterface
    {
        return new MailServiceQueueSpy();
    }
}

final class MailServiceDelayedQueueSpy implements \Semitexa\Core\Queue\DelayedQueueTransportInterface
{
    /** @var list<array{string, string, int}> */
    public static array $delayed = [];

    public function publish(string $queueName, string $payload): void
    {
    }

    public function publishDelayed(string $queueName, string $payload, int $delaySeconds): void
    {
        self::$delayed[] = [$queueName, $payload, $delaySeconds];
    }

    public function consume(string $queueName, callable $callback): void
    {
    }
}
