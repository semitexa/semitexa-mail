<?php

declare(strict_types=1);

namespace Semitexa\Mail\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Mail\Application\Db\MySQL\Repository\MailMessageRepository;
use Semitexa\Mail\Domain\Model\MailMessage;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\OrmManager;

/** The claim a resend makes on a row nobody is delivering, against the MySQL test database. */
final class MailAbandonedRowClaimTest extends TestCase
{
    private OrmManager $orm;

    /** @var list<string> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->orm = new OrmManager();
        self::assertSame('mysql', $this->orm->getDriver(), 'bin/semitexa test:run runs on MySQL');
    }

    protected function tearDown(): void
    {
        foreach ($this->ids as $id) {
            $this->orm->getAdapter()->execute('DELETE FROM mail_messages WHERE id = :id', ['id' => Uuid7::toBytes($id)]);
        }
        $this->orm->shutdown();
    }

    #[Test]
    public function exactly_one_claim_takes_a_stale_row_and_none_takes_a_fresh_one(): void
    {
        $repository = new MailMessageRepository();
        $stale = $this->stored($repository, 'sending', '2001-01-01 00:00:00');
        $fresh = $this->stored($repository, 'pending', null);
        $sent = $this->stored($repository, 'sent', '2001-01-01 00:00:00');
        $cutoff = new \DateTimeImmutable('-15 minutes');

        self::assertTrue($repository->claimAbandoned($stale, $cutoff));
        self::assertFalse($repository->claimAbandoned($stale, $cutoff), 'the second resend finds it taken');
        self::assertSame('pending', $repository->findById($stale->getId())?->getStatus());
        self::assertFalse($repository->claimAbandoned($fresh, $cutoff), 'a row changed a moment ago may still be on its way');
        self::assertFalse($repository->claimAbandoned($sent, $cutoff), 'a sent row is never taken');
    }

    /** updated_at is UTC: a zone mistake would move the boundary by hours. */
    #[Test]
    public function the_boundary_is_fifteen_minutes_of_utc(): void
    {
        $repository = new MailMessageRepository();
        $utc = new \DateTimeZone('UTC');
        $older = $this->stored($repository, 'pending', (new \DateTimeImmutable('-16 minutes', $utc))->format('Y-m-d H:i:s'));
        $newer = $this->stored($repository, 'pending', (new \DateTimeImmutable('-14 minutes', $utc))->format('Y-m-d H:i:s'));
        $cutoff = new \DateTimeImmutable('-15 minutes');

        self::assertTrue($repository->claimAbandoned($older, $cutoff));
        self::assertFalse($repository->claimAbandoned($newer, $cutoff));
    }

    #[Test]
    public function a_held_back_delivery_takes_only_a_pending_row(): void
    {
        $repository = new MailMessageRepository();
        $pending = $this->stored($repository, 'pending', null);
        $sent = $this->stored($repository, 'sent', null);

        self::assertTrue($repository->takeForDelivery($pending));
        self::assertSame('sending', $repository->findById($pending->getId())?->getStatus());
        self::assertFalse($repository->takeForDelivery($pending), 'taken once');
        self::assertFalse($repository->takeForDelivery($sent));
    }

    /**
     * A redelivery while the first worker was still sending moved the row to
     * `sending` again and delivered the mail twice: one worker at a time.
     */
    #[Test]
    public function one_worker_at_a_time_moves_a_mail_to_sending(): void
    {
        $repository = new MailMessageRepository();
        $queued = $this->stored($repository, 'queued', null);
        $abandoned = $this->stored($repository, 'sending', '2001-01-01 00:00:00');
        $sent = $this->stored($repository, 'sent', null);
        $cutoff = new \DateTimeImmutable('-15 minutes');

        self::assertTrue($repository->claimForSending($queued, $cutoff));
        self::assertSame('sending', $repository->findById($queued->getId())?->getStatus());
        self::assertFalse($repository->claimForSending($queued, $cutoff), 'another worker is on it');
        self::assertTrue($repository->claimForSending($abandoned, $cutoff), 'a worker that died is taken over');
        self::assertFalse($repository->claimForSending($sent, $cutoff));
    }

    /**
     * Two keyed sends read the same failed row. The first took it, sent it
     * and stored an outcome it may not repeat; the second, still holding its
     * old read, matched on `failed` alone and sent the mail a second time.
     */
    #[Test]
    public function a_resend_whose_read_predates_another_resends_outcome_takes_nothing(): void
    {
        $repository = new MailMessageRepository();
        $row = $this->stored($repository, 'failed', null);
        $repository->save($row->with(['errorCode' => 'provider_4xx', 'errorMessage' => '451 Try again later']));
        $read = $repository->findById($row->getId());
        self::assertNotNull($read);
        $staleRead = clone $read;

        self::assertTrue($repository->claimForResend($read));
        self::assertTrue($repository->saveOutcomeUnlessSent($read->with([
            'status' => 'failed', 'errorCode' => 'unknown_transport_error', 'errorMessage' => 'No reply after the message was sent',
        ])));

        self::assertFalse($repository->claimForResend($staleRead), 'the second resend saw provider_4xx, the row says unknown_transport_error');
        $stored = $repository->findById($row->getId());
        self::assertNotNull($stored);
        self::assertSame('failed', $stored->getStatus());
        self::assertSame('unknown_transport_error', $stored->getErrorCode());
        self::assertSame('No reply after the message was sent', $stored->getErrorMessage());
    }

    /** A row with no error code is claimed as before: NULL is matched, not skipped. */
    #[Test]
    public function a_deferred_row_without_an_error_code_is_claimed_once(): void
    {
        $repository = new MailMessageRepository();
        $row = $this->stored($repository, 'deferred', null);
        self::assertNull($row->getErrorCode());

        self::assertTrue($repository->claimForResend($row));
        self::assertSame('pending', $repository->findById($row->getId())?->getStatus());
        self::assertFalse($repository->claimForResend($row), 'taken once');
    }

    private function stored(MailMessageRepository $repository, string $status, ?string $updatedAt): MailMessage
    {
        $row = $repository->save(new MailMessage(
            id: '', tenantId: null, status: $status, driver: 'fake', fromEmail: 'noreply@example.test', subject: 'Receipt',
            to: [['email' => 'ada@example.test', 'name' => 'Ada']], textBody: 'Paid',
        ));
        $this->ids[] = $row->getId();
        if ($updatedAt !== null) {
            $this->orm->getAdapter()->execute('UPDATE mail_messages SET updated_at = :u WHERE id = :id', ['u' => $updatedAt, 'id' => Uuid7::toBytes($row->getId())]);
        }

        return $row;
    }
}
