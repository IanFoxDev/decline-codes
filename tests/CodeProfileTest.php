<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes\Tests;

use IanFoxDev\DeclineCodes\Advice;
use IanFoxDev\DeclineCodes\Catalog;
use IanFoxDev\DeclineCodes\DeclineClass;
use PHPUnit\Framework\TestCase;

final class CodeProfileTest extends TestCase
{
    public function testIsoCodesTakeTheClassOfTheirReason(): void
    {
        $iso = Catalog::default()->iso8583();

        self::assertSame(DeclineClass::Later, $iso->resolve('51')->class);
        self::assertSame(DeclineClass::Never, $iso->resolve('43')->class);
        self::assertSame(DeclineClass::Never, $iso->resolve('94')->class);
        self::assertSame('duplicate_transaction', $iso->resolve('94')->reason->id);
        self::assertSame(DeclineClass::Technical, $iso->resolve('96')->class);
        self::assertFalse($iso->source->primary);
        self::assertSame([], $iso->limits());
    }

    public function testPrivateRangeCodesAreNotGuessed(): void
    {
        // 78 and 82 mean different things per network; the ISO profile does not pick one.
        $iso = Catalog::default()->iso8583();

        self::assertSame('generic_decline', $iso->resolve('82')->reason->id);
        self::assertSame('Not listed.', $iso->resolve('78')->meaning);
        self::assertSame('incorrect_cvc', Catalog::default()->visa()->resolve('82')->reason->id);
        self::assertSame('policy_decline', Catalog::default()->mastercard()->resolve('82')->reason->id);
    }

    public function testMerchantAdviceCodeDecidesTheClass(): void
    {
        $mc = Catalog::default()->mastercard();

        $doNotRetry = $mc->resolve('51', '03');
        self::assertSame('insufficient_funds', $doNotRetry->reason->id);
        self::assertSame(DeclineClass::Never, $doNotRetry->class);
        self::assertSame('Do not try again', $doNotRetry->advice?->meaning);

        self::assertSame(DeclineClass::Never, $mc->resolve('05', '21')->class);
        self::assertSame(DeclineClass::CustomerAction, $mc->resolve('79', '01')->class);
        self::assertSame(DeclineClass::FixData, $mc->resolve('05', '04')->class);

        $wait = $mc->resolve('51', '27');
        self::assertSame(DeclineClass::Later, $wait->class);
        self::assertSame(Advice::WAIT, $wait->advice?->effect);
        self::assertSame(96, $wait->advice->waitHours);
    }

    public function testAdviceCannotMakeANeverRetryable(): void
    {
        // A stolen card stays never, whatever advice comes with it.
        self::assertSame(DeclineClass::Never, Catalog::default()->mastercard()->resolve('43', '24')->class);
    }

    public function testInformationalAdviceChangesNothing(): void
    {
        $mc = Catalog::default()->mastercard();

        self::assertSame(DeclineClass::Later, $mc->resolve('51', '40')->class);
        self::assertSame(DeclineClass::Later, $mc->resolve('51')->class);
    }

    public function testRetryAfterDelays(): void
    {
        $mc = Catalog::default()->mastercard();
        $hours = [];
        foreach (['24', '25', '26', '27', '28', '29', '30'] as $code) {
            $hours[] = $mc->advice($code)?->waitHours;
        }

        self::assertSame([1, 24, 48, 96, 144, 192, 240], $hours);
    }

    public function testMastercardLimitsAreMarkedSecondary(): void
    {
        $limits = Catalog::default()->mastercard()->limits();

        self::assertSame(['consecutive_declines', 'declines_same_amount'], array_map(static fn($l): string => $l->id, $limits));
        self::assertSame(10, $limits[0]->maxDeclines);
        self::assertSame(86400, $limits[0]->windowSeconds);
        self::assertSame(['card', 'acceptor'], $limits[0]->same);
        self::assertSame(35, $limits[1]->maxDeclines);
        self::assertSame(30 * 86400, $limits[1]->windowSeconds);
        self::assertSame(['card', 'acceptor', 'amount'], $limits[1]->same);
        foreach ($limits as $limit) {
            self::assertNotSame([], $limit->sources);
            foreach ($limit->sources as $source) {
                self::assertFalse($source->primary);
            }
        }
    }

    public function testUnknownAdviceCodeIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Catalog::default()->mastercard()->resolve('51', '99');
    }
}
