# osm-addresses

The method used to build the United Kingdom (GB) and Ireland (IE) address databases of MapCova from OpenStreetMap extracts.

It is published to meet section 4.6 of the Open Database License (ODbL) 1.0, which asks whoever publishes a Derivative Database to offer "the method of making the alterations to the Database (such as an algorithm)". Given the extract, this code makes the same database, byte for byte in its tables.

Copyright (C) 2026 Simon de Chabaneix. Licensed under the [GNU Affero General Public License v3.0](LICENSE) (AGPL-3.0-only).

## The data

The addresses come from OpenStreetMap: **© OpenStreetMap contributors**, available under the Open Database License 1.0, <https://www.openstreetmap.org/copyright> (licence text: <https://opendatacommons.org/licenses/odbl/1-0/>).

- GB is built from the Geofabrik extract of the United Kingdom, `europe/united-kingdom-latest.osm.pbf`.
- IE is built from the Geofabrik extract of Ireland and Northern Ireland, `europe/ireland-and-northern-ireland-latest.osm.pbf`.
- `src/resources/northern-ireland.json` is derived from OpenStreetMap data: the outer ways of relation 156393 (Northern Ireland), fetched from the Overpass API and simplified to 10 m by `tools/make-ni-border.php`. It is only used to decide on which side of the border an address lies.

This repository holds no other OpenStreetMap data: the tests use small synthetic files written by the test code. The databases are Derivative Databases and keep the attribution above in their `meta` table; the OpenStreetMap data stay in their own files and are never mixed with another country's data.

## What the build does

The build reads one extract and writes one SQLite file, in these passes:

1. **Verify and scan.** The PBF file (nodes, then ways, then relations) is read blob by blob, in memory that does not grow with the extract. Nodes and ways that carry `addr:housenumber` or `addr:housename` with a street (`addr:street`) or a place (`addr:place`) are addresses. Place nodes (`city`, `town`, `village`, `hamlet`) are kept for the city rules below. Streets that have a name in another language are kept for the name forms. Relations are not read.
2. **Resolve and centres.** The position of a way is the mean of up to four of its nodes (its first, and those a quarter, a half and three quarters round its ring): the node blobs are read a second time to find them. A way none of whose nodes is found is dropped.
3. **Postcode fill.** An address with no postcode is given the postcode key (GB: the outward code, `SW1A`; IE: the routing key, `D02`) that most of the addresses of its street and city have, when at least one of them has one (a tie goes to the smallest key). The street is then one row instead of two. The part after the key (the inward code, the Eircode's identifier) is never invented, an address that has a postcode is never touched, and text that is not a postcode is left alone. The counts of (street, city, key) go to a staging SQLite table in slices of rows, the majority of each group is picked in one statement over the groups, `addr` is copied with the keys added in slices, and the copy replaces it. An address that gave no city of its own keeps the city it would have had.
4. **Ingest.** The extracted addresses are read as a TSV and each is turned into a row by the country's rules (below) or rejected. Rows are cut into buckets by street.
5. **Finalize.** Each bucket is sorted by street, number and suffix, and the addresses are numbered so that the addresses of a street are contiguous. Addresses of the same street with the same number and suffix are one (a shop and its building): the first one read stays.
6. **Streets.** The addresses are aggregated into streets (a street is a name, a postcode and a city). Streets are ranked by the number of their addresses and of their city's, and an R-Tree of their extent is built.
7. **Search text.** The text of each street (name, postcode, city, district, other-language forms) is normalised (lower case, no diacritics, punctuation to space) and indexed with SQLite FTS5.
8. **Verify and publish.** An integrity check, a count, the `meta` table, `ANALYZE`, and an atomic rename.

The units of a build are small and idempotent, so a build can be stopped after any one and resumed from its saved state; the result is the same as a continuous build (the tests check it after every unit).

### Rules

- **Numbers.** `10`, `10a` (number 10, suffix `a`), `2-6` and `12/14` (number 2, suffix `-6`; number 12, suffix `/14`). A value that lists several numbers (`2;4;6`, `2,4,6`) gives one address for each, up to 10. A number with more than five digits, `0`, or text (`Flat 2`) is no number.
- **Named houses.** A house with `addr:housename` and no number is a street of its own, `"<house name>, <street>"`, with one address and no number.
- **Street.** `addr:street`, else `addr:place` (a rural address).
- **Postcodes, GB.** The street holds the outward code (`SW1A`), the address the inward code (`1AA`). The text is upper-cased, spaces dropped, and the outward code must be on one of the 121 postcode areas of Great Britain and Northern Ireland (`BT` included). `GIR 0AA`, the Crown dependencies' postcodes and anything else are no postcode.
- **Postcodes, IE.** The street holds the Eircode routing key (`D02`, `T12`), the address the unique identifier (`X285`). `Dublin 2` or `Dublin 6W` written as a postcode are the routing keys `D02` and `D6W`, with no identifier. Eircodes are not open data and are rarely in OpenStreetMap.
- **City chain.** `addr:city`, else `addr:town`; else (GB only, where postcodes follow post towns) the most frequent city of the address's outward code; else the nearest place node of the extract within the radius of its type (city 15 km, town 8 km, village 3 km, hamlet 1.5 km). An address with none is rejected. The district is `addr:suburb`, else `addr:village` or `addr:hamlet`, when it is not the city.
- **Border.** The two extracts overlap along the border. GB keeps what lies outside the Republic, IE keeps what lies outside Northern Ireland, by the polygon of relation 156393.
- **Name forms.** The names in other languages (`name:cy`, `name:gd` for GB; `name:ga`, `name:en` for IE) of the highways found near an address by name, and of its city's place node, go to the street's `names` column (display) and to the search text. The name without its apostrophes (`St Johns Road` for `St John's Road`) goes to the search text too.

## Run it

Requirements: PHP 8.3 or later with `pdo_sqlite` (with FTS5 and R-Tree) and `zlib`; [Composer](https://getcomposer.org/). No network is used by the build.

```
composer install
php bin/build-osm-addresses.php --country GB --pbf wales-latest.osm.pbf --out gb.sqlite
php bin/build-osm-addresses.php --country IE --pbf ireland-and-northern-ireland-latest.osm.pbf --out ie.sqlite
```

Download the extract by hand from Geofabrik first. `--country GB` also accepts a regional extract (Wales, for instance) as long as it is a sorted PBF file. `--work <folder>` sets where the work files go (default: next to the output); they are deleted at the end. Count on a work folder of about 1.3 times the size of the extract, on top of the extract itself.

Wales (152 MB) takes about 15 seconds and 70 MB of memory and gives 189 606 rows in 29 225 streets. `tools/osm-measure.php` builds the same database in slices and prints the time, memory and disk of each phase.

## Tests and checks

```
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/php-cs-fixer fix --dry-run --diff
```

The tests build GB and IE end to end from synthetic extracts (`tests/Support/PbfWriter.php` writes the PBF files), including after every unit of a sliced build, and test the PBF reader, the extraction, the sorted runs, the place index, the border and the rules of each source.

## The database

SQLite, `PRAGMA application_id = 1296254785`, `user_version = 3`. Coordinates are integers in millionths of a degree (`*_e6`).

| Table | Content |
|---|---|
| `meta` | `key`, `value`: `schema`, `builder`, `text_rules`, `country`, `source`, `source_url`, `source_version`, `source_updated_at`, `licence`, `licence_url`, `attribution`, `built_at`, `build_id`, `rows`, `streets`, `rejected`, `trigram`, `bbox` |
| `street` | `id`, `name`, `postcode`, `city`, `city_code`, `district`, `first_address`, `address_count`, `lat_e6`, `lon_e6`, `names` (JSON, other-language forms), `part` (always null here) |
| `address` | `id`, `number`, `suffix`, `lat_e6`, `lon_e6`, `pc` (the inward code or Eircode identifier). The addresses of a street are the rows `first_address` to `first_address + address_count - 1` |
| `street_rtree` | R-Tree (`id`, `min_lon`, `max_lon`, `min_lat`, `max_lat`) of the extent of each street |
| `street_fts` | FTS5 index (contentless) of the normalised search text of each street, `rowid` = `street.id` |

Streets are numbered in rank order (the largest city and the longest street first).

## Known limits

- OpenStreetMap has about one address in five in Great Britain, and no national address file is used: the databases are as complete as the map. In Wales, 15 % of the numbered addresses have no postcode once the postcode fill has run (26 % before it), 11 % take their city from the nearest place and 2 % from the post town; the extraction drops 11 % of the objects that have a number because they have no street and no place.
- In Ireland, most streets have no Eircode (Cork: 90 % of the numbered addresses have no postcode) and 40 % of the cities come from the nearest place.
- A filled address shows the postcode district of its street (`SW1A`) with no second half, as the addresses whose own postcode is only a district do, although OpenStreetMap gives that address no postcode. A street whose addresses have postcodes in several districts stays one row per district; an address with no postcode on it takes the district most of the others have.
- The position of a way is the mean of up to four of its nodes, not its centroid.
- Addresses mapped on relations (multipolygon buildings) are not read (0.06 % of the addresses of the extracts measured).
- The border is a polygon simplified to 10 m: an address within that distance of the border may fall on the wrong side.
- Only GB and IE are built here.

See [`docs/PROVENANCE.md`](docs/PROVENANCE.md) for where the code comes from.
