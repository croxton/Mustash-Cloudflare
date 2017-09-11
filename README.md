## Mustash Cloudflare v1.0.0

Cloudflare static cache invalidation for Stash/Mustash-powered ExpressionEngine websites.

### Features

* Static-cached html pages will be cached and served via Cloudflare's CDN. Users will receive your content in the fastest possible time, wherever they are in the world, reducing direct requests to your server to a minimum and allowing your website to serve very large numbers of visitors concurrently.
* Mustash rules that trigger cache-breaking of static pages will clear the corresponding Cloudflare cache. 
* Time-limited static cached pages will be automatically pruned from the Cloudflare cache on expiry.
* Clearing the Mustash cache will purge the entire Cloudflare cache for your domain.

### How it works

1. We tell Cloudflare to cache everything, except the EE control panel admin and ACTions

2. We ensure that requests that pass through to EE templates are served with no-cache headers, which Cloudflare and browsers will respect. Forms and other types of dynamic content will continue to work as expected, as will the control panel.

3. We add `{exp:stash:static}` tags to the specific templates we DO want to cache. These generate static HTML pages which are served with special `max-age` and `s-maxage` headers. This ensures that Cloudflare is able to cache them in it's edge CDN.

4. When an entry is edited and triggers a Mustash cache-breaking rule, the Mustash Cloudflare extension adds any page URLs matched by the rule to a queue. When a time-limited static cached page expires, or a static cached variable is deleted in Mustash, it's URL is also added to the queue.

5. We use the Stash cache pruning CRON to periodically send requests via the Cloudflare API to clear the URLs in the queue, while respecting Cloudflare's rate limits.


### Requirements

* [ExpressionEngine](https://ellislab.com/expressionengine) 2.9+ or 3.1+
* [Stash](https://github.com/croxton/Stash) 2.6.9 (EE2) or 3.0.2 (EE3)
* [Mustash](https://devot-ee.com/add-ons/mustash)
* [Cloudflare](https://www.cloudflare.com) account (free or paid)

### Recommended

* [Resource Router](https://github.com/rsanchez/resource_router)
* If using Apache, the [mod_headers](http://httpd.apache.org/docs/current/mod/mod_headers.html) module

### Installation

1. If you haven't already, create a [Cloudflare](https://www.cloudflare.com) account and add your domain.

2. Make sure you have already installed [Stash](https://github.com/croxton/Stash) and [Mustash](https://devot-ee.com/add-ons/mustash)

3. Follow the instructions to [setup static caching](https://github.com/croxton/Stash/wiki/Static-cache-setup) but use the `.htaccess` file below.

4. Ensure that your website is accessible at a single canonical domain by redirecting non-www to www (or vice versa), and redirecting http to https if you have enabled SSL. Uncomment the relevant rules in the `.htaccess` file.

5. Setup the Stash [cache-pruning configuration](https://github.com/croxton/Stash/wiki/Cache-pruning-configuration), using a 5 minute (or less) CRON, e.g.:
    
    ```
    */5 * * * * wget -qO- 'http://your-domain.com/?ACT=123&key=456&prune=1' >/dev/null 2>&1
    ```

6. Open your `./system/expressionengine/config/config.php` file (EE2) or `./system/user/config/config.php` (EE3), add the following lines at the bottom of the file:

    ```PHP
    // The email account you use to log in to Cloudflare
    $env_config['mustash_cloudflare_email']             = 'you@hyour-domain.com';

    // Your Cloudflare API key
    $env_config['mustash_cloudflare_api_key']           = 'API_KEY';

    // The zone identifier for your domain
    $env_config['mustash_cloudflare_domain_zone_id']    = '12345678901234567890';
    ```

7. Download and unzip this repo, and then:

    *ExpressionEngine 2* - move the 'mustash_cloudflare' folder to the `./system/expressionengine/third_party` directory. Go to the Add-Ons > Extensions and click the 'Install' link.

    *ExpressionEngine 3* - move the 'mustash_cloudflare' folder to the `./system/user/addons` directory. Go to Add-On Manager and click the button to install `Mustash Cloudflare`.

8. In your Cloudflare account, add these Page Rules for your domain, in this order:

    1.  *Pattern:*        
        your-domain.com/admin.php\*

        *Rules:*     
        Apps: Off, Performance: Off, Security: Off, Always online: Off, Cache level: Bypass cache
    
    2.  *Pattern:*        
        your-domain.com/\*ACT=\*

        *Rules:*     
        Apps: Off, Performance: Off, Security: Off, Always online: Off, Cache level: Bypass cache

    3.  *Pattern:*     
        \*your-domain.com/\*
    
        *Rules:*     
        Browser cache expire TTL: 30 minutes, Custom caching: Cache everything

9.  We need to ensure that all requests that pass through to EE templates return no-cache headers. Both Cloudflare and browsers will respect these headers and will not cache the pages. Only templates set up to generate static-cached pages will be cached by Cloudflare on a subsequent visit to the url, when the page is served as static html without `Set-Cookie` headers. Any other pages that you choose not to static cache, such as those containing forms, will not be cached by Cloudflare.

    If you have Resource Router installed, create this rule to add a no-cache header to all requests:

    ```PHP
    '.*' => function($router) {   

        // set cache header for all requests that pass through to EE
        $router->setHeader('Cache-Control', 'max-age=0, no-cache, no-store, must-revalidate');

        return; // signify this rule does not match a route
    },
    ```

    If you don't want to use Resource Router, there are a number of other add-ons that allow you to set these headers in templates, e.g. [HTTP Header](https://github.com/rsanchez/http_header])


9. Add [{exp:stash:static}](https://github.com/croxton/Stash/wiki/%7Bexp:stash:static%7D) to templates you want to cache, and set up Mustash [cache-breaking rules](https://github.com/croxton/Stash/wiki/Cache-breaking-rules) as normal.


### .htaccess file

This file requires the Apache `mod_headers` module (installed by default). It differs from the standard Stash static caching rules in that it allows you to specify the cache duration of resources cached by Cloudflare and by the browser.

* Replace '[site_id]' with the id number of your site.
* You may want to tweak the `s-maxage` and `maxage` values.

`s-maxage`     
The number of seconds that the edge-side cache (Cloudflare) will cache your page. This should be higher than max-age.

`max-age`    
The number of seconds that browsers will cache the page. Note that Cloudflare will enforce a minimum max-age equal to the 'Browser cache expire TTL' seconds for any page that it caches, so you can't go lower than 1800.


    <IfModule mod_rewrite.c>
     
    RewriteEngine on   

    #################################################################################
    # REDIRECT TO CANONICAL DOMAIN - uncomment the relevant rules

    # Redirect to non-www
    #RewriteCond %{HTTP_HOST} ^www.your-domain.com [NC]
    #RewriteRule ^(.*)$ https://your-domain.com/$1 [L,R=301]

    # Redirect to www
    #RewriteCond %{HTTP_HOST} ^your-domain.com [NC]
    #RewriteRule ^(.*)$ https://www.your-domain.com/$1 [L,R=301]

    # Redirect to https
    #RewriteCond %{HTTP:CF-Visitor} '"scheme":"http"' 
    #RewriteRule ^(.*)$ https://your-domain.com/$1 [L,R=301] 


    #################################################################################
    # START STASH STATIC CACHE

    # Exclude image files
    RewriteCond $1 !\.(gif|jpe?g|png|css|js|ico)$ [NC]

    # We only want GET requests
    RewriteCond %{REQUEST_METHOD} ^GET

    # Exclude ACT
    RewriteCond %{HTTP:ACT} !=^$

    # Exclude AJAX
    RewriteCond %{HTTP:X-Requested-With} !=XMLHttpRequest

    # Exclude when css, ACT, URL or 'preview' in query string
    RewriteCond %{QUERY_STRING} !^(css|ACT|URL|preview)

    # Disable static caching for logged-in users
    RewriteCond %{HTTP_COOKIE} !exp_sessionid [NC]

    # Remove index.php from conditions
    RewriteCond $1 ^(index.php/)*(.*)(/*)$

    # Check if cached index.html exists
    RewriteCond %{DOCUMENT_ROOT}/static_cache/[site_id]/$2/index.html (.*\.(.*))$
    RewriteCond %1 -f

    # Rewrite to the cached page
    RewriteRule ^(index.php/*)*(.*)(/*) /static_cache/[site_id]/$2/index.%2 [L,E=STATIC:1]

    # Set CDN (s-max-age) and browser cache (max-age) durations for static cached pages
    SetEnvIf REDIRECT_STATIC 1 STATIC=1
    <IfModule mod_headers.c>
        Header set Cache-Control "s-maxage=86400, max-age=1800" ENV=STATIC
    </IfModule>

    #################################################################################

    # -------------------------------------------------------------------------------
    # Officially supported method to remove index.php from ExpressionEngine URLs
    # See: http://ellislab.com/expressionengine/user-guide/urls/remove_index.php.html
    # -------------------------------------------------------------------------------

    RewriteBase /

    # Removes index.php from ExpressionEngine URLs
    RewriteCond %{THE_REQUEST} ^GET.*index\.php [NC]
    RewriteCond %{REQUEST_URI} !/system/.* [NC]
    RewriteRule (.*?)index\.php/*(.*) /$1$2 [R=301,NE,L]

    # Directs all EE web requests through the site index file
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ /index.php/$1 [L]

    </IfModule>


