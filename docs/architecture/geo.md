# IP location database

The site places a visitor — country, region, city — with a local MaxMind DB
file it downloads and refreshes itself, the way the WP Statistics plugin does
with the files https://geo.wp-statistics.com publishes. Nothing is sent to an
outside lookup service, and a visitor's page never waits on one.

## Editions

| Edition | Maker and licence | Published | Sources, in order |
| --- | --- | --- | --- |
| `geolite2_city` (default) | MaxMind GeoLite2 City, [GeoLite2 EULA](https://www.maxmind.com/en/geolite2/eula) (data under CC BY-SA 4.0) | Tuesdays and Fridays, republished on npm by the WP Statistics team (`geolite2-city`) | npm registry, jsDelivr |
| `dbip_city_lite` | DB-IP City Lite, CC BY 4.0 | the 1st of every month | download.db-ip.com, then the npm package `dbip-city-lite` |
| `dbip_asn_lite` | DB-IP ASN Lite, CC BY 4.0 | the 1st of every month | download.db-ip.com only (no mirror keeps it current) |

The administrator picks the edition on Traffic sources → Sources (Tracking).
The other one stands in, by itself, while the chosen one has no database file
(`current.mmdb`): not installed yet, its downloads still failing, or — for
GeoLite2 — taken out of service because its build is older than 30 days, which
MaxMind's licence does not allow to keep using. A chosen file that is there but
cannot be read is not replaced by the other one: lookups answer unknown until
the edition's next check finds that it no longer matches the size and SHA-256
recorded when it was installed, and installs it again. Once the chosen one
serves again, the other one's files are deleted.

`dbip_asn_lite` places no one: it names the network an address belongs to —
its autonomous system number and the organisation holding it — in
GeoLite2-ASN's layout (`DBIP-ASN-Lite (compat=GeoLite2-ASN)`). It serves
beside whichever city database places the visitor, stands in for nothing and
can't be chosen to place visitors (`GeoEdition::cities()`;
`TrafficSetting::cityEdition()` reads any stored value naming it as the
default). It is held to probes of its own — four well-known addresses and the
ASNs they belong to (Google, Cloudflare, Quad9) — instead of countries.

## Network lists

Two lists sort the networks the ASN database names, kept under
`storage/geoip/networks/` by `NetworkListUpdater` in the same run, each on
its own schedule (`config('geo.network_lists')`):

| List | What | Source | Checked |
| --- | --- | --- | --- |
| `hosting` | hosting, cloud and VPN networks by ASN: no person's own connection | X4BNet's curated `input/datacenter/ASN.txt` and `input/vpn/ASN.txt` (MIT), joined | daily |
| `relay` | the addresses iCloud Private Relay sends people's traffic from | Apple's `egress-ip-ranges.csv` | weekly |

A fetched list is read and must hold at least `min_entries` (500 networks,
100 merged ranges) before it is written beside the one in service and
renamed over it; a fetch that fails, a list too short or unreadable, or one
past its size cap leaves the one in service as it was, waits as a database
does (15 minutes, an hour, …) and is logged as an error from the third
failure in a row. `hosting.list` is one ASN a line (`HostingAsnList`);
`relay.list` holds the ranges merged and sorted, 32 bytes each — the first
and last address, IPv4 mapped into IPv6 — so a lookup finds an address with
a binary search of some twenty 32-byte reads (`AddressRanges`), never loading
the 12 MB list Apple publishes.

`NetworkLookup::lookup($ip)` (`MmdbNetworkLookup`, bound scoped) answers a
`NetworkInfo`: the ASN and organisation, and its kind (`NetworkKind`) —
`relay` when Private Relay's ranges hold the address, whatever network
carries it (Akamai, Cloudflare and Fastly are on the hosting list); else
`hosting` when the hosting list names its ASN; else `other`; `unknown` with no
networks' database, for an address that is not public, or for a file that
breaks — never an exception. The tracker keeps the ASN on the visit
(`traffic_visits.asn`) and flags a hosting one (`BotSignals::DATACENTER`); it
never refuses a visit for it, and keeps no address.

Attribution, shown wherever the data is shown in the admin: “This product
includes GeoLite Data created by MaxMind, available from
https://www.maxmind.com.” for GeoLite2, and a link “IP Geolocation by DB-IP” to
https://db-ip.com for DB-IP. `App\Services\Geo\GeoAttribution` gives every
screen the edition serving, its last update and the credit of that edition,
so the wording has one home. The networks' database asks for DB-IP's credit
whichever city database serves: Tracking names it beside the networks line
("IP to ASN data by DB-IP", `GeoAttribution::networks()`).

## Files

`config('geo.directory')` is `storage/geoip`. On a host `storage` is the
shared storage every release links to, so the files survive deploys; it is not
on the public disk, and the nightly backup archives only `storage/app`, so the
databases are never backed up — after a restore the first update fetches them
again.

```
storage/geoip/
  geolite2_city/  dbip_city_lite/  dbip_asn_lite/   made once, by the first update
    current.mmdb     the database that serves
    previous.mmdb    the one the last update replaced, kept 7 days for a rollback
    state.json       what is installed, when it was checked, the last error
    update.lock      keeps two runs apart
    *.part           a download or unpacking in progress
  networks/          made once, by the first update
    hosting.list     relay.list     the lists in service
    hosting.json     relay.json     their state
    *.lock  *.part   a run's lock, a download in progress
```

The host's repair compares the owner and mode of everything up to two levels
under the shared storage before and after it runs; the files live a level
below that, so after the first run an update changes nothing the repair looks
at. The facts about a file live in `state.json` beside it, not in the
database: a table restored from a backup would describe a file that is not
there.

## Updating

`geo:update` (`app/Console/Commands/GeoUpdateCommand.php`) is scheduled every
minute and ends at once when there is nothing to do: each edition is checked
once a day, sooner after a failure (15 minutes, an hour, 3 hours, then every 6
hours) or when the administrator presses Update now, which only records the
request — the download always runs in the CLI, never in a web request or a
queued job. It runs in the background, its overlap lock lasts an hour, and a
file lock keeps two runs apart as well.

A run, for the chosen edition (`GeoDatabaseUpdater`):

1. Takes the file lock — a run that finds it held ends at once, as `busy` —
   and only then reads the state, so no run acts on a state another run has
   changed since.
2. If the installed GeoLite2 build is past 30 days, deletes it and its
   previous copy; refuses without PHP's zlib (`zlib_missing`) or without room
   for the download and the unpacked file (`disk_space`).
3. Checks the serving file against the size and SHA-256 the state recorded;
   a file that does not match counts as not installed.
4. Asks each source in turn for its latest build. A source offering the
   installed version ends the run as `current`.
5. Downloads by the exact version only (`…@1.0.116/…`, never `latest`), with
   no redirects, explicit timeouts and a size cap.
6. Holds the download to the published checksum: the SHA-512 the npm registry
   lists for the tarball, the SHA-256 jsDelivr lists for the file. DB-IP
   publishes none; its file is held to the size its server announces.
7. Unpacks it in 16 KiB steps — for npm, the gzipped database out of the
   package's tar — and refuses a stream that does not reach its proper end.
   A step that small never unpacks to more than about 16.5 MiB in memory, so
   a crafted file is refused at its size cap instead of exhausting memory.
8. Opens it with the reader: the expected database type, IPv6-capable, binary
   format 2, a build no older than the installed one and not dated in the
   future, and at least three of four well-known addresses placed in their
   countries.
9. Links the serving file as `previous.mmdb` and renames the new one over
   `current.mmdb` — a reader that has the old file open keeps reading it, and
   there is never a moment without a database.
10. Writes the state.

Any failure deletes the half-written files, leaves the serving database alone,
and is recorded with its reason (`GeoUpdateFailure`); from the third in a row
it is logged as an error. `geo:update --force` checks now; `geo:update
--rollback` puts `previous.mmdb` back and keeps the build it replaces from
being installed again. The rollback and the deletion of the other edition's
files take the same file lock as an update: a rollback while an update of the
edition runs is refused, to be tried again once it finishes, and files an
update of their edition is still working on are left for the next run to
delete.

## Looking up

`GeoLookup::lookup($ip)` (`MmdbGeoLookup`, bound scoped: one reader per request
or job, opened by the first lookup that needs one) answers a `GeoLocation` —
country code and name, region, city, time zone. An address that is not public,
a missing database or a broken file answer unknown, never an exception.
Country codes are those of `App\Support\Geo\Countries` — ISO 3166-1 and XK —
with their English names. The site keeps no IP address: the tracker stores the
country, region and city it was given.

The application's IP is `Request::ip()`, the connecting address: nginx serves
the site directly, with no proxy in front. A CDN in front would need trusted
proxies configured first, or every visitor would be placed where the CDN is.

## Tests

Tests never see a developer's database (`tests/TestCase.php` points
`geo.directory` at a path that does not exist) and never go to the network.
`tests/Fixtures/geo/` holds two of MaxMind's own test databases from
https://github.com/maxmind/MaxMind-DB/tree/main/test-data (Apache-2.0 or MIT):
`GeoLite2-City-Test.mmdb`, `GeoLite2-ASN-Test.mmdb` (for the networks'
database), and a deliberately broken one. `installNetworkLists()` in
`tests/Pest.php` writes the network lists as the update writes them.
