<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Sources;

/**
 * A source whose chunk is an OpenStreetMap PBF extract. The builder runs the extraction phase on the downloaded file
 * (`OsmExtractor`: addresses, with the centre of building ways, to a TSV), then ingests that TSV through the source's
 * {@see ReaderSource::reader()}, which opens `OsmExtractor::addressesPath($path)` with a `TsvRecordReader`. The records
 * have the columns of `AddressTags::HEADER`; the places, named streets and postcode-to-city files the extraction also
 * leaves next to it are the source's to load.
 */
interface ExtractSource extends ReaderSource
{
    /**
     * Language codes whose `name:<code>` forms the extraction collects for place nodes and streets (`ga`, `cy`, `gd`).
     *
     * @return list<string>
     */
    public function languages(): array;
}
