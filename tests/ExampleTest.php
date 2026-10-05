<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes\Tests;

use PHPUnit\Framework\TestCase;

final class ExampleTest extends TestCase
{
    public function testTheRenewalsExamplePrintsWhatTheReadmeShows(): void
    {
        $output = shell_exec(escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../examples/renewals/run.php'));

        self::assertStringEqualsFile(__DIR__ . '/../examples/renewals/expected-output.txt', (string) $output);
    }
}
