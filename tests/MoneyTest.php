<?php

declare(strict_types=1);

namespace Beexar\Tests;

use Beexar\Money;
use Beexar\MoneyException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testKeepsTheScaleItWasGiven(): void
    {
        foreach (['0.90', '100', '1.2300'] as $in) {
            self::assertSame($in, (string) Money::parse($in));
        }
    }

    public function testRejectsTheExponentBombBeforeAnyArithmetic(): void
    {
        // The whole point: this parses in microseconds in every bignum library
        // and then asks for a multi-gigabyte integer on the first rescale.
        $started = microtime(true);
        $this->expectException(MoneyException::class);
        try {
            Money::parse('1E2000000000');
        } finally {
            self::assertLessThan(0.05, microtime(true) - $started);
        }
    }

    /** @return list<array{string}> */
    public static function badValues(): array
    {
        return [
            [''], ['1.12345678901234567'], ['-1.00'], ['+1.00'], [' 1.00'],
            ['1,00'], ['NaN'], ['Infinity'], ['.5'], ['1.'], [str_repeat('1', 41)],
        ];
    }

    #[DataProvider('badValues')]
    public function testRejectsWhatTheContractRejects(string $value): void
    {
        self::assertNull(Money::tryParse($value));
    }

    public function testRejectsAFloatSoOneCanNeverEnterThroughTheFrontDoor(): void
    {
        self::assertNull(Money::tryParse(1.1));
        self::assertNull(Money::tryParse(110));
    }

    public function testArithmeticAlignsScalesWithoutLosingDigits(): void
    {
        self::assertSame('0.3', (string) Money::parse('0.1')->add(Money::parse('0.2')));
        self::assertSame('99.995', (string) Money::parse('100.00')->sub(Money::parse('0.005')));
    }

    public function testSubtractionCanGoNegativeSoABetCanBeRefused(): void
    {
        $after = Money::parse('10.00')->sub(Money::parse('25.00'));
        self::assertTrue($after->isNegative());
        self::assertSame('-15.00', (string) $after);
        self::assertSame('0.00', (string) $after->clampToZero());
    }

    public function testComparesByValueNotByString(): void
    {
        self::assertSame(0, Money::parse('1.5')->compare(Money::parse('1.50')));
        self::assertTrue(Money::parse('1.5')->equals(Money::parse('1.50')));
        self::assertSame(-1, Money::parse('2')->compare(Money::parse('10')));
    }

    public function testSurvivesValuesPastFloatPrecision(): void
    {
        $huge = Money::parse('90071992547409910.99');
        self::assertSame('90071992547409911.00', (string) $huge->add(Money::parse('0.01')));
    }

    public function testFormatTruncatesTowardZero(): void
    {
        // Rounding up here would inflate what the operator owes.
        self::assertSame('1.99', Money::parse('1.999')->format(2));
        self::assertSame('1.00', Money::parse('1.001')->format(2));
        self::assertSame('1.0000', Money::parse('1')->format(4));
        self::assertSame('1', Money::parse('1.5')->format(0));
    }

    public function testFormatBonusClampsToTheTwelveDigitsTheContractAllows(): void
    {
        self::assertSame('1.123456789012', Money::parse('1.1234567890123456')->formatBonus());
        self::assertSame('0.00', Money::zero()->formatBonus());
    }

    public function testSerialisesToItsWireFormInsideJson(): void
    {
        self::assertSame('{"balance":"12.30"}', json_encode(['balance' => Money::parse('12.30')]));
    }

    public function testFromMinorUnitsBridgesAnIntegerLedger(): void
    {
        self::assertSame('99.70', (string) Money::fromMinorUnits('9970', 2));
        self::assertSame('-1.00', (string) Money::fromMinorUnits('-100', 2));
        $this->expectException(MoneyException::class);
        Money::fromMinorUnits('1', 17);
    }
}
