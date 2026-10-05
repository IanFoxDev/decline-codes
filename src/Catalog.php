<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes;

/**
 * The reasons and the network profiles, read from the JSON files in data/.
 */
final class Catalog
{
    private static ?self $default = null;

    /** @var array<string, Reason> */
    private array $reasons = [];

    private ?VisaProfile $visa = null;

    private ?CodeProfile $iso8583 = null;

    private ?CodeProfile $mastercard = null;

    private ?StripeProfile $stripe = null;

    public function __construct(private readonly string $directory = __DIR__ . '/../data')
    {
        $data = Data::file($this->directory . '/reasons.json');
        foreach (Data::list($data, 'reasons') as $reason) {
            $id = Data::string($reason, 'id');
            $this->reasons[$id] = new Reason($id, DeclineClass::from(Data::string($reason, 'class')), Data::string($reason, 'description'), ($reason['disclose'] ?? true) !== false);
        }
    }

    public static function default(): self
    {
        return self::$default ??= new self();
    }

    public function reason(string $id): Reason
    {
        return $this->reasons[$id] ?? throw new \InvalidArgumentException(\sprintf('Unknown reason "%s".', $id));
    }

    /**
     * @return array<string, Reason>
     */
    public function reasons(): array
    {
        return $this->reasons;
    }

    public function iso8583(): CodeProfile
    {
        return $this->iso8583 ??= new CodeProfile($this, Data::file($this->directory . '/iso8583.json'));
    }

    public function mastercard(): CodeProfile
    {
        return $this->mastercard ??= new CodeProfile($this, Data::file($this->directory . '/mastercard.json'));
    }

    public function stripe(): StripeProfile
    {
        return $this->stripe ??= new StripeProfile($this, Data::file($this->directory . '/stripe.json'));
    }

    public function visa(): VisaProfile
    {
        return $this->visa ??= new VisaProfile($this, Data::file($this->directory . '/visa.json'));
    }
}
