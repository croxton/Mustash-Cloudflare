<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

require PATH_THIRD . "mustash_cloudflare/vendor/autoload.php";

use Cloudflare\Zone\Cache;

/**
 * Mustash_cloudflare extension
 *
 * @package		ExpressionEngine
 * @subpackage	Addons
 * @category	Extension
 * @author		Mark Croxton
 * @link		http://hallmark-design.co.uk
 */

class Mustash_cloudflare_ext {
	
	public $settings 		= array();
	public $description		= 'Cloudflare cache invalidation for Mustash';
	public $docs_url		= '';
	public $name			= 'Mustash Cloudflare';
	public $settings_exist	= 'n';
	public $version			= '1.0';

	/**
	 * Hostname
	 *
	 * @var 	string
	 * @access 	protected
	 */
	protected $site_url = '';

	/**
	 * Cloudlare email address
	 *
	 * @var 	string
	 * @access 	protected
	 */
	protected $email = '';

	/**
	 * Cloudflare API key
	 *
	 * @var 	string
	 * @access 	protected
	 */
	protected $api_key = '';

	/**
	 * Cloudflare domain zone identifier
	 *
	 * @var 	string
	 * @access 	protected
	 */
	protected $zone_id = '';

	/**
	 * The queue of URLs to purge
	 *
	 * @var 	object
	 * @access 	protected
	 */
	protected $queue;

	/**
	 * The maximum number of concurrent requests to send to Cloudflare
	 *
	 * @var 	integer
	 * @access 	private
	 */
	private $cloudflare_request_limit = 10;

	/**
	 * The maximum number of URLs that Cloudflare will clear per request
	 *
	 * @var 	integer
	 * @access 	private
	 */
	private $cloudflare_url_limit = 30;


	// ------------------------------------------------------
	
	/**
	 * Constructor
	 *
	 * @param 	mixed	Settings array or empty string if none exist.
	 */
	public function __construct($settings = array())
	{
		$this->settings 	= $settings;
		$this->site_url 	= rtrim(ee()->config->item('site_url'), '/'); // remove trailing slash, if any
		$this->email 		= ee()->config->item('mustash_cloudflare_email');
		$this->api_key 		= ee()->config->item('mustash_cloudflare_api_key');
		$this->zone_id 		= ee()->config->item('mustash_cloudflare_domain_zone_id');

		$this->queue 		= new stdClass;
		$this->queue->ids 	= array();
		$this->queue->urls 	= array();
	}
	
	// ------------------------------------------------------

    /**
     * Activate Extension
     * 
     * @return void
     */
    public function activate_extension()
    {
        $this->_add_hook('stash_delete', 10);
        $this->_add_hook('stash_flush_cache', 10);
        $this->_add_hook('stash_prune', 10);

        // create mustash_cloudflare table
        ee()->db->query("
        CREATE TABLE `".ee()->db->dbprefix."mustash_cloudflare` (
          `id` int(11) unsigned NOT NULL auto_increment,
          `url` varchar(512) NOT NULL,
          PRIMARY KEY  (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        ");
    }

    // ------------------------------------------------------
    // 
	/**
     * Disable Extension
     *
     * @return void
     */
    public function disable_extension()
    {
        ee()->db->where('class', __CLASS__);
        ee()->db->delete('extensions');

        ee()->load->dbforge();
        ee()->dbforge->drop_table('mustash_cloudflare');
    }

    // ------------------------------------------------------

	/**
	 * Update Extension
	 *
	 * @return 	mixed	void on update / false if none
	 */
	function update_extension($current = '')
	{
		if ($current == '' OR $current == $this->version)
		{
			return FALSE;
		}
	}	

    // --------------------------------------------------------------------

    /**
     * Add extension hook
     *
     * @access     private
     * @param      string $name
     * @param      integer $priority
     * @return     void
     */
    private function _add_hook($name, $priority = 10)
    {
        ee()->db->insert('extensions',
            array(
                'class'    => __CLASS__,
                'method'   => $name,
                'hook'     => $name,
                'settings' => '',
                'priority' => $priority,
                'version'  => $this->version,
                'enabled'  => 'y'
            )
        );
    }

	// ----------------------------------------------------------------------
	
	/**
	 * stash_delete hook
	 *
	 * Called when a single Stash variable is deleted
	 *
	 * @param array $var
     * @return array
	 */
	public function stash_delete($var) 
	{	
		// get the latest version of $var
        if (isset(ee()->extensions->last_call) && ee()->extensions->last_call)
        {
            $var = ee()->extensions->last_call;
        } 

		// we're only interested in static cached variables (bundle ID 3)
		if ( $var['bundle_id'] == 3)
		{
			// get the uri of the page represented by the cached variable
			$uri = ee()->mustash_model->parse_uri_from_key($var['key_name']);

			// ensure it has a leading slash
			$uri = '/' . ltrim($uri, '/');

			// construct the full url to the page we want to purge
			$uri = $this->site_url . $uri;

			// add the URL to the queue to be pruned
			$this->add_to_queue($uri);
		}

		return $var;
	}


	// ----------------------------------------------------------------------
	
	/**
	 * stash_flush_cache hook
	 *
	 * Called when the entire Stash cache for a given site is purged
	 *
	 * @param integer $site_id
	 * @return void
	 */
	public function stash_flush_cache($site_id) 
	{	
		// create a connection to the Cloudflare API
		$cache = new Cloudflare\Zone\Cache($this->email, $this->api_key);

		// purge the whole domain
		$cache->purge($this->zone_id, true);

		// reset the queue
		$this->reset_queue();
	}


	// ----------------------------------------------------------------------
	
	/**
	 * stash_prune hook
	 *
	 * Called when the Stash cache is periodically pruned
	 *
	 * @param array $data
	 * @return void
	 */
	public function stash_prune($data) 
	{
		// get the queue of urls to purge from Cloudflare
		$this->get_queue();

		if (count($this->queue->urls) > 0)
		{
			// chunk the array so we don't send too many urls per request
			$requests = array_chunk($this->queue->urls, $this->cloudflare_url_limit);

			// create a connection to the Cloudflare API
			$cache = new Cloudflare\Zone\Cache($this->email, $this->api_key);

			foreach ($requests as $urls)
			{
				// purge the urls
    			$cache->purge_files($this->zone_id, $urls);
			}

			// remove purged URLs from the queue
			$this->prune_queue();
		}
	}

	
	// ----------------------------------------------------------------------
	
	/**
	 * add_to_queue
	 *
	 * Add a single URL to the queue
	 *
	 * @param string $url
	 * @return mixed 
	 */
	protected function add_to_queue($url) 
	{
		if (ee()->db->insert('mustash_cloudflare', array('url' => $url)) )
		{
			return ee()->db->insert_id();
		}
		else
		{
			return FALSE;
		}
	}

	// ----------------------------------------------------------------------
	
	/**
	 * get_queue
	 *
	 * Get an array of urls to purge
	 *
	 * @return void 
	 */
	protected function get_queue() 
	{
		// maximum number of individual URLs we can safely purge in one go
		$limit = $this->cloudflare_url_limit * $this->cloudflare_request_limit;

		$query = ee()->db->get('mustash_cloudflare', $limit);

		if ($query->num_rows() > 0)
		{
			foreach($query->result() as $row) {
				$this->queue->ids[] = $row->id;
				$this->queue->urls[] = $row->url;
			}
		}
	}

	// ----------------------------------------------------------------------
	
	/**
	 * reset_queue
	 *
	 * Remove all urls from the queue
	 *
	 * @return void 
	 */
	protected function reset_queue() 
	{
		ee()->db->truncate('mustash_cloudflare');
	}

	// ----------------------------------------------------------------------
	
	/**
	 * prune_queue
	 *
	 * Remove purged URLs from the queue
	 *
	 * @return void 
	 */
	protected function prune_queue() 
	{
		return ee()->db->where_in('id', $this->queue->ids)->delete('mustash_cloudflare');
	}
}

/* End of file ext.mustash_cloudflare.php */
/* Location: /system/expressionengine/third_party/wygwam_config/ext.mustash_cloudflare.php */