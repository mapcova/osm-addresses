# Provenance

## Code

- `src/Pbf/` (the OpenStreetMap PBF reader) and `tests/Support/PbfWriter.php` come from the MapCova `routing-builder` repository (AGPL-3.0, same copyright holder, no outside contribution). Changed on copy: `PbfReader::checkHeader()` refuses a file whose header does not declare `Sort.Type_then_ID`; format errors are a `PbfFormatException` carrying the key of the message to show (`err_osm_truncated`, `err_osm_compression` for lz4, zstd and lzma blobs, `err_osm_unsorted`); `Bin` keeps only the four functions used here; `PbfWriter` writes blocks of a chosen size for each kind of object, plain and dense nodes, and options for raw or unsupported compression and for an unsorted header.
- `src/Text/TextNormalizer.php` and `src/Build/Schema.php` are shared with the query side of MapCova, so that what is indexed and what is searched are normalised alike. `src/Support/{SafeHttpClient,ByteRange,HttpClientException,SourceChangedException}.php` come from the MapCova core, reduced to what a build from a local file needs.
- The extraction (`src/Osm/`), the sources (`src/Sources/`) and the builder (`src/Build/`) are written for this method.

## Data

- OpenStreetMap, © OpenStreetMap contributors, ODbL 1.0, <https://www.openstreetmap.org/copyright>. Extracts by Geofabrik (`europe/united-kingdom`, `europe/ireland-and-northern-ireland`).
- `src/resources/northern-ireland.json`: the outer ways of relation 156393 (Northern Ireland), fetched from the Overpass API with `rel(156393);out geom;` and simplified to 10 m by `tools/make-ni-border.php` (one ring, 4 615 points of 18 371). ODbL 1.0.
- Figures in the source docblocks were measured on the Geofabrik extract of Wales of 2026-11-24 (151 742 205 bytes) and on the BBBike extract of Cork.
