<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes\Tests;

use IanFoxDev\DeclineCodes\Data;
use IanFoxDev\DeclineCodes\DeclineClass;
use PHPUnit\Framework\TestCase;

/**
 * The JSON in data/ is read by other languages too, so its shape is checked here rather
 * than trusted to the PHP classes.
 */
final class DataTest extends TestCase
{
    private const array NETWORKS = ['visa.json'];

    public function testClassesMatchTheEnum(): void
    {
        $ids = array_map(static fn(array $c): string => Data::string($c, 'id'), Data::list($this->json('reasons.json'), 'classes'));

        self::assertSame(array_map(static fn(DeclineClass $c): string => $c->value, DeclineClass::cases()), $ids);
    }

    public function testReasonsAreUniqueAndHaveAClass(): void
    {
        $ids = [];
        foreach (Data::list($this->json('reasons.json'), 'reasons') as $reason) {
            $id = Data::string($reason, 'id');
            self::assertMatchesRegularExpression('/^[a-z][a-z_]+$/', $id);
            self::assertNotContains($id, $ids, "duplicate reason $id");
            self::assertNotNull(DeclineClass::tryFrom(Data::string($reason, 'class')), $id);
            Data::string($reason, 'description');
            $ids[] = $id;
        }
    }

    public function testEveryNetworkCodeHasAKnownReasonAndASource(): void
    {
        $reasons = array_map(static fn(array $r): string => Data::string($r, 'id'), Data::list($this->json('reasons.json'), 'reasons'));
        foreach (self::NETWORKS as $file) {
            $network = $this->json($file);
            $source = Data::object($network, 'source');
            self::assertContains(Data::string($source, 'verified'), ['primary', 'secondary']);
            Data::string($source, 'document');
            $sources = isset($network['sources']) ? Data::object($network, 'sources') : [];
            $codes = [];
            foreach (Data::list($network, 'codes') as $record) {
                $code = Data::string($record, 'code');
                self::assertNotContains($code, $codes, "duplicate code $code in $file");
                self::assertContains(Data::string($record, 'reason'), $reasons, "$file $code");
                Data::string($record, 'meaning');
                $meaningSource = Data::optionalString($record, 'meaning_source');
                if ($meaningSource !== null) {
                    self::assertArrayHasKey($meaningSource, $sources, "$file $code");
                }
                $class = Data::optionalString($record, 'class');
                if ($class !== null) {
                    self::assertNotNull(DeclineClass::tryFrom($class), "$file $code");
                }
                $codes[] = $code;
            }
        }
    }

    public function testTheDataIsPlainAscii(): void
    {
        foreach (glob(__DIR__ . '/../data/*.json') ?: [] as $file) {
            self::assertDoesNotMatchRegularExpression('/[^\x00-\x7F]/', (string) file_get_contents($file), basename($file));
        }
    }

    /**
     * @return array<mixed>
     */
    private function json(string $file): array
    {
        return Data::file(__DIR__ . '/../data/' . $file);
    }
}
