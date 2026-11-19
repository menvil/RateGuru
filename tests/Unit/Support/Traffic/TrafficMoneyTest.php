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
