<?php

use App\Support\Geo\NetworkRecordMapper;
use App\Support\Geo\Networks\AddressRanges;
use App\Support\Geo\Networks\HostingAsnList;

/**
 * The network lists, pure: an ASN list read from X4BNet's "AS1234 # comment"
 * lines, joined and stored; Private Relay's CIDR ranges turned into merged
 * 16-byte ranges and found again in a file of them with a binary search.
 */
it('reads the ASNs of a list, skipping comments and lines that name none, and joins lists', function () {
    $text = "AS45090 # Tencent cloud\n# a comment\nAS3223 # VOXILITY, RO\n  as13335 # lower case\nAS0\nASx\nAS3223 # twice\n";

    expect(HostingAsnList::parse($text))->toBe([3223, 13335, 45090])
        ->and(HostingAsnList::union([[3223, 45090], [9009, 3223]]))->toBe([3223, 9009, 45090])
        ->and(HostingAsnList::union([]))->toBe([])
        ->and(HostingAsnList::decode(HostingAsnList::encode([3223, 9009])))->toBe([3223 => true, 9009 => true])
        ->and(HostingAsnList::decode("12\nnot a number\n\n34"))->toBe([12 => true, 34 => true]);
});

it('turns a CIDR range into its first and last address, IPv4 mapped into IPv6, and refuses what is not one', function () {
    [$first, $last] = AddressRanges::range('172.224.226.0/27');

    expect(inet_ntop($first))->toBe('::ffff:172.224.226.0')
        ->and(inet_ntop($last))->toBe('::ffff:172.224.226.31')
        ->and(array_map('inet_ntop', AddressRanges::range('2a02:26f7:b3c0:4000::/64')))->toBe(['2a02:26f7:b3c0:4000::', '2a02:26f7:b3c0:4000:ffff:ffff:ffff:ffff'])
        ->and(array_map('inet_ntop', AddressRanges::range('10.0.0.7/32')))->toBe(['::ffff:10.0.0.7', '::ffff:10.0.0.7'])
        ->and(array_map('inet_ntop', AddressRanges::range('10.0.0.255/24')))->toBe(['::ffff:10.0.0.0', '::ffff:10.0.0.255'])
        ->and(AddressRanges::range('10.0.0.0/33'))->toBeNull()
        ->and(AddressRanges::range('::/129'))->toBeNull()
        ->and(AddressRanges::range('10.0.0.0'))->toBeNull()
        ->and(AddressRanges::range('nonsense/24'))->toBeNull()
        ->and(AddressRanges::range('10.0.0.0/x'))->toBeNull();
});

it('merges ranges that touch or overlap, in order, and keeps apart those with a gap', function () {
    $ranges = AddressRanges::parse(implode("\n", [
        '172.224.226.32/31,GB,GB-SC,Aberdeen,',
        '172.224.226.0/27,GB,GB-EN,London,',
        '172.224.226.34/31,GB,GB-EN,Oxford,',
        '172.224.226.40/29,GB,GB-NI,Belfast,',
        '172.224.226.42/31,GB,GB-NI,Belfast,',
        'not a range,XX',
        '',
        '2a02:26f7:b3c0:4000::/64,GB,GB-EN,London,',
    ]));

    expect(array_map(fn (array $range): array => array_map('inet_ntop', $range), $ranges))->toBe([
        ['::ffff:172.224.226.0', '::ffff:172.224.226.35'],
        ['::ffff:172.224.226.40', '::ffff:172.224.226.47'],
        ['2a02:26f7:b3c0:4000::', '2a02:26f7:b3c0:4000:ffff:ffff:ffff:ffff'],
    ]);
});

it('reads ranges from a file a line at a time, in or out of address order, as from the same text', function () {
    $lines = ['172.224.226.36/31,GB', '172.224.226.0/27,GB', '10.0.0.0/8', '172.224.226.32/31,GB', '172.224.226.34/31,GB', '2a02:26f7::/32', '10.0.0.0/16', 'junk'];
    $path = tempnam(sys_get_temp_dir(), 'relay');
    file_put_contents($path, implode("\r\n", $lines));

    try {
        $ranges = AddressRanges::parseFile($path);

        expect($ranges)->toBe(AddressRanges::parse(implode("\n", $lines)))
            ->and(array_map(fn (array $range): array => array_map('inet_ntop', $range), $ranges))->toBe([
                ['::ffff:10.0.0.0', '::ffff:10.255.255.255'],
                ['::ffff:172.224.226.0', '::ffff:172.224.226.37'],
                ['2a02:26f7::', '2a02:26f7:ffff:ffff:ffff:ffff:ffff:ffff'],
            ])
            ->and(AddressRanges::parseFile($path.'.missing'))->toBe([]);
    } finally {
        @unlink($path);
    }
});

it('finds an address in a file of ranges, and nothing in a file that is missing or cut short', function () {
    $path = tempnam(sys_get_temp_dir(), 'ranges');
    file_put_contents($path, AddressRanges::encode(AddressRanges::parse("172.224.226.0/27\n172.224.230.0/24\n2a02:26f7:b3c0:4000::/64\n104.28.0.0/16")));

    try {
        expect(AddressRanges::contains($path, '172.224.226.0'))->toBeTrue()
            ->and(AddressRanges::contains($path, '172.224.226.31'))->toBeTrue()
            ->and(AddressRanges::contains($path, '172.224.226.32'))->toBeFalse()
            ->and(AddressRanges::contains($path, '172.224.230.128'))->toBeTrue()
            ->and(AddressRanges::contains($path, '104.28.255.255'))->toBeTrue()
            ->and(AddressRanges::contains($path, '104.29.0.0'))->toBeFalse()
            ->and(AddressRanges::contains($path, '2a02:26f7:b3c0:4000::1'))->toBeTrue()
            ->and(AddressRanges::contains($path, '2a02:26f7:b3c0:4001::1'))->toBeFalse()
            // The same address written as IPv4-mapped IPv6 is the same address.
            ->and(AddressRanges::contains($path, '::ffff:172.224.226.5'))->toBeTrue()
            ->and(AddressRanges::contains($path, 'not an address'))->toBeFalse()
            ->and(AddressRanges::contains($path.'.missing', '172.224.226.0'))->toBeFalse();

        file_put_contents($path, substr((string) file_get_contents($path), 0, 40));
        expect(AddressRanges::contains($path, '172.224.226.0'))->toBeFalse();
    } finally {
        @unlink($path);
    }
});

it('reads a networks’ database record into its ASN and organisation, and anything else into none', function () {
    $record = ['autonomous_system_number' => 1221, 'autonomous_system_organization' => '  Telstra Pty Ltd '];

    expect(NetworkRecordMapper::asn($record))->toBe(1221)
        ->and(NetworkRecordMapper::organization($record))->toBe('Telstra Pty Ltd')
        ->and(NetworkRecordMapper::asn(null))->toBeNull()
        ->and(NetworkRecordMapper::asn(['autonomous_system_number' => '1221']))->toBeNull()
        ->and(NetworkRecordMapper::asn(['autonomous_system_number' => 0]))->toBeNull()
        ->and(NetworkRecordMapper::organization(['autonomous_system_organization' => ' ']))->toBeNull();
});
