<?php

declare(strict_types=1);

namespace MapCova\OsmAddresses\Sources;

/**
 * One address of a source, in the shape the database stores.
 */
final class AddressRow
{
    /**
     * @param ?string $number house number as written (`10`, `31.1`), `null` when the source has none
     * @param ?string $suffix `bis`, `ter`, `a`…
     * @param string  $extra  other names the street answers to (alias, lieu-dit, its name in the other languages), may be empty
     * @param ?string $pc     the second half of the postcode (the inward code `1AA` of `SW1A 1AA`, the Eircode unique identifier `X285`), the first half being the street's
     * @param ?string $names  the street and the city in each language (JSON `{"fr":["Rue Neuve","Bruxelles"],"nl":[…]}`), `null` for a single form
     * @param ?string $sourceId the id the service gives the address, to drop it when two pages of the service both have it, `null` for a file
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $postcode,
        public readonly string $city,
        public readonly string $cityCode,
        public readonly ?string $district,
        public readonly ?string $number,
        public readonly ?string $suffix,
        public readonly int $latE6,
        public readonly int $lonE6,
        public readonly string $extra = '',
        public readonly ?string $names = null,
        public readonly ?string $pc = null,
        public readonly ?string $sourceId = null,
    ) {
    }

    /** Numeric part of the number, to sort `2` before `10`. */
    public function sortNumber(): int
    {
        return $this->number === null ? -1 : (int) $this->number;
    }

    /** Identity of the street this address belongs to. */
    public function streetKey(): string
    {
        return $this->cityCode . '|' . ($this->district ?? '') . '|' . ($this->postcode ?? '') . '|' . $this->name;
    }
}
