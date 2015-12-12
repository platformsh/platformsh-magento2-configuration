<?php

class Platformsh
{
    const MAGIC_ROUTE = '{default}';

    const PREFIX_SECURE = 'https://';
    const PREFIX_UNSECURE = 'http://';

    protected $debugMode = false;

    protected $platformReadWriteDirs = ['var', 'app/etc', 'pub'];

    protected $urls = ['unsecure' => [], 'secure' => []];

    protected $defaultCurrency = 'USD';

    protected $dbHost;
    protected $dbName;
    protected $dbUser;

    protected $adminUsername;
    protected $adminFirstname;
    protected $adminLastname;
    protected $adminEmail;
    protected $adminPassword;

    protected $redisCacheHost;
    protected $redisCacheScheme;
    protected $redisCachePort;

    protected $redisFpcHost;
    protected $redisFpcScheme;
    protected $redisFpcPort;

    protected $redisSessionHost;
    protected $redisSessionScheme;
    protected $redisSessionPort;

    protected $lastOutput = array();
    protected $lastStatus = null;

    /**
     * Prepare data needed to install Magento
     */
    public function init()
    {
        $this->log("Preparing environment specific data.");

        $this->initRoutes();

        $relationships = $this->getRelationships();
        $var = $this->getVariables();

        $this->dbHost = $relationships["database"][0]["host"];
        $this->dbName = $relationships["database"][0]["path"];
        $this->dbUser = $relationships["database"][0]["username"];

        $this->adminUsername = isset($var["ADMIN_USERNAME"]) ? $var["ADMIN_USERNAME"] : "admin";
        $this->adminFirstname = isset($var["ADMIN_FIRSTNAME"]) ? $var["ADMIN_FIRSTNAME"] : "John";
        $this->adminLastname = isset($var["ADMIN_LASTNAME"]) ? $var["ADMIN_LASTNAME"] : "Doe";
        $this->adminEmail = isset($var["ADMIN_EMAIL"]) ? $var["ADMIN_EMAIL"] : "john@example.com";
        $this->adminPassword = isset($var["ADMIN_PASSWORD"]) ? $var["ADMIN_PASSWORD"] : "admin12";

        $this->redisCacheHost = $relationships['rediscache'][0]['host'];
        $this->redisCacheScheme = $relationships['rediscache'][0]['scheme'];
        $this->redisCachePort = $relationships['rediscache'][0]['port'];

        $this->redisFpcHost = $relationships['redisfpc'][0]['host'];
        $this->redisFpcScheme = $relationships['redisfpc'][0]['scheme'];
        $this->redisFpcPort = $relationships['redisfpc'][0]['port'];

        $this->redisSessionHost = $relationships['redissession'][0]['host'];
        $this->redisSessionScheme = $relationships['redissession'][0]['scheme'];
        $this->redisSessionPort = $relationships['redissession'][0]['port'];
    }

    /**
     * Parse Platform.sh routes to more readable format.
     */
    public function initRoutes()
    {
        $routes = $this->getRoutes();

        foreach($routes as $key => $val) {
            if ($val["type"] !== "upstream") {
                continue;
            }

            $urlParts = parse_url($val['original_url']);
            $originalUrl = str_replace(self::MAGIC_ROUTE, '', $urlParts['host']);

            if(strpos($key, self::PREFIX_UNSECURE) === 0) {
                $this->urls['unsecure'][$originalUrl] = $key;
                continue;
            }

            if(strpos($key, self::PREFIX_SECURE) === 0) {
                $this->urls['secure'][$originalUrl] = $key;
                continue;
            }
        }
    }

    /**
     * Build application: clear temp directory and move writable directories content to temp.
     */
    public function build()
    {
        $this->clearTemp();

        foreach ($this->platformReadWriteDirs as $dir) {
            $this->execute(sprintf('mkdir -p ../init/%s', $dir));
            $this->execute(sprintf('/bin/bash -c "shopt -s dotglob; cp -R %s/* ../init/%s/"', $dir, $dir));
            $this->execute(sprintf('rm -rf %s', $dir));
            $this->execute(sprintf('mkdir %s', $dir));
        }
    }

    /**
     * Deploy application: copy writable directories back, install or update Magento data.
     */
    public function deploy()
    {
        // Copy read-write directories back
        foreach ($this->platformReadWriteDirs as $dir) {
            $this->execute(sprintf('/bin/bash -c "shopt -s dotglob; cp -R ../init/%s/* %s/ || true"', $dir, $dir));
            $this->log(sprintf('Copied directory: %s', $dir));
        }

        if (!file_exists('app/etc/config.php')) {
            $this->installMagento();
        } else {
            $this->updateMagento();
        }
    }

    /**
     * Get routes information from Platform.sh environment variable.
     *
     * @return mixed
     */
    protected function getRoutes()
    {
        return json_decode(base64_decode($_ENV["PLATFORM_ROUTES"]), true);
    }

    /**
     * Get relationships information from Platform.sh environment variable. 
     *
     * @return mixed
     */
    protected function getRelationships()
    {
        return json_decode(base64_decode($_ENV["PLATFORM_RELATIONSHIPS"]), true);
    }

    /**
     * Get custom variables from Platform.sh environment variable.
     *
     * @return mixed
     */
    protected function getVariables()
    {
        return json_decode(base64_decode($_ENV["PLATFORM_VARIABLES"]), true);
    }

    /**
     * Run Magento installation
     */
    protected function installMagento()
    {
        $this->log("File config.php does not exist. Installing Magento.");

        $urlUnsecure = $this->urls['unsecure'][''];
        $urlSecure = $this->urls['secure'][''];

        $this->execute(
            "cd bin/; /usr/bin/php ./magento setup:install \
            --currency=$this->defaultCurrency \
            --base-url=$urlUnsecure \
            --base-url-secure=$urlSecure \
            --language=en_US \
            --timezone=America/Los_Angeles \
            --db-host=$this->dbHost \
            --db-name=$this->dbName \
            --db-user=$this->dbUser \
            --backend-frontname=admin \
            --admin-user=$this->adminUsername \
            --admin-firstname=$this->adminFirstname \
            --admin-lastname=$this->adminLastname \
            --admin-email=$this->adminEmail \
            --admin-password=$this->adminPassword"
        );
    }

    /**
     * Update Magento configuration
     */
    protected function updateMagento()
    {
        $this->log("File config.php exists.");

        $this->updateConfiguration();

        $this->updateDatabaseConfiguration();
        
        $this->updateUrls();

        $this->clearCache();
    }

    /**
     * Update admin credentials
     */
    protected function updateDatabaseConfiguration()
    {
        // @todo migrate for Magento 2
        return;

        $this->log("Updating database configuration.");

        $this->execute("mysql -u user -h $this->dbHost -e \"update admin_user set firstname = '$this->adminFirstname', lastname = '$this->adminLastname', email = '$this->adminEmail', username = '$this->adminUsername', password = md5('$this->adminPassword') where user_id = '1';\" $this->dbName");
    }

    /**
     * Update secure and unsecure URLs 
     */
    protected function updateUrls()
    {
        // @todo migrate for Magento 2
        return;

        foreach ($this->urls as $urlType => $urls) {
            foreach ($urls as $route => $url) {
                $prefix = 'unsecure' === $urlType ? self::PREFIX_UNSECURE : self::PREFIX_SECURE;
                if (!strlen($route)) {
                    $this->execute("mysql -u user -h $this->dbHost -e \"update core_config_data set value = '$url' where path = 'web/$urlType/base_url' and scope_id = '0';\" $this->dbName");
                    continue;
                }
                $likeKey = $prefix . $route . '%';
                $likeKeyParsed = $prefix . str_replace('.', '---', $route) . '%';
                $this->execute("mysql -u user -h $this->dbHost -e \"update core_config_data set value = '$url' where path = 'web/$urlType/base_url' and (value like '$likeKey' or value like '$likeKeyParsed');\" $this->dbName");
            }
        }
    }

    /**
     * Clear content of temp directory
     */
    protected function clearTemp()
    {
        $this->execute('rm -rf ../init/*');
    }

    /**
     * Clear Magento file based cache
     *
     * @todo think about way to clean redis cache.
     */
    protected function clearCache()
    {
        // @todo migrate for Magento 2
        return;

        $this->log("Clearing cache.");
        $this->execute('rm -rf var/cache/* var/full_page_cache/* media/css/* media/js/*');
    }

    /**
     * Update config.php file content
     */
    protected function updateConfiguration()
    {
        // @todo migrate for Magento 2
        return;

        $this->log("Updating config.php configuration.");

        $configFileName = "app/etc/config.php";

        $config = simplexml_load_file($configFileName);

        $dbConfig = $config->xpath('/config/global/resources/default_setup/connection')[0];
        $cacheBackend = $config->xpath('/config/global/cache/backend')[0];

        $dbConfig->username = $this->dbUser;
        $dbConfig->host = $this->dbHost;
        $dbConfig->dbname = $this->dbName;

        if ('Cm_Cache_Backend_Redis' == $cacheBackend) {
            $cacheConfig = $config->xpath('/config/global/cache/backend_options')[0];
            $fpcConfig = $config->xpath('/config/global/full_page_cache/backend_options')[0];
            $sessionConfig = $config->xpath('/config/global/redis_session')[0];

            $cacheConfig->port = $this->redisCachePort;
            $cacheConfig->server = $this->redisCacheHost;

            $fpcConfig->port = $this->redisFpcPort;
            $fpcConfig->server = $this->redisFpcHost;

            $sessionConfig->port = $this->redisSessionPort;
            $sessionConfig->host = $this->redisSessionHost;
        }

        $config->saveXML($configFileName);
    }

    protected function log($message)
    {
        echo $message . PHP_EOL;
    }

    protected function execute($command)
    {
        if ($this->debugMode) {
            $this->log($command);
        }
        exec(
            $command//,
            //$this->lastOutput, 
            //$this->lastStatus
        );
        // @todo log output in debug mode
    }
}