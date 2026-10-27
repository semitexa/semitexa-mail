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
 * The unique (tenant_id, idempotency_key) index let any number of NULL
 * tenants through: two concurrent sends of one key without a tenant were both
 * stored and both delivered. Against the MySQL test database.
 */
final class MailTenantlessKeyTest extends TestCase
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
        $this->orm->getAdapter()->execute("DELETE FROM mail_messages WHERE idempotency_key LIKE 'tenantless-test-%'");
        $this->orm->shutdown();
    }

    #[Test]
    public function one_key_without_a_tenant_is_stored_once_and_found_with_no_tenant(): void
    {
        $repository = new MailMessageRepository();
        $key = 'tenantless-test-' . bin2hex(random_bytes(4));
        $first = $repository->save($this->message($key));
        $this->ids[] = $first->getId();

        self::assertNull($repository->findById($first->getId())?->getTenantId(), 'still no tenant in the domain');
        self::assertSame($first->getId(), $repository->findByIdempotencyKey(null, $key)?->getId());

        $this->expectException(\Semitexa\Orm\Exception\ConstraintViolationException::class);
        $repository->save($this->message($key));
    }

    #[Test]
    public function a_row_stored_before_with_a_null_tenant_is_still_found_by_its_key(): void
    {
        $repository = new MailMessageRepository();
        $key = 'tenantless-test-' . bin2hex(random_bytes(4));
        $row = $repository->save($this->message($key));
        $this->ids[] = $row->getId();
        $this->orm->getAdapter()->execute('UPDATE mail_messages SET tenant_id = NULL WHERE id = :id', ['id' => Uuid7::toBytes($row->getId())]);

        self::assertSame($row->getId(), $repository->findByIdempotencyKey(null, $key)?->getId());
    }

    private function message(string $key): MailMessage
    {
        return new MailMessage(
            id: '', tenantId: null, status: 'pending', driver: 'fake', fromEmail: 'noreply@example.test', subject: 'Receipt',
            to: [['email' => 'ada@example.test', 'name' => 'Ada']], textBody: 'Paid', idempotencyKey: $key,
        );
    }
}
