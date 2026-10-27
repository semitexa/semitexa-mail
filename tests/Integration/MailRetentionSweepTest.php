<?php

declare(strict_types=1);

namespace Semitexa\Mail\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Mail\Application\Db\MySQL\Repository\MailAttemptRepository;
use Semitexa\Mail\Application\Db\MySQL\Repository\MailMessageRepository;
use Semitexa\Mail\Domain\Model\MailAttempt;
use Semitexa\Mail\Domain\Model\MailMessage;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\OrmManager;

/**
 * The retention sweep deleted a mail's attempts before the mail itself, and
 * the mail only if it was still finished: one resent in between kept its row
 * and lost its history. Against the MySQL test database.
 */
final class MailRetentionSweepTest extends TestCase
{
    private const DRIVER = 'retention-race-test';

    private const TRIGGER = 'mail_retention_race_test';

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
        $this->orm->getAdapter()->execute('DROP TRIGGER IF EXISTS ' . self::TRIGGER);
        foreach ($this->ids as $id) {
            $this->orm->getAdapter()->execute('DELETE FROM mail_attempts WHERE mail_message_id = :id', ['id' => Uuid7::toBytes($id)]);
            $this->orm->getAdapter()->execute('DELETE FROM mail_messages WHERE id = :id', ['id' => Uuid7::toBytes($id)]);
        }
        $this->orm->shutdown();
    }

    #[Test]
    public function a_mail_and_its_attempts_go_together(): void
    {
        $repository = new MailMessageRepository();
        $finished = $this->stored($repository, 'failed');
        $live = $this->stored($repository, 'deferred');
        // A resend of the finished mail lands while the sweep is at work: as
        // soon as anything deletes its attempts, it is `deferred` again.
        $this->orm->getAdapter()->execute(
            'CREATE TRIGGER ' . self::TRIGGER . " BEFORE DELETE ON mail_attempts FOR EACH ROW
             UPDATE mail_messages SET status = 'deferred' WHERE id = OLD.mail_message_id AND driver = '" . self::DRIVER . "'",
        );

        $deleted = $repository->deleteFinishedBefore(new \DateTimeImmutable('2000-01-01 00:00:00', new \DateTimeZone('UTC')), 500);

        self::assertSame(1, $deleted);
        self::assertSame([null, 0], [$repository->findById($finished->getId())?->getStatus(), $this->attempts($finished)], 'the finished mail went, with its attempts');
        self::assertSame(['deferred', 1], [$repository->findById($live->getId())?->getStatus(), $this->attempts($live)], 'a mail still on its way keeps its history');
    }

    private function stored(MailMessageRepository $repository, string $status): MailMessage
    {
        $row = $repository->save(new MailMessage(
            id: '', tenantId: null, status: $status, driver: self::DRIVER, fromEmail: 'noreply@example.test', subject: 'Receipt',
            to: [['email' => 'ada@example.test', 'name' => 'Ada']], textBody: 'Paid',
        ));
        $this->ids[] = $row->getId();
        (new MailAttemptRepository())->save(new MailAttempt(
            id: '', tenantId: null, mailMessageId: $row->getId(), attemptNo: 1, driver: self::DRIVER, status: 'failed',
        ));
        $this->orm->getAdapter()->execute("UPDATE mail_messages SET updated_at = '1999-01-01 00:00:00' WHERE id = :id", ['id' => Uuid7::toBytes($row->getId())]);

        return $row;
    }

    private function attempts(MailMessage $row): int
    {
        return (int) ($this->orm->getAdapter()->execute(
            'SELECT COUNT(*) AS n FROM mail_attempts WHERE mail_message_id = :id',
            ['id' => Uuid7::toBytes($row->getId())],
        )->rows[0]['n'] ?? -1);
    }
}
