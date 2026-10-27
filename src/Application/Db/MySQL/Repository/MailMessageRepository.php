<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Mail\Application\Db\MySQL\Model\MailMessageResource;
use Semitexa\Mail\Domain\Model\MailMessage;
use Semitexa\Mail\Domain\Contract\MailRepositoryInterface;
use Semitexa\Mail\Domain\Contract\MailRecoveryInterface;
use Semitexa\Mail\Domain\Contract\MailSendingClaimInterface;
use Semitexa\Mail\Domain\Contract\MailOutcomeGuardInterface;
use Semitexa\Mail\Domain\Contract\MailResendClaimInterface;
use Semitexa\Mail\Domain\Enum\MailMessageStatus;
use Semitexa\Mail\Domain\Contract\MailRetentionInterface;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\SystemScopeToken;
use Semitexa\Orm\Repository\DomainRepository;

#[SatisfiesRepositoryContract(of: MailRepositoryInterface::class)]
class MailMessageRepository implements MailRepositoryInterface, MailRetentionInterface, MailRecoveryInterface, MailSendingClaimInterface, MailOutcomeGuardInterface, MailResendClaimInterface
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

    private ?DomainRepository $repository = null;

    private ?DomainRepository $system = null;

    public function findById(int|string $id): ?MailMessage
    {
        if (!is_string($id)) {
            $id = (string) $id;
        }

        /** @var MailMessage|null */
        return $this->system()->findById($id);
    }

    public function findByIdempotencyKey(?string $tenantId, string $idempotencyKey): ?MailMessage
    {
        if ($tenantId !== null && $tenantId !== '') {
            /** @var MailMessage|null */
            return $this->repository()->forTenant($tenantId)->query()
                ->where(MailMessageResource::column('idempotency_key'), Operator::Equals, $idempotencyKey)
                ->fetchOneAs(MailMessage::class, $this->orm()->getMapperRegistry());
        }

        // System mail (no tenant) is stored with tenant '' (the mapper), which
        // the unique index holds to one row per key; a row from before that
        // has NULL, and is found too.
        /** @var MailMessage|null */
        return $this->system()->query()
            ->where(MailMessageResource::column('tenant_id'), Operator::Equals, '')
            ->where(MailMessageResource::column('idempotency_key'), Operator::Equals, $idempotencyKey)
            ->fetchOneAs(MailMessage::class, $this->orm()->getMapperRegistry())
            ?? $this->system()->query()
                ->whereNull(MailMessageResource::column('tenant_id'))
                ->where(MailMessageResource::column('idempotency_key'), Operator::Equals, $idempotencyKey)
                ->fetchOneAs(MailMessage::class, $this->orm()->getMapperRegistry());
    }

    public function save(MailMessage $entity): MailMessage
    {

        // First save stamps the creation time; every save refreshes the update.
        $now = new \DateTimeImmutable();
        $entity = $entity->with(['createdAt' => $entity->getCreatedAt() ?? $now, 'updatedAt' => $now]);

        /** @var MailMessage */
        return $entity->getId() === ''
            ? $this->system()->insert($entity)
            : $this->system()->update($entity);
    }

    /**
     * SYSTEM-scope view — deliberately cross-tenant: the worker addresses a
     * message by its own unique id (from the queue payload), and writes carry
     * the tenant on the row itself. The resource is #[TenantScoped], so any
     * FUTURE tenant-facing finder must call forTenant(...) (see
     * findByIdempotencyKey) or copy this token-marked posture consciously.
     */
    private function system(): DomainRepository
    {
        return $this->system ??= $this->repository()->withoutTenantScope(SystemScopeToken::issue());
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            MailMessageResource::class,
            MailMessage::class,
        );
    }

    public function saveOutcomeUnlessSent(MailMessage $entity): bool
    {
        $utc = new \DateTimeZone('UTC');
        $id = Uuid7::toBytes($entity->getId());
        $changed = $this->orm()->getQueryAdapter()->execute(
            "UPDATE mail_messages
             SET status = :status, error_code = :error_code, error_message = :error_message,
                 provider_message_id = :provider_message_id,
                 last_attempt_at = COALESCE(:last_attempt_at, last_attempt_at), updated_at = :updated_at
             WHERE id = :id AND status <> 'sent'",
            [
                'status' => $entity->getStatus(),
                'error_code' => $entity->getErrorCode(),
                'error_message' => $entity->getErrorMessage(),
                'provider_message_id' => $entity->getProviderMessageId(),
                'last_attempt_at' => $entity->getLastAttemptAt()?->setTimezone($utc)->format('Y-m-d H:i:s'),
                'updated_at' => (new \DateTimeImmutable('now', $utc))->format('Y-m-d H:i:s'),
                'id' => $id,
            ],
        )->rowCount;
        if ($changed > 0) {
            return true;
        }

        // MySQL counts changed rows: 0 is also "the same values written
        // again". Only a row that is sent was refused.
        $row = $this->orm()->getQueryAdapter()->execute('SELECT status FROM mail_messages WHERE id = :id', ['id' => $id])->rows[0] ?? null;

        return $row !== null && $row['status'] !== 'sent';
    }

    public function claimForResend(MailMessage $message): bool
    {
        return $this->orm()->getQueryAdapter()->execute(
            'UPDATE mail_messages SET status = :pending, error_code = NULL, error_message = NULL, updated_at = :now WHERE id = :id AND status = :seen',
            [
                'pending' => MailMessageStatus::Pending->value,
                'now' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'id' => Uuid7::toBytes($message->getId()),
                'seen' => $message->getStatus(),
            ],
        )->rowCount === 1;
    }

    public function claimAbandoned(MailMessage $message, \DateTimeImmutable $staleBefore): bool
    {
        $utc = new \DateTimeZone('UTC');

        return $this->orm()->getQueryAdapter()->execute(
            "UPDATE mail_messages SET status = 'pending', updated_at = :now
             WHERE id = :id AND status IN ('pending', 'sending') AND updated_at < :stale",
            [
                'now' => (new \DateTimeImmutable('now', $utc))->format('Y-m-d H:i:s'),
                'id' => Uuid7::toBytes($message->getId()),
                'stale' => $staleBefore->setTimezone($utc)->format('Y-m-d H:i:s'),
            ],
        )->rowCount === 1;
    }

    public function claimForSending(MailMessage $message, \DateTimeImmutable $staleBefore): bool
    {
        $utc = new \DateTimeZone('UTC');
        $now = (new \DateTimeImmutable('now', $utc))->format('Y-m-d H:i:s');

        return $this->orm()->getQueryAdapter()->execute(
            "UPDATE mail_messages SET status = 'sending', last_attempt_at = :now_attempt, updated_at = :now
             WHERE id = :id AND status <> 'sent' AND (status <> 'sending' OR updated_at < :stale)",
            [
                'now_attempt' => $now,
                'now' => $now,
                'stale' => $staleBefore->setTimezone($utc)->format('Y-m-d H:i:s'),
                'id' => Uuid7::toBytes($message->getId()),
            ],
        )->rowCount === 1;
    }

    public function takeForDelivery(MailMessage $message): bool
    {
        return $this->orm()->getQueryAdapter()->execute(
            "UPDATE mail_messages SET status = 'sending', updated_at = :now WHERE id = :id AND status = 'pending'",
            [
                'now' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'id' => Uuid7::toBytes($message->getId()),
            ],
        )->rowCount === 1;
    }

    public function deleteFinishedBefore(\DateTimeImmutable $cutoff, int $limit): int
    {
        $adapter = $this->orm()->getQueryAdapter();
        $finished = "'sent', 'failed'";
        // Through idx_mail_messages_status_updated: without it, every sweep
        // read every finished row in the retained window.
        $ids = array_column($adapter->execute(
            "SELECT id FROM mail_messages WHERE status IN ({$finished}) AND updated_at < :cutoff LIMIT " . max(1, min($limit, 500)),
            ['cutoff' => $cutoff->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        )->rows, 'id');
        if ($ids === []) {
            return 0;
        }

        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $params["id{$i}"] = $id;
        }
        $in = ':' . implode(', :', array_keys($params));
        $adapter->execute("DELETE FROM mail_attempts WHERE mail_message_id IN ({$in})", $params);

        return $adapter->execute(
            "DELETE FROM mail_messages WHERE id IN ({$in}) AND status IN ({$finished}) AND updated_at < :cutoff",
            $params + ['cutoff' => $cutoff->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        )->rowCount;
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }

}
