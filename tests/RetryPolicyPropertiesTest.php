<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes\Tests;

use IanFoxDev\DeclineCodes\Advice;
use IanFoxDev\DeclineCodes\Attempt;
use IanFoxDev\DeclineCodes\Catalog;
use IanFoxDev\DeclineCodes\DeclineClass;
use IanFoxDev\DeclineCodes\RetryPolicy;
use IanFoxDev\DeclineCodes\Schedule;
use IanFoxDev\DeclineCodes\Verdict;
use PHPUnit\Framework\TestCase;

/**
 * A retrier that tries again the moment the policy allows, with a schedule that has no
 * limits of its own, against random issuer answers over 90 days. Whatever happens, the
 * network rules must hold over the whole history of every card.
 */
final class RetryPolicyPropertiesTest extends TestCase
{
    private const int SEED = 20261005;
    private const int CARDS = 400;

    public function testNetworkRulesHoldForAnEagerRetrier(): void
    {
        mt_srand(self::SEED);
        $catalog = Catalog::default();
        $policy = new RetryPolicy($catalog, new Schedule(10_000, [0, 60, 3600], [0]));
        // Mostly declines that allow a retry; now and then one that ends the history.
        $visaRetry = ['51', '05', '91', '61', '59', '96', '19'];
        $visaStop = ['14', '43', '46', '54', 'N7'];
        $mcCodes = ['51', '05', '91', '61', '79', '83'];
        $macRetry = [null, null, null, null, '02', '24', '25', '26', '30'];
        $macStop = ['03', '21'];
        $rules = [];

        for ($card = 0; $card < self::CARDS; $card++) {
            $visa = $card % 2 === 0;
            // A merchant retries its own charge: one acceptor and one amount per card.
            $acceptor = 'shop-' . mt_rand(1, 3);
            $amount = 1000 * mt_rand(1, 5);
            $at = new \DateTimeImmutable('2026-09-01 00:00', new \DateTimeZone('UTC'));
            $end = $at->modify('+90 days');
            $attempts = [];
            while ($at < $end) {
                $stop = mt_rand(0, 39) === 0;
                $decline = mt_rand(0, 49) === 0 ? null : ($visa
                    ? $catalog->visa()->resolve(self::pick($stop ? $visaStop : $visaRetry), at: $at)
                    : $catalog->mastercard()->resolve(self::pick($mcCodes), self::pick($stop ? $macStop : $macRetry)));
                $attempts[] = new Attempt($at, $decline, $acceptor, $amount);
                if ($decline === null) {
                    break;
                }
                $decision = $policy->decide($attempts);
                $rules[$decision->rule] = ($rules[$decision->rule] ?? 0) + 1;
                if ($decision->verdict !== Verdict::RetryAt || $decision->notBefore === null) {
                    break;
                }
                self::assertGreaterThanOrEqual($at, $decision->notBefore);
                $at = $decision->notBefore;
            }
            $visa ? $this->assertVisaRules($attempts) : $this->assertMastercardRules($attempts);
        }

        // The ceilings were reached, so the assertions above tested them.
        foreach (['visa.reattempt_limit', 'visa.category_1', 'mastercard.consecutive_declines', 'mastercard.mac_03', 'mastercard.mac_30'] as $rule) {
            self::assertGreaterThan(0, $rules[$rule] ?? 0, "the simulation never hit $rule");
        }
    }

    /**
     * @template T
     * @param list<T> $items
     * @return T
     */
    private static function pick(array $items): mixed
    {
        return $items[mt_rand(0, \count($items) - 1)];
    }

    /**
     * @param list<Attempt> $attempts
     */
    private function assertVisaRules(array $attempts): void
    {
        $reattempts = [];
        foreach ($attempts as $i => $attempt) {
            if ($i === 0) {
                continue;
            }
            foreach (\array_slice($attempts, 0, $i) as $earlier) {
                self::assertNotSame(1, $earlier->decline?->category, 'an attempt after a Visa category 1 decline');
            }
            $previous = $attempts[$i - 1]->decline?->class;
            self::assertNotContains($previous, [DeclineClass::Never, DeclineClass::FixData, DeclineClass::CustomerAction], 'an attempt the customer had to act on first');

            $now = $attempt->at->getTimestamp();
            $reattempts[] = $now;
            $inWindow = array_filter($reattempts, static fn(int $t): bool => $t > $now - 30 * 86400);
            self::assertLessThanOrEqual(20, \count($inWindow), 'more than 20 Visa reattempts in 30 days');
        }
    }

    /**
     * @param list<Attempt> $attempts
     */
    private function assertMastercardRules(array $attempts): void
    {
        foreach ($attempts as $i => $attempt) {
            if ($i === 0) {
                continue;
            }
            $now = $attempt->at->getTimestamp();
            foreach (\array_slice($attempts, 0, $i) as $earlier) {
                self::assertNotSame(Advice::NEVER, $earlier->decline?->advice?->effect, 'an attempt after MAC 03 or 21');
            }
            $previous = $attempts[$i - 1];
            $wait = $previous->decline?->advice?->waitHours;
            if ($wait !== null) {
                self::assertGreaterThanOrEqual($previous->at->getTimestamp() + $wait * 3600, $now, 'an attempt before the MAC delay');
            }
            $declinedDay = array_filter(\array_slice($attempts, 0, $i), static fn(Attempt $a): bool => $a->decline !== null && $a->at->getTimestamp() > $now - 86400);
            self::assertLessThan(10, \count($declinedDay), 'an attempt after 10 declines in 24 hours');
            $declinedMonth = array_filter(\array_slice($attempts, 0, $i), static fn(Attempt $a): bool => $a->decline !== null && $a->at->getTimestamp() > $now - 30 * 86400);
            self::assertLessThan(35, \count($declinedMonth), 'an attempt after 35 declines of the same amount in 30 days');
        }
    }
}
