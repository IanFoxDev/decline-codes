<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes;

/**
 * A table of decline codes without reattempt categories: the ISO 8583 response codes, and
 * Mastercard's codes with its Merchant Advice Codes and limits. The class comes from the
 * reason unless the network's advice says otherwise; "never" from either side wins.
 */
final class CodeProfile
{
    public readonly string $network;

    public readonly Source $source;

    /** @var array<string, Source> */
    private array $sources = [];

    /** @var array<string, array<mixed>> */
    private array $codes = [];

    /** @var array<string, Advice> */
    private array $advice = [];

    /** @var list<Limit> */
    private array $limits = [];

    /**
     * @param array<mixed> $data
     * @internal use Catalog::iso8583() or Catalog::mastercard()
     */
    public function __construct(private readonly Catalog $catalog, array $data)
    {
        $this->network = Data::string($data, 'network');
        $this->source = Source::fromArray(Data::object($data, 'source'));
        foreach (isset($data['sources']) ? Data::object($data, 'sources') : [] as $name => $source) {
            if (\is_array($source)) {
                $this->sources[(string) $name] = Source::fromArray($source);
            }
        }
        foreach (Data::list($data, 'codes') as $code) {
            $this->codes[Data::string($code, 'code')] = $code;
        }
        if (isset($data['advice_codes'])) {
            foreach (Data::list(Data::object($data, 'advice_codes'), 'codes') as $advice) {
                $code = Data::string($advice, 'code');
                $wait = isset($advice['wait_hours']) ? Data::int($advice, 'wait_hours') : null;
                $this->advice[$code] = new Advice($code, Data::string($advice, 'meaning'), Data::string($advice, 'effect'), $wait);
            }
        }
        foreach (isset($data['limits']) ? Data::list($data, 'limits') : [] as $limit) {
            $window = isset($limit['window_hours']) ? Data::int($limit, 'window_hours') * 3600 : Data::int($limit, 'window_days') * 86400;
            $same = array_values(array_filter(\is_array($limit['same'] ?? null) ? $limit['same'] : [], 'is_string'));
            $sources = [];
            foreach (\is_array($limit['sources'] ?? null) ? $limit['sources'] : [] as $name) {
                if (\is_string($name) && isset($this->sources[$name])) {
                    $sources[] = $this->sources[$name];
                }
            }
            $from = Data::optionalString($limit, 'effective_from');
            $this->limits[] = new Limit(
                Data::string($limit, 'id'),
                Data::int($limit, 'max_declines'),
                $window,
                $same,
                $from === null ? null : new \DateTimeImmutable($from, new \DateTimeZone('UTC')),
                $sources,
                Data::string($limit, 'note'),
            );
        }
    }

    /**
     * @param ?string $advice the advice code sent with the decline, such as Mastercard's
     *                        Merchant Advice Code
     */
    public function resolve(string|int $code, string|int|null $advice = null): Decline
    {
        $code = Codes::normalize($code);
        $record = $this->codes[$code] ?? null;
        $adviceRecord = null;
        if ($advice !== null) {
            $adviceCode = Codes::normalize($advice);
            $adviceRecord = $this->advice[$adviceCode] ?? throw new \InvalidArgumentException(\sprintf('%s has no advice code "%s".', $this->network, $adviceCode));
        }

        if ($record === null) {
            $reason = $this->catalog->reason('generic_decline');
            $meaning = 'Not listed.';
            $source = $this->source;
        } else {
            $reason = $this->catalog->reason(Data::string($record, 'reason'));
            $meaning = Data::string($record, 'meaning');
            $from = Data::optionalString($record, 'meaning_source');
            $source = $from === null ? $this->source : $this->sources[$from];
        }

        return new Decline($this->network, $code, $meaning, $reason, self::classOf($reason->class, $adviceRecord), $source, null, $adviceRecord);
    }

    public function advice(string|int $code): ?Advice
    {
        return $this->advice[Codes::normalize($code)] ?? null;
    }

    /**
     * @return list<Limit>
     */
    public function limits(): array
    {
        return $this->limits;
    }

    private static function classOf(DeclineClass $fromReason, ?Advice $advice): DeclineClass
    {
        if ($fromReason === DeclineClass::Never || $advice === null) {
            return $fromReason;
        }

        return match ($advice->effect) {
            Advice::NEVER => DeclineClass::Never,
            Advice::UPDATE_CREDENTIALS => DeclineClass::CustomerAction,
            Advice::FIX_DATA => DeclineClass::FixData,
            Advice::LATER, Advice::WAIT => $fromReason === DeclineClass::Technical ? DeclineClass::Technical : DeclineClass::Later,
            default => $fromReason,
        };
    }
}
