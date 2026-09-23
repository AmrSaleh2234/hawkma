<?php

namespace Modules\Clients\Services;

use Modules\Clients\Models\Client;
use Modules\Clients\Models\ClientLocation;

class LocationService
{
    /**
     * CLI-LOC-02: the first location is the default automatically; setting
     * `is_default` unsets the others.
     */
    public function create(Client $client, array $data): ClientLocation
    {
        $isFirst = ! $client->locations()->exists();

        $data['is_default'] = $isFirst || ($data['is_default'] ?? false);

        $location = $client->locations()->create($data);

        if ($location->is_default) {
            $this->unsetOtherDefaults($client, $location);
        }

        return $location;
    }

    /**
     * CLI-LOC-04: update; setting `is_default` unsets the others.
     */
    public function update(ClientLocation $location, array $data): ClientLocation
    {
        $location->fill($data);
        $location->save();

        if ($data['is_default'] ?? false) {
            $this->unsetOtherDefaults($location->client, $location);
        }

        return $location;
    }

    /**
     * CLI-LOC-05: soft delete; if it was the default, the newest remaining
     * one becomes the default.
     */
    public function delete(ClientLocation $location): void
    {
        $wasDefault = $location->is_default;
        $client = $location->client;

        $location->delete();

        if ($wasDefault) {
            $client->locations()
                ->latest('id')
                ->first()
                ?->update(['is_default' => true]);
        }
    }

    /**
     * CLI-LOC-06: set as the default.
     */
    public function setDefault(ClientLocation $location): ClientLocation
    {
        $location->is_default = true;
        $location->save();

        $this->unsetOtherDefaults($location->client, $location);

        return $location;
    }

    protected function unsetOtherDefaults(Client $client, ClientLocation $except): void
    {
        $client->locations()
            ->whereKeyNot($except->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
