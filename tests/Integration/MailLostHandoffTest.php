<?php

declare(strict_types=1);

namespace Semitexa\Mail\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Mail\Application\Db\MySQL\Repository\MailMessageRepository;
use Semitexa\Mail\Domain\Model\MailMessage;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\OrmManager;

/**
 * orm-gap-7-package-read-apis: a mail the queue accepted and then lost sits
 * `queued` with no attempt. A project rescuing such mails read mail_messages and
 * mail_attempts itself and wrote the row by its binary id; the repository now
 * lists them and lets exactly one sweep take each, against the MySQL test database.
 */
final class MailLostHandoffTest extends TestCase
{
    /**
     * Older than anything else the shared test database holds, so these rows come
     * first in an oldest-first list however many other lost mails it carries.
     */
    private const LONG_AGO = '1970-01-02 00:00:00';

    private OrmManager $orm;
    private MailMessageRepository $repository;

    /** @var list<string> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->orm = new OrmManager();
        self::assertSame('mysql', $this->orm->getDriver(), 'bin/semitexa test:run runs on MySQL');
        $this->repository = new MailMessageRepository();
    }

    protected function tearDown(): void
    {
        foreach ($this->ids as $id) {
            $this->orm->getAdapter()->execute('DELETE FROM mail_attempts WHERE mail_message_id = :id', ['id' => Uuid7::toBytes($id)]);
            $this->orm->getAdapter()->execute('DELETE FROM mail_messages WHERE id = :id', ['id' => Uuid7::toBytes($id)]);
        }
        $this->orm->shutdown();
    }

    #[Test]
    public function only_unattempted_queued_or_pending_mails_untouched_since_the_cutoff_are_lost(): void
    {
        $queued = $this->stored('queued', 'smtp', self::LONG_AGO);
        $pending = $this->stored('pending', 'smtp', '1970-01-03 00:00:00');
        $attempted = $this->stored('queued', 'smtp', self::LONG_AGO, attempts: 1);
        $sink = $this->stored('queued', 'null', self::LONG_AGO);
        $sent = $this->stored('sent', 'smtp', self::LONG_AGO);
        $recent = $this->stored('queued', 'smtp', null);

        // The order assertion holds only if no other row is older than these: said, not assumed.
        $ours = array_map(static fn (string $id): string => Uuid7::toBytes($id), $this->ids);
        $placeholders = implode(', ', array_map(static fn (int $i): string => ':o' . $i, array_keys($ours)));
        $older = $this->orm->getAdapter()->execute(
            "SELECT COUNT(*) AS c FROM mail_messages WHERE created_at <= '1970-01-03 00:00:00' AND id NOT IN ({$placeholders})",
            array_combine(array_map(static fn (int $i): string => 'o' . $i, array_keys($ours)), $ours),
        )->rows[0]['c'] ?? null;
        self::assertNotNull($older, 'the count came back');
        self::assertSame(0, (int) $older, 'the shared database holds mail rows dated before 1970-01-03 (left by a crashed run of this test?); delete them');

        $listed = array_map(static fn (MailMessage $m): string => $m->getId(), $this->repository->lostHandoffs(new \DateTimeImmutable('-15 minutes'), 500));
        $lost = array_values(array_filter($listed, fn (string $id): bool => in_array($id, $this->ids, true)));

        self::assertSame([$queued->getId(), $pending->getId()], array_slice($listed, 0, 2), 'the oldest lost mails come first');
        self::assertSame([$queued->getId(), $pending->getId()], $lost, 'not the attempted, the null-driver, the sent or the recent one');
        self::assertNotContains($attempted->getId(), $lost);
        self::assertNotContains($sink->getId(), $lost);
        self::assertNotContains($sent->getId(), $lost);
        self::assertNotContains($recent->getId(), $lost);
    }

    #[Test]
    public function exactly_one_sweep_takes_a_lost_mail_and_it_leaves_the_list_for_a_window(): void
    {
        $lost = $this->stored('queued', 'smtp', self::LONG_AGO);
        $cutoff = new \DateTimeImmutable('-15 minutes');

        self::assertTrue($this->repository->claimLostHandoff($lost, $cutoff));
        self::assertFalse($this->repository->claimLostHandoff($lost, $cutoff), 'a second sweep finds it taken');
        $updatedAt = $this->repository->findById($lost->getId())?->getUpdatedAt();
        self::assertNotNull($updatedAt);
        self::assertGreaterThan($cutoff, $updatedAt, 'claimed now: untouched no longer, so the next sweep leaves it');
        self::assertSame('queued', $this->repository->findById($lost->getId())?->getStatus(), 'the claim changes nothing but the time');
    }

    #[Test]
    public function a_mail_the_worker_has_attempted_is_never_taken(): void
    {
        $attempted = $this->stored('queued', 'smtp', self::LONG_AGO, attempts: 1);

        self::assertFalse($this->repository->claimLostHandoff($attempted, new \DateTimeImmutable('-15 minutes')));
    }

    /** created_at and updated_at are UTC: a zone mistake would move the boundary by hours. By id, so other rows cannot matter. */
    #[Test]
    public function the_boundary_is_utc(): void
    {
        $utc = new \DateTimeZone('UTC');
        $older = $this->stored('queued', 'smtp', (new \DateTimeImmutable('-16 minutes', $utc))->format('Y-m-d H:i:s'));
        $newer = $this->stored('queued', 'smtp', (new \DateTimeImmutable('-14 minutes', $utc))->format('Y-m-d H:i:s'));
        $cutoff = new \DateTimeImmutable('-15 minutes', new \DateTimeZone('America/New_York'));

        self::assertFalse($this->repository->claimLostHandoff($newer, $cutoff));
        self::assertTrue($this->repository->claimLostHandoff($older, $cutoff));
    }

    #[Test]
    public function a_cutoff_in_the_future_is_refused_rather_than_letting_two_callers_take_one_mail(): void
    {
        $lost = $this->stored('queued', 'smtp', self::LONG_AGO);

        try {
            $this->repository->claimLostHandoff($lost, new \DateTimeImmutable('+1 day'));
            self::fail('a future cutoff must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('claimLostHandoff() takes a cutoff in the past.', $e->getMessage());
        }
        self::assertTrue($this->repository->claimLostHandoff($lost, new \DateTimeImmutable('-15 minutes')), 'the refused call took nothing');
    }

    /**
     * The shared test database may hold other rows: keep this test's, in the order given.
     *
     * @param list<MailMessage> $messages
     * @return list<string>
     */
    private function ours(array $messages): array
    {
        return array_values(array_filter(
            array_map(static fn (MailMessage $m): string => $m->getId(), $messages),
            fn (string $id): bool => in_array($id, $this->ids, true),
        ));
    }

    private function stored(string $status, string $driver, ?string $untouchedSince, int $attempts = 0): MailMessage
    {
        $row = $this->repository->save(new MailMessage(
            id: '', tenantId: null, status: $status, driver: $driver, fromEmail: 'noreply@example.test', subject: 'Verify',
            to: [['email' => 'ada@example.test', 'name' => 'Ada']], textBody: 'Hello',
        ));
        $this->ids[] = $row->getId();
        if ($untouchedSince !== null) {
            $this->orm->getAdapter()->execute(
                'UPDATE mail_messages SET created_at = :c, updated_at = :u WHERE id = :id',
                ['c' => $untouchedSince, 'u' => $untouchedSince, 'id' => Uuid7::toBytes($row->getId())],
            );
        }
        for ($n = 1; $n <= $attempts; $n++) {
            $this->orm->getAdapter()->execute(
                "INSERT INTO mail_attempts (id, mail_message_id, attempt_no, driver, status) VALUES (:id, :m, :n, :d, 'failed')",
                ['id' => Uuid7::toBytes(Uuid7::generate()), 'm' => Uuid7::toBytes($row->getId()), 'n' => $n, 'd' => $driver],
            );
        }

        return $row;
    }
}
