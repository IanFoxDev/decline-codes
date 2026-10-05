<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes\Tests;

use IanFoxDev\DeclineCodes\Attempt;
use IanFoxDev\DeclineCodes\Catalog;
use IanFoxDev\DeclineCodes\RetryPolicy;
use IanFoxDev\DeclineCodes\Schedule;
use IanFoxDev\DeclineCodes\Verdict;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    private Catalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = Catalog::default();
    }

    public function testInsufficientFundsFollowsTheSchedule(): void
    {
        $decision = RetryPolicy::default()->decide([$this->visa('2026-10-01 10:00', '51')]);

        self::assertSame(Verdict::RetryAt, $decision->verdict);
        self::assertSame('schedule.later', $decision->rule);
        self::assertSame('2026-10-02 10:00', $decision->notBefore?->format('Y-m-d H:i'));
        self::assertFalse($decision->allowsRetryAt(new \DateTimeImmutable('2026-10-02 09:59')));
        self::assertTrue($decision->allowsRetryAt(new \DateTimeImmutable('2026-10-02 10:00')));
    }

    public function testATechnicalFailureIsRetriedSooner(): void
    {
        $decision = RetryPolicy::default()->decide([$this->visa('2026-10-01 10:00', '91')]);

        self::assertSame('schedule.technical', $decision->rule);
        self::assertSame('2026-10-01 10:10', $decision->notBefore?->format('Y-m-d H:i'));
    }

    public function testVisaCategoryOneIsNeverRetriedEvenAfterOtherDeclines(): void
    {
        $decision = RetryPolicy::default()->decide([
            $this->visa('2026-10-01 10:00', '46'),
            $this->visa('2026-10-03 10:00', '51'),
        ]);

        self::assertSame(Verdict::Never, $decision->verdict);
        self::assertSame('visa.category_1', $decision->rule);
        self::assertStringContainsString('Closed account', $decision->explanation);
        self::assertSame('2026-04-18', $decision->source?->edition);
    }

    public function testFixDataAndAuthenticationWaitForTheCustomer(): void
    {
        $policy = RetryPolicy::default();

        self::assertSame(Verdict::NeedsCustomer, $policy->decide([$this->visa('2026-10-01 10:00', 'N7')])->verdict);
        self::assertSame(Verdict::NeedsCustomer, $policy->decide([$this->visa('2026-10-01 10:00', '1A', 'Europe')])->verdict);
    }

    public function testTheScheduleGivesUpAfterEightAttempts(): void
    {
        $attempts = [];
        for ($i = 0; $i < 8; $i++) {
            $attempts[] = $this->visa("2026-10-01 10:00 +$i days", '51');
        }

        $decision = RetryPolicy::default()->decide($attempts);

        self::assertSame(Verdict::GiveUp, $decision->verdict);
        self::assertSame('schedule.max_attempts', $decision->rule);
    }

    public function testVisaTwentyReattemptsInThirtyDaysIsACeiling(): void
    {
        // An eager schedule: retry right away, no limit of its own.
        $policy = new RetryPolicy($this->catalog, new Schedule(1000, [0], [0]));
        $attempts = [];
        for ($i = 0; $i < 21; $i++) {
            $attempts[] = $this->visa('2026-10-01 10:00 +' . $i . ' hours', '51');
        }

        $decision = $policy->decide($attempts);

        // 20 reattempts were made from 11:00 on 1 October; the first leaves the window 30 days later.
        self::assertSame('visa.reattempt_limit', $decision->rule);
        self::assertSame('2026-10-31 11:00', $decision->notBefore?->format('Y-m-d H:i'));
        self::assertTrue($decision->source?->primary);
    }

    public function testMastercardAdviceCodes(): void
    {
        $policy = RetryPolicy::default();

        $never = $policy->decide([$this->mastercard('2026-10-01 10:00', '51', '03')]);
        self::assertSame(Verdict::Never, $never->verdict);
        self::assertSame('mastercard.mac_03', $never->rule);
        self::assertFalse($never->source?->primary);

        $wait = $policy->decide([$this->mastercard('2026-10-01 10:00', '51', '28')]);
        self::assertSame('mastercard.mac_28', $wait->rule);
        self::assertSame('2026-10-07 10:00', $wait->notBefore?->format('Y-m-d H:i'));
        self::assertNotNull($wait->source);
        self::assertStringContainsString('Merchant Advice Code', $wait->source->document);

        // A MAC asking for 1 hour does not shorten the schedule's own day.
        self::assertSame('schedule.later', $policy->decide([$this->mastercard('2026-10-01 10:00', '51', '24')])->rule);
    }

    public function testMastercardTenConsecutiveDeclinesInADay(): void
    {
        $policy = new RetryPolicy($this->catalog, new Schedule(1000, [0], [0]));
        $attempts = [];
        for ($i = 0; $i < 10; $i++) {
            $attempts[] = $this->mastercard('2026-10-01 10:00 +' . (10 * $i) . ' minutes', '05');
        }

        $decision = $policy->decide($attempts);

        self::assertSame('mastercard.consecutive_declines', $decision->rule);
        self::assertSame('2026-10-02 10:00', $decision->notBefore?->format('Y-m-d H:i'));

        // An approval in between resets the count.
        $approvedBetween = $attempts;
        $approvedBetween[4] = Attempt::approved(new \DateTimeImmutable('2026-10-01 10:40', new \DateTimeZone('UTC')), 'shop-1', 1000);
        self::assertSame('schedule.later', $policy->decide(array_values($approvedBetween))->rule);

        // Declines at another acceptor do not count.
        $elsewhere = $attempts;
        for ($i = 0; $i < 5; $i++) {
            $decline = $attempts[$i]->decline;
            self::assertNotNull($decline);
            $elsewhere[$i] = Attempt::declined($attempts[$i]->at, $decline, 'shop-2', 1000);
        }
        self::assertSame('schedule.later', $policy->decide(array_values($elsewhere))->rule);
    }

    public function testOrderIsChecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RetryPolicy::default()->decide([$this->visa('2026-10-02', '51'), $this->visa('2026-10-01', '51')]);
    }

    public function testAStripeDeclineUsesTheNetworkCodeItCarries(): void
    {
        $decline = $this->catalog->stripe()->resolve('generic_decline', brand: 'visa', networkDeclineCode: '43');

        $decision = RetryPolicy::default()->decide([Attempt::declined(new \DateTimeImmutable('2026-10-01'), $decline)]);

        self::assertSame('visa.category_1', $decision->rule);
    }

    private function visa(string $at, string $code, ?string $region = null): Attempt
    {
        $when = new \DateTimeImmutable($at, new \DateTimeZone('UTC'));

        return Attempt::declined($when, $this->catalog->visa()->resolve($code, $region, $when), 'shop-1', 1000);
    }

    private function mastercard(string $at, string $code, ?string $mac = null): Attempt
    {
        return Attempt::declined(new \DateTimeImmutable($at, new \DateTimeZone('UTC')), $this->catalog->mastercard()->resolve($code, $mac), 'shop-1', 1000);
    }
}
