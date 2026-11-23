<?php

declare(strict_types=1);

namespace Janoga\Scheduling\Tests\Unit;

use Janoga\Scheduling\CronExpression;
use Janoga\Scheduling\Exception\InvalidCronExpression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CronExpressionTest extends TestCase
{
    public function test_it_accepts_five_fields_and_normalises_whitespace(): void
    {
        self::assertSame('*/5 0-6 1,15 * MON-FRI', (new CronExpression("  */5  0-6 1,15\t* MON-FRI "))->value);
    }

    #[DataProvider('malformed')]
    public function test_it_rejects_anything_that_is_not_five_crontab_fields(string $expression): void
    {
        $this->expectException(InvalidCronExpression::class);

        new CronExpression($expression);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'four fields' => ['* * * *'];
        yield 'six fields (seconds)' => ['* * * * * *'];
        yield 'macro' => ['@daily'];
        yield 'shell metacharacters' => ['* * * * $(id)'];
    }
}
