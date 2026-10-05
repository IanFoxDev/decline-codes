<?php

declare(strict_types=1);

// Monthly renewals that were declined, and what to do about each one.
//
//   php examples/renewals/run.php

use IanFoxDev\DeclineCodes\Attempt;
use IanFoxDev\DeclineCodes\Catalog;
use IanFoxDev\DeclineCodes\Decline;
use IanFoxDev\DeclineCodes\RetryPolicy;
use IanFoxDev\DeclineCodes\Schedule;
use IanFoxDev\DeclineCodes\Verdict;

require __DIR__ . '/../../vendor/autoload.php';

$catalog = Catalog::default();
$policy = RetryPolicy::default();
$utc = new DateTimeZone('UTC');
$at = static fn(string $when): DateTimeImmutable => new DateTimeImmutable($when, $utc);

$show = static function (string $title, array $attempts, ?RetryPolicy $with = null) use ($policy, $catalog): void {
    /** @var list<Attempt> $attempts */
    $last = $attempts[count($attempts) - 1]->decline;
    assert($last instanceof Decline);
    $decision = ($with ?? $policy)->decide($attempts);
    echo $title, "\n";
    printf("  decline     %s %s: %s (%s)\n", $last->profile, $last->code, $last->reason->id, $last->class->value);
    if ($last->network !== null) {
        printf("  network     %s %s: %s\n", $last->network->profile, $last->network->code, $last->network->meaning);
    }
    printf("  decision    %s%s\n", $decision->verdict->value, $decision->verdict === Verdict::RetryAt ? ' at ' . $decision->notBefore?->format('Y-m-d H:i') : '');
    printf("  rule        %s%s\n", $decision->rule, $decision->source === null ? '' : ' (' . ($decision->source->primary ? 'network rules' : 'secondary source') . ($decision->source->edition === null ? '' : ', ' . $decision->source->edition) . ')');
    printf("  customer    %s\n\n", $last->reason->forCustomer($catalog)->id);
};

// 1. Visa, not sufficient funds on the 1st. The schedule waits a day, then two.
$funds = static fn(string $when): Attempt => Attempt::declined($at($when), $catalog->visa()->resolve('51'), 'acme', 1900);
$show('1. Visa 51 on 1 October', [$funds('2026-10-01 06:00')]);
$show('   ... and again on 2 October', [$funds('2026-10-01 06:00'), $funds('2026-10-02 06:00')]);

// 2. Mastercard, not sufficient funds with Merchant Advice Code 27: try again in 4 days.
$show('2. Mastercard 51 with MAC 27', [Attempt::declined($at('2026-10-01 06:00'), $catalog->mastercard()->resolve('51', '27'), 'acme', 1900)]);

// 3. Through Stripe: "generic_decline", but the network code Visa sent is 43, stolen card.
$stolen = $catalog->stripe()->resolve('generic_decline', brand: 'visa', networkDeclineCode: '43');
$show('3. Stripe generic_decline carrying Visa 43', [Attempt::declined($at('2026-10-01 06:00'), $stolen, 'acme', 1900)]);

// 4. Stripe incorrect_cvc: nothing to retry until the customer fixes the card.
$show('4. Stripe incorrect_cvc', [Attempt::declined($at('2026-10-01 06:00'), $catalog->stripe()->resolve('incorrect_cvc'), 'acme', 1900)]);

// 5. A schedule with no limit of its own retried every hour: 21 Visa attempts in a day.
// The network allows 20 reattempts in 30 days, so the next one waits until 31 October.
$eager = [];
for ($i = 0; $i < 21; $i++) {
    $eager[] = Attempt::declined($at('2026-10-01 00:00')->modify("+$i hours"), $catalog->visa()->resolve('05'), 'acme', 1900);
}
$show('5. 21 attempts on 1 October, all Visa 05, hourly schedule', $eager, new RetryPolicy($catalog, new Schedule(1000, [3600], [3600])));
