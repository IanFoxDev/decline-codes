<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes;

/**
 * Visa's authorization decline codes and their reattempt categories, from Table 7-2 of the
 * Visa Core Rules. A code the table does not list is category 4, as the rules say.
 */
final class VisaProfile
{
    /** Visa's regions, as the rules name them. */
    public const array REGIONS = ['AP', 'Canada', 'CEMEA', 'Europe', 'LAC', 'US'];

    public readonly Source $source;

    /** @var array<int, array{class: DeclineClass, max: ?int, windowDays: ?int}> */
    private array $categories = [];

    /** @var array<string, array<mixed>> */
    private array $codes = [];

    /** @var array<string, Source> */
    private array $meaningSources = [];

    /**
     * @param array<mixed> $data
     * @internal use Catalog::visa()
     */
    public function __construct(private readonly Catalog $catalog, array $data)
    {
        $this->source = Source::fromArray(Data::object($data, 'source'));
        foreach (Data::object($data, 'sources') as $name => $source) {
            if (\is_array($source)) {
                $this->meaningSources[(string) $name] = Source::fromArray($source);
            }
        }
        foreach (Data::list($data, 'categories') as $category) {
            $reattempts = Data::object($category, 'reattempts');
            $allowed = ($reattempts['allowed'] ?? null) === true;
            $this->categories[Data::int($category, 'category')] = [
                'class' => DeclineClass::from(Data::string($category, 'class')),
                'max' => $allowed ? Data::int($reattempts, 'max') : null,
                'windowDays' => $allowed ? Data::int($reattempts, 'window_days') : null,
            ];
        }
        foreach (Data::list($data, 'codes') as $code) {
            $this->codes[Data::string($code, 'code')] = $code;
        }
    }

    /**
     * Reads a decline response code (ISO 8583 field 39 as Visa uses it).
     *
     * @param ?string $region the acquiring region, one of REGIONS; some codes are in a
     *                        different category in CEMEA and Europe
     * @param ?\DateTimeImmutable $at when the decline happened; some codes change category
     *                                on a date
     */
    public function resolve(string|int $code, ?string $region = null, ?\DateTimeImmutable $at = null): Decline
    {
        if ($region !== null && !\in_array($region, self::REGIONS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown Visa region "%s"; use one of %s.', $region, implode(', ', self::REGIONS)));
        }
        $code = Codes::normalize($code);
        $record = $this->codes[$code] ?? null;
        if ($record === null) {
            return new Decline('visa', $code, 'Not listed in Table 7-2; all other decline codes are category 4.', $this->catalog->reason('generic_decline'), $this->categories[4]['class'], $this->source, 4);
        }

        $category = $this->categoryOf($record, $region, $at ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $reason = $this->catalog->reason(Data::string($record, 'reason'));
        $class = isset($record['class']) ? DeclineClass::from(Data::string($record, 'class')) : $this->classOf($category, $reason);
        $meaningSource = isset($record['meaning_source']) ? $this->meaningSources[Data::string($record, 'meaning_source')] : $this->source;

        return new Decline('visa', $code, Data::string($record, 'meaning'), $reason, $class, $meaningSource, $category);
    }

    /**
     * How many reattempts the category allows and in how many days; null for category 1.
     *
     * @return array{max: int, windowDays: int}|null
     */
    public function reattemptLimit(int $category): ?array
    {
        $limits = $this->categories[$category] ?? throw new \InvalidArgumentException(\sprintf('Visa has no category %d.', $category));

        return $limits['max'] === null || $limits['windowDays'] === null ? null : ['max' => $limits['max'], 'windowDays' => $limits['windowDays']];
    }

    /**
     * @param array<mixed> $record
     */
    private function categoryOf(array $record, ?string $region, \DateTimeImmutable $at): int
    {
        $from = Data::optionalString($record, 'effective_from');
        if ($from !== null && $at < new \DateTimeImmutable($from, new \DateTimeZone('UTC'))) {
            return 4;
        }
        if (isset($record['category_in_regions']) && $region !== null) {
            $regional = Data::object($record, 'category_in_regions');
            $regions = $regional['regions'] ?? [];
            if (\is_array($regions) && \in_array($region, $regions, true)) {
                return Data::int($regional, 'category');
            }
        }

        return Data::int($record, 'category');
    }

    /**
     * The category decides whether a retry is allowed at all; within the categories that
     * allow it, the reason says what kind of retry fits.
     */
    private function classOf(int $category, Reason $reason): DeclineClass
    {
        $class = $this->categories[$category]['class'];
        if ($class === DeclineClass::Never || $reason->class === DeclineClass::Never) {
            return $class;
        }

        return $reason->class;
    }
}
