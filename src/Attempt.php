<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes;

/**
 * One authorization attempt with the same card: when, the decline (null if approved), the
 * card acceptor (merchant) and the amount in minor units. Mastercard counts declines per
 * acceptor and per amount.
 */
final readonly class Attempt
{
    public function __construct(
        public \DateTimeImmutable $at,
        public ?Decline $decline,
        public string $acceptor = '',
        public int $amount = 0,
    ) {}

    public static function declined(\DateTimeImmutable $at, Decline $decline, string $acceptor = '', int $amount = 0): self
    {
        return new self($at, $decline, $acceptor, $amount);
    }

    public static function approved(\DateTimeImmutable $at, string $acceptor = '', int $amount = 0): self
    {
        return new self($at, null, $acceptor, $amount);
    }

    /**
     * The decline as the card network read it: the network's own decline, or the one a
     * processor passed on.
     */
    public function networkDecline(): ?Decline
    {
        if ($this->decline === null) {
            return null;
        }
        if ($this->decline->network !== null) {
            return $this->decline->network;
        }

        return \in_array($this->decline->profile, ['visa', 'mastercard'], true) ? $this->decline : null;
    }
}
