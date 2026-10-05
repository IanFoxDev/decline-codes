<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes;

/**
 * Where a rule or a code's meaning comes from. Primary: the network's own rules. Secondary:
 * a processor's or a third party's documentation.
 */
final readonly class Source
{
    public function __construct(
        public string $document,
        public bool $primary,
        public ?string $edition = null,
        public ?string $rule = null,
        public ?string $url = null,
    ) {}

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Data::string($data, 'document'),
            Data::string($data, 'verified') === 'primary',
            Data::optionalString($data, 'edition'),
            Data::optionalString($data, 'rule'),
            Data::optionalString($data, 'url'),
        );
    }
}
