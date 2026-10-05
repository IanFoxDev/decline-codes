<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes;

/**
 * Stripe's decline codes. The reason comes from Stripe's decline_code, which is more
 * specific than the network's. Whether to retry comes from the card network when Stripe
 * passes its raw codes on (network_decline_code, network_advice_code), and otherwise from
 * Stripe's advice_code and the reason. "Never" from any of them wins.
 */
final class StripeProfile
{
    public readonly Source $source;

    /** The most retries Stripe recommends for a charge that permits them. */
    public readonly int $recommendedMaxRetries;

    /** @var array<string, array<mixed>> */
    private array $codes = [];

    /** @var array<string, Advice> */
    private array $advice = [];

    /** @var array<string, string> */
    private array $brands = [];

    /**
     * @param array<mixed> $data
     * @internal use Catalog::stripe()
     */
    public function __construct(private readonly Catalog $catalog, array $data)
    {
        $this->source = Source::fromArray(Data::object($data, 'source'));
        $this->recommendedMaxRetries = Data::int(Data::object($data, 'recommended_max_retries'), 'value');
        foreach (Data::list($data, 'codes') as $code) {
            $this->codes[Data::string($code, 'code')] = $code;
        }
        foreach (Data::list(Data::object($data, 'advice_codes'), 'codes') as $advice) {
            $code = Data::string($advice, 'code');
            $this->advice[$code] = new Advice($code, Data::string($advice, 'meaning'), Data::string($advice, 'effect'));
        }
        foreach (Data::object(Data::object($data, 'network_codes'), 'brands') as $brand => $profile) {
            if (\is_string($profile)) {
                $this->brands[(string) $brand] = $profile;
            }
        }
    }

    /**
     * @param string $declineCode the decline_code of the charge or payment error
     * @param ?string $adviceCode outcome.advice_code
     * @param ?string $brand the card brand, such as visa or mastercard
     * @param ?string $networkDeclineCode outcome.network_decline_code
     * @param ?string $networkAdviceCode outcome.network_advice_code
     * @param ?string $region the acquiring region, for Visa (see VisaProfile::REGIONS)
     */
    public function resolve(
        string $declineCode,
        ?string $adviceCode = null,
        ?string $brand = null,
        ?string $networkDeclineCode = null,
        ?string $networkAdviceCode = null,
        ?string $region = null,
        ?\DateTimeImmutable $at = null,
    ): Decline {
        $record = $this->codes[$declineCode] ?? null;
        $reason = $this->catalog->reason($record === null ? 'generic_decline' : Data::string($record, 'reason'));
        $advice = null;
        if ($adviceCode !== null) {
            $advice = $this->advice[$adviceCode] ?? throw new \InvalidArgumentException(\sprintf('Stripe has no advice code "%s".', $adviceCode));
        }

        $network = null;
        $profile = $brand === null ? null : ($this->brands[strtolower($brand)] ?? null);
        if ($profile !== null && $networkDeclineCode !== null && $networkDeclineCode !== '') {
            $network = match ($profile) {
                'visa' => $this->catalog->visa()->resolve($networkDeclineCode, $region, $at),
                'mastercard' => $this->catalog->mastercard()->resolve($networkDeclineCode, $networkAdviceCode === '' ? null : $networkAdviceCode),
                default => null,
            };
        }

        $classes = [$reason->class];
        if ($advice !== null) {
            $classes[] = match ($advice->effect) {
                Advice::NEVER => DeclineClass::Never,
                Advice::FIX_DATA => DeclineClass::FixData,
                default => DeclineClass::Later,
            };
        }
        if ($network !== null) {
            $classes[] = $network->class;
        }
        $class = match (true) {
            \in_array(DeclineClass::Never, $classes, true) => DeclineClass::Never,
            $network !== null => $network->class,
            $advice !== null && $advice->effect === Advice::FIX_DATA => DeclineClass::FixData,
            default => $reason->class,
        };
        $meaning = $record === null ? 'Not a Stripe card decline code.' : $reason->description;

        return new Decline('stripe', $declineCode, $meaning, $reason, $class, $this->source, $network?->category, $advice, $network);
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->codes);
    }
}
