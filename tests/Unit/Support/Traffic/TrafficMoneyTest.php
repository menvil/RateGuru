<?php

use App\Support\Traffic\Money\TrafficMoney;

it('reads an amount the same whether the engine returned a string or a float', function () {
    expect(TrafficMoney::stored('0.00002'))->toBe('0.00002000')
        ->and(TrafficMoney::stored(0.1 + 0.2))->toBe('0.30000000')
        ->and(TrafficMoney::stored(null))->toBe('0.00000000')
        ->and(TrafficMoney::stored(12))->toBe('12.00000000');
});

it('writes an amount for a postback without trailing zeros', function () {
    expect(TrafficMoney::forPostback('0.12300000'))->toBe('0.123')
        ->and(TrafficMoney::forPostback('2.00000000'))->toBe('2')
        ->and(TrafficMoney::forPostback('0.0000004'))->toBe('0');
});

it('writes an amount for people with a true minus', function () {
    expect(TrafficMoney::format('1234.5'))->toBe('$1,234.50')
        ->and(TrafficMoney::format('-0.4'))->toBe('−$0.40')
        ->and(TrafficMoney::format('0.00345', 4))->toBe('$0.0035');
});

it('writes a worth finer the smaller it is, from cents down to three decimals and four', function () {
    expect(TrafficMoney::fine('1234.5'))->toBe('$1,234.50')
        ->and(TrafficMoney::fine('1'))->toBe('$1.00')
        ->and(TrafficMoney::fine('0.172'))->toBe('$0.172')
        ->and(TrafficMoney::fine('0.1'))->toBe('$0.100')
        ->and(TrafficMoney::fine('0.0257'))->toBe('$0.0257')
        ->and(TrafficMoney::fine('0.0123'))->toBe('$0.0123')
        ->and(TrafficMoney::fine('0.01'))->toBe('$0.0100')
        ->and(TrafficMoney::fine('-0.4'))->toBe('−$0.400');
});

it('writes a worth under a cent to three significant digits, four decimals at least and eight at most', function () {
    expect(TrafficMoney::fine('0.000759'))->toBe('$0.000759')
        ->and(TrafficMoney::fine('0.00079123'))->toBe('$0.000791')
        ->and(TrafficMoney::fine('0.00000012'))->toBe('$0.00000012')
        ->and(TrafficMoney::fine('0.00000001'))->toBe('$0.00000001')
        ->and(TrafficMoney::fine('0.00999'))->toBe('$0.00999')
        ->and(TrafficMoney::fine('0.00412345'))->toBe('$0.00412')
        // Trailing zeros go, down to the four decimals the cents above keep.
        ->and(TrafficMoney::fine('0.005'))->toBe('$0.0050')
        ->and(TrafficMoney::fine('0.0001'))->toBe('$0.0001')
        ->and(TrafficMoney::fine('0.0000205'))->toBe('$0.0000205')
        // Rounding up carries across a digit, as far as a whole cent.
        ->and(TrafficMoney::fine('0.0009996'))->toBe('$0.0010')
        ->and(TrafficMoney::fine('0.00099949'))->toBe('$0.000999')
        ->and(TrafficMoney::fine('0.009996'))->toBe('$0.0100')
        // Past the eighth decimal money is not kept: it rounds there first.
        ->and(TrafficMoney::fine('0.000000005'))->toBe('$0.00000001')
        ->and(TrafficMoney::fine('0.000000004'))->toBe('$0.0000')
        ->and(TrafficMoney::fine(0.000759))->toBe('$0.000759')
        ->and(TrafficMoney::fine('-0.000759'))->toBe('−$0.000759')
        ->and(TrafficMoney::fine('0'))->toBe('$0.0000')
        ->and(TrafficMoney::fine(null))->toBe('$0.0000');
});

it('writes an amount exactly as kept for a hover, trailing zeros dropped down to cents', function () {
    expect(TrafficMoney::exact('0.00079123'))->toBe('$0.00079123')
        ->and(TrafficMoney::exact('0.000759'))->toBe('$0.000759')
        ->and(TrafficMoney::exact('0.00000012'))->toBe('$0.00000012')
        ->and(TrafficMoney::exact('1.5'))->toBe('$1.50')
        ->and(TrafficMoney::exact('1234.56780000'))->toBe('$1,234.5678')
        ->and(TrafficMoney::exact('0'))->toBe('$0.00')
        ->and(TrafficMoney::exact('-0.0004'))->toBe('−$0.0004')
        ->and(TrafficMoney::exact('0.000000005'))->toBe('$0.00000001');
});
