<?php

namespace Cloudflare\Zone;

use Cloudflare\Api;
use Cloudflare\Zone;

/**
 * CloudFlare API wrapper
 *
 * Custom Pages for a Zone
 *
 * @author James Bell <james@james-bell.co.uk>
 * @version 1
 */

class CustomPages extends Api
{
    /**
     * Default permissions level
     * @var array
     */
    protected $permission_level = array('read' => '#zone_settings:read', 'edit' => '#zone_settings:edit');

    /**
     * Available Custom Pages (permission needed: #zone_settings:read)
     * @param string $zone_identifier API item identifier tag
     */
    public function custom_pages($zone_identifier)
    {
        return $this->get('zones/' . $zone_identifier . '/custom_pages');
    }

    /**
     * Custom Page details (permission needed: #zone_settings:read)
     * Details about a specific Custom page details
     * @param string $zone_identifier API item identifier tag
     * @param string $identifier
     */
    public function details($zone_identifier, $identifier)
    {
        return $this->get('zones/' . $zone_identifier . '/custom_pages/' . $identifier);
    }

    /**
     * Update Custom page URL (permission needed: #zone_settings:edit)
     * Update Custom page URL
     * @param string $zone_identifier API item identifier tag
     * @param string $identifier
     * @param string $url             A URL that is associated with the Custom Page.
     * @param string $state           The Custom Page state
     */
    public function update($zone_identifier, $identifier, $url, $state)
    {
        $data = array(
            'url'   => $url,
            'state' => $state
        );
        return $this->patch('zones/' . $zone_identifier . '/custom_pages/' . $identifier, $data);
    }
}
