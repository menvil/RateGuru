<?php

namespace App\Support\Geo;

use App\Enums\GeoEdition;

/**
 * Where an edition's files live: storage/geoip/<edition>/ holds the serving
 * database (current.mmdb), the one it replaced (previous.mmdb), the update
 * state (state.json), the update lock and any half-written download. The
 * edition directories are made once, by the first update; afterwards only the
 * files inside them change. storage/geoip/networks/ holds the network lists
 * the same way: each list (<name>.list), its state (<name>.json), its lock and
 * any half-written download.
 */
final readonly class GeoDatabaseLocator
{
    public function __construct(private string $directory) {}

    public function root(): string
    {
        return rtrim($this->directory, '/');
    }

    public function editionDirectory(GeoEdition $edition): string
    {
        return $this->root().'/'.$edition->value;
    }

    public function current(GeoEdition $edition): string
    {
        return $this->editionDirectory($edition).'/current.mmdb';
    }

    public function previous(GeoEdition $edition): string
    {
        return $this->editionDirectory($edition).'/previous.mmdb';
    }

    public function state(GeoEdition $edition): string
    {
        return $this->editionDirectory($edition).'/state.json';
    }

    public function lock(GeoEdition $edition): string
    {
        return $this->editionDirectory($edition).'/update.lock';
    }

    public function part(GeoEdition $edition, string $name): string
    {
        return $this->editionDirectory($edition).'/'.$name.'.part';
    }

    public function networksDirectory(): string
    {
        return $this->root().'/networks';
    }

    public function networkList(string $name): string
    {
        return $this->networksDirectory().'/'.$name.'.list';
    }

    public function networkState(string $name): string
    {
        return $this->networksDirectory().'/'.$name.'.json';
    }

    public function networkLock(string $name): string
    {
        return $this->networksDirectory().'/'.$name.'.lock';
    }

    public function networkPart(string $name, string $part): string
    {
        return $this->networksDirectory().'/'.$name.'.'.$part.'.part';
    }

    /**
     * The database to serve: the preferred edition's, else the other one's,
     * else none. An update deletes the other edition's files once the chosen
     * one serves again, so both exist only for the minutes in between.
     */
    public function serving(GeoEdition $preferred): ?GeoEdition
    {
        foreach ([$preferred, $preferred->other()] as $edition) {
            if (is_file($this->current($edition))) {
                return $edition;
            }
        }

        return null;
    }
}
