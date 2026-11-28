# IP database fixtures

Three of MaxMind's own test databases, taken unchanged from
https://github.com/maxmind/MaxMind-DB/tree/main/test-data at commit
`c454a21f31fd17f2843f16094e9afb3a14a42078` (licensed under the Apache License
2.0 or the MIT License, at your option):

- `GeoLite2-City-Test.mmdb` — a 16 KB GeoLite2 City database built on
  2026-10-22. It places, among others, 81.2.69.142 in GB (ENG, London,
  Europe/London), 216.160.83.56 in US (WA, Milton) and 2001:218:: in JP (no
  city).
- `GeoLite2-ASN-Test.mmdb` — a 12 KB GeoLite2 ASN database built on
  2026-10-22, taken at commit `1b0113ab55f0428c00fe30b9709742ac52f4e9cc`. It
  names, among others, 1.128.0.0/11 as AS1221 (Telstra Pty Ltd),
  12.81.92.0/22 as AS7018 (AT&T Services) and 2600:6000::/20 as AS237 (Merit
  Network Inc.) — DB-IP ASN Lite has the same layout.
- `MaxMind-DB-test-broken-pointers-24.mmdb` — a database whose data section
  points at nothing, for the reader's failures.
