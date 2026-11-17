# IP database fixtures

Two of MaxMind's own test databases, taken unchanged from
https://github.com/maxmind/MaxMind-DB/tree/main/test-data at commit
`c454a21f31fd17f2843f16094e9afb3a14a42078` (licensed under the Apache License
2.0 or the MIT License, at your option):

- `GeoLite2-City-Test.mmdb` — a 16 KB GeoLite2 City database built on
  2026-10-22. It places, among others, 81.2.69.142 in GB (ENG, London,
  Europe/London), 216.160.83.56 in US (WA, Milton) and 2001:218:: in JP (no
  city).
- `MaxMind-DB-test-broken-pointers-24.mmdb` — a database whose data section
  points at nothing, for the reader's failures.
