<?php

declare(strict_types=1);

namespace Semitexa\Mail\Queue\Message;

final class QueuedMailMessage implements \JsonSerializable
{
    public const string TYPE = 'mail';

    public function __construct(
        public string $messageId,
        public string $queuedAt = '',
        public int $attempts = 0,
        public int $maxRetries = 3,
        public int $retryDelay = 60,
    ) {
        $this->queuedAt = $queuedAt ?: date(DATE_ATOM);
    }

    public function jsonSerialize(): array
    {
        return [
            'type'       => self::TYPE,
            'messageId'  => $this->messageId,
            'queuedAt'   => $this->queuedAt,
            'attempts'   => $this->attempts,
            'maxRetries' => $this->maxRetries,
            'retryDelay' => $this->retryDelay,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $payload): self
    {
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        return new self(
            messageId:  $data['messageId'],
            queuedAt:   $data['queuedAt'] ?? date(DATE_ATOM),
            attempts:   $data['attempts'] ?? 0,
            maxRetries: $data['maxRetries'] ?? 3,
            retryDelay: $data['retryDelay'] ?? 60,
        );
    }
}
