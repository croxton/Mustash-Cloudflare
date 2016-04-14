<?php

namespace Cloudflare\Zone;

use Cloudflare\Api;
use Cloudflare\Zone;

/**
 * CloudFlare API wrapper
 *
 * Railguns for a Zone
 *
 * @author James Bell <james@james-bell.co.uk>
 * @version 1
 */

class Railgun extends Api
{
    /**
     * Default permissions level
     * @var array
     */
    protected $permission_level = array('read' => '#zone_settings:read', 'edit' => '#zone_settings:edit');

    /**
     * Get available Railguns (permission needed: #zone_settings:read)
     * A list of available Railguns the zone can use
     * @param string $zone_identifier API item identifier tag
     */
    public function railguns($zone_identifier)
    {
        return $this->get('zones/' . $zone_identifier . '/railguns');
    }

    /**
     * Get Railgun details (permission needed: #zone_settings:read)
     * Details about a specific Railgun
     * @param string $zone_identifier API item identifier tag
     * @param string $identifier
     */
    public function details($zone_identifier, $identifier)
    {
        return $this->get('zones/' . $zone_identifier . '/railguns/' . $identifier);
    }

    /**
     * Test Railgun connection (permission needed: #zone_settings:read)
     * Test Railgun connection to the Zone
     * @param string $zone_identifier API item identifier tag
     * @param string $identifier
     */
    public function diagnose($zone_identifier, $identifier)
    {
        return $this->get('zones/' . $zone_identifier . '/railguns/' . $identifier . '/diagnose');
    }

    /**
     * Connect or disconnect a Railgun (permission needed: #zone_settings:edit)
     * Connect or disconnect a Railgun
     * @param string $zone_identifier
     * @param string $identifier      API item identifier tag
     * @param bool   $connected       A flag indicating whether the given zone is connected to the Railgun [valid values: (true,false)]
     */
    public function connected($zone_identifier, $identifier, bool $connected)
    {
        $data = array(
            'connected' => $connected
        );
        return $this->get('zones/' . $zone_identifier . '/railguns/' . $identifier, $data);
    }
}
