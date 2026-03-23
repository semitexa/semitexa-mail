<?php

declare(strict_types=1);

namespace Semitexa\Mail\Value;

final readonly class MailRecipient
{
    public function __construct(
        public string $email,
        public ?string $name = null,
    ) {}

    public function formatted(): string
    {
        if ($this->name !== null && $this->name !== '') {
            $escaped = str_replace('"', '\\"', $this->name);
            return "\"{$escaped}\" <{$this->email}>";
        }
        return $this->email;
    }
}
