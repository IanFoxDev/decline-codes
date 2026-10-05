<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes\Tests;

use IanFoxDev\DeclineCodes\Catalog;
use IanFoxDev\DeclineCodes\Data;
use IanFoxDev\DeclineCodes\DeclineClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The lists below are copied from Visa Core Rules, 18 April 2026, Table 7-2, independently
 * of data/visa.json, so a mistake in the data shows up here.
 */
final class VisaProfileTest extends TestCase
{
    private const array CATEGORY_1 = ['04', '07', '12', '14', '15', '41', '43', '46', '57', 'R0', 'R1', 'R3'];
    private const array CATEGORY_2 = ['03', '19', '39', '51', '52', '53', '59', '61', '62', '65', '75', '78', '86', '91', '93', '96', '5C', '9G', 'N3', 'N4', 'Z5'];
    private const array CATEGORY_3 = ['54', '55', '82', '6P', 'N7'];

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function table(): iterable
    {
        foreach ([1 => self::CATEGORY_1, 2 => self::CATEGORY_2, 3 => self::CATEGORY_3] as $category => $codes) {
            foreach ($codes as $code) {
                yield "$code is category $category" => [$code, $category];
            }
        }
    }

    #[DataProvider('table')]
    public function testEveryCodeOfTheTableIsInItsCategory(string $code, int $category): void
    {
        $decline = Catalog::default()->visa()->resolve($code, at: new \DateTimeImmutable('2026-10-05'));

        self::assertSame($category, $decline->category);
        self::assertSame($category === 1, $decline->class === DeclineClass::Never, $code);
        self::assertSame($category === 1, !$decline->isRetryable());
    }

    public function testTheDataHasNoCategoryOneOrTwoOrThreeCodeTheTableDoesNotList(): void
    {
        $listed = array_merge(self::CATEGORY_1, self::CATEGORY_2, self::CATEGORY_3, ['83', '70', '1A']);
        foreach (Data::list(Data::file(__DIR__ . '/../data/visa.json'), 'codes') as $record) {
            if (Data::int($record, 'category') !== 4) {
                self::assertContains(Data::string($record, 'code'), $listed);
            }
        }
    }

    public function testLimits(): void
    {
        $visa = Catalog::default()->visa();

        self::assertNull($visa->reattemptLimit(1));
        foreach ([2, 3, 4] as $category) {
            self::assertSame(['max' => 20, 'windowDays' => 30], $visa->reattemptLimit($category));
        }
        self::assertSame('2026-04-18', $visa->source->edition);
        self::assertTrue($visa->source->primary);
    }

    public function testCommonDeclines(): void
    {
        $visa = Catalog::default()->visa();

        $funds = $visa->resolve('51');
        self::assertSame('insufficient_funds', $funds->reason->id);
        self::assertSame(DeclineClass::Later, $funds->class);
        self::assertSame('Not sufficient funds', $funds->meaning);

        $stolen = $visa->resolve(43);
        self::assertSame('stolen_card', $stolen->reason->id);
        self::assertSame(DeclineClass::Never, $stolen->class);

        $cvv = $visa->resolve('n7');
        self::assertSame('incorrect_cvc', $cvv->reason->id);
        self::assertSame(DeclineClass::FixData, $cvv->class);

        self::assertSame(DeclineClass::Technical, $visa->resolve('91')->class);
        self::assertSame('05', $visa->resolve(5)->code);
    }

    public function testDoNotHonorAndUnlistedCodesAreCategoryFour(): void
    {
        $visa = Catalog::default()->visa();

        $dnh = $visa->resolve('05');
        self::assertSame(4, $dnh->category);
        self::assertSame('do_not_honor', $dnh->reason->id);
        self::assertFalse($dnh->source->primary, 'the meaning of 05 comes from the ISO table, not Visa');

        $unknown = $visa->resolve('Q9');
        self::assertSame(4, $unknown->category);
        self::assertSame('generic_decline', $unknown->reason->id);
        self::assertTrue($unknown->isRetryable());
    }

    public function testSeventyAndOneAAreCategoryThreeOnlyInCemeaAndEurope(): void
    {
        $visa = Catalog::default()->visa();

        self::assertSame(3, $visa->resolve('1A', 'Europe')->category);
        self::assertSame(3, $visa->resolve('70', 'CEMEA')->category);
        self::assertSame(4, $visa->resolve('1A', 'US')->category);
        self::assertSame(4, $visa->resolve('1A')->category);
        self::assertSame(DeclineClass::CustomerAction, $visa->resolve('1A', 'Europe')->class);
        self::assertSame('authentication_required', $visa->resolve('1A')->reason->id);

        $this->expectException(\InvalidArgumentException::class);
        $visa->resolve('1A', 'EU');
    }

    public function testEightyThreeJoinsCategoryTwoOnTwentyFifthOfJuly2026(): void
    {
        $visa = Catalog::default()->visa();

        self::assertSame(4, $visa->resolve('83', at: new \DateTimeImmutable('2026-07-24 23:59:59', new \DateTimeZone('UTC')))->category);
        self::assertSame(2, $visa->resolve('83', at: new \DateTimeImmutable('2026-07-25 00:00:00', new \DateTimeZone('UTC')))->category);
    }

    public function testAccountsThatDoNotExistInCategoryTwoAreStillRetryable(): void
    {
        // 39 No credit account is category 2: Visa allows reattempts, unlike 46 Closed account.
        $visa = Catalog::default()->visa();

        self::assertSame('invalid_account', $visa->resolve('39')->reason->id);
        self::assertSame(DeclineClass::Later, $visa->resolve('39')->class);
        self::assertSame(DeclineClass::Never, $visa->resolve('46')->class);
    }

    public function testGarbageIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Catalog::default()->visa()->resolve('51; drop');
    }
}
