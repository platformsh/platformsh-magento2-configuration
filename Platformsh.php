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
    protected $dbPassword;

    protected $adminUsername;
    protected $adminFirstname;
    protected $adminLastname;
    protected $adminEmail;
    protected $adminPassword;

    protected $redisHost;
    protected $redisScheme;
    protected $redisPort;

    protected $solrHost;
    protected $solrPath;
    protected $solrPort;
    protected $solrScheme;

    protected $lastOutput = array();
    protected $lastStatus = null;

    /**
     * Parse Platform.sh routes to more readable format.
     */
    public function initRoutes()
    {
        $this->log("Initializing routes.");

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

        if (!count($this->urls['secure'])) {
            $this->urls['secure'] = $this->urls['unsecure'];
        }

        $this->log(sprintf("Routes: %s", var_export($this->urls, true)));
    }

    /**
     * Build application: clear temp directory and move writable directories content to temp.
     */
    public function build()
    {
        $this->log("Start build.");

        $this->clearTemp();

        $this->log("Copying read/write directories to temp directory.");

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
        $this->log("Start deploy.");

        $this->_init();

        $this->log("Copying read/write directories back.");

        foreach ($this->platformReadWriteDirs as $dir) {
            $this->execute(sprintf('/bin/bash -c "shopt -s dotglob; cp -R ../init/%s/* %s/ || true"', $dir, $dir));
            $this->log(sprintf('Copied directory: %s', $dir));
        }

        if (!file_exists('app/etc/env.php')) {
            $this->installMagento();
        } else {
            $this->updateMagento();
        }
    }

    /**
     * Prepare data needed to install Magento
     */
    protected function _init()
    {
        $this->log("Preparing environment specific data.");

        $this->initRoutes();

        $relationships = $this->getRelationships();
        $var = $this->getVariables();

        $this->dbHost = $relationships["database"][0]["host"];
        $this->dbName = $relationships["database"][0]["path"];
        $this->dbUser = $relationships["database"][0]["username"];
        $this->dbPassword = $relationships["database"][0]["password"];

        $this->adminUsername = isset($var["ADMIN_USERNAME"]) ? $var["ADMIN_USERNAME"] : "admin";
        $this->adminFirstname = isset($var["ADMIN_FIRSTNAME"]) ? $var["ADMIN_FIRSTNAME"] : "John";
        $this->adminLastname = isset($var["ADMIN_LASTNAME"]) ? $var["ADMIN_LASTNAME"] : "Doe";
        $this->adminEmail = isset($var["ADMIN_EMAIL"]) ? $var["ADMIN_EMAIL"] : "john@example.com";
        $this->adminPassword = isset($var["ADMIN_PASSWORD"]) ? $var["ADMIN_PASSWORD"] : "admin12";

        $this->redisHost = $relationships['redis'][0]['host'];
        $this->redisScheme = $relationships['redis'][0]['scheme'];
        $this->redisPort = $relationships['redis'][0]['port'];

        $this->solrHost = $relationships["solr"][0]["host"];
        $this->solrPath = $relationships["solr"][0]["path"];
        $this->solrPort = $relationships["solr"][0]["port"];
        $this->solrScheme = $relationships["solr"][0]["scheme"];
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
        $this->log("File env.php does not exist. Installing Magento.");

        $urlUnsecure = $this->urls['unsecure'][''];
        $urlSecure = $this->urls['secure'][''];

        $command =
            "cd bin/; /usr/bin/php ./magento setup:install \
            --session-save=db \
            --cleanup-database \
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
            --admin-password=$this->adminPassword";

        if (strlen($this->dbPassword)) {
            $command .= " \
            --db-password=$this->dbPassword";
        }

        $this->execute($command);

        $this->deployStaticContent();
    }

    /**
     * Update Magento configuration
     */
    protected function updateMagento()
    {
        $this->log("File env.php exists. Updating configuration.");

        $this->updateConfiguration();

        $this->updateDatabaseConfiguration();

        $this->updateSolrConfiguration();

        $this->updateUrls();

        $this->setupUpgrade();

        $this->clearCache();

        $this->deployStaticContent();
    }

    /**
     * Update admin credentials
     */
    protected function updateDatabaseConfiguration()
    {
        $this->log("Updating database configuration.");

        if (strlen($this->dbPassword)) {
            $password = sprintf('-p%s', $this->dbPassword);
        }

        $this->execute("mysql -u $this->dbUser -h $this->dbHost -e \"update admin_user set firstname = '$this->adminFirstname', lastname = '$this->adminLastname', email = '$this->adminEmail', username = '$this->adminUsername' where user_id = '1';\" $password $this->dbName");
    }

    /**
     * Update SOLR configuration
     */
    protected function updateSolrConfiguration()
    {
        $this->log("Updating SOLR configuration.");

        if (strlen($this->dbPassword)) {
            $password = sprintf('-p%s', $this->dbPassword);
        }

        $this->execute("mysql -u $this->dbUser -h $this->dbHost -e \"update core_config_data set value = '$this->solrHost' where path = 'catalog/search/solr_server_hostname' and scope_id = '0';\" $password $this->dbName");
        $this->execute("mysql -u $this->dbUser -h $this->dbHost -e \"update core_config_data set value = '$this->solrPort' where path = 'catalog/search/solr_server_port' and scope_id = '0';\" $password $this->dbName");
        $this->execute("mysql -u $this->dbUser -h $this->dbHost -e \"update core_config_data set value = '$this->solrScheme' where path = 'catalog/search/solr_server_username' and scope_id = '0';\" $password $this->dbName");
        $this->execute("mysql -u $this->dbUser -h $this->dbHost -e \"update core_config_data set value = '$this->solrPath' where path = 'catalog/search/solr_server_path' and scope_id = '0';\" $password $this->dbName");
    }

    /**
     * Update secure and unsecure URLs 
     */
    protected function updateUrls()
    {
        $this->log("Updating secure and unsecure URLs.");

        if (strlen($this->dbPassword)) {
            $password = sprintf('-p%s', $this->dbPassword);
        }

        foreach ($this->urls as $urlType => $urls) {
            foreach ($urls as $route => $url) {
                $prefix = 'unsecure' === $urlType ? self::PREFIX_UNSECURE : self::PREFIX_SECURE;
                if (!strlen($route)) {
                    $this->execute("mysql -u $this->dbUser -h $this->dbHost -e \"update core_config_data set value = '$url' where path = 'web/$urlType/base_url' and scope_id = '0';\" $password $this->dbName");
                    continue;
                }
                $likeKey = $prefix . $route . '%';
                $likeKeyParsed = $prefix . str_replace('.', '---', $route) . '%';
                $this->execute("mysql -u $this->dbUser -h $this->dbHost -e \"update core_config_data set value = '$url' where path = 'web/$urlType/base_url' and (value like '$likeKey' or value like '$likeKeyParsed');\" $password $this->dbName");
            }
        }
    }

    /**
     * Clear content of temp directory
     */
    protected function clearTemp()
    {
        $this->log("Clearing temporary directory.");

        $this->execute('rm -rf ../init/*');
    }

    /**
     * Run Magento setup upgrade
     */
    protected function setupUpgrade()
    {
        $this->log("Running setup upgrade.");

        $this->execute(
            "cd bin/; /usr/bin/php ./magento setup:upgrade"
        );
    }

    /**
     * Clear Magento file based cache
     */
    protected function clearCache()
    {
        $this->log("Clearing cache.");

        $this->log("Clearing generated code.");

        $this->execute('rm -rf var/generation/*');

        $this->log("Clearing application cache.");

        $this->execute(
            "cd bin/; /usr/bin/php ./magento cache:flush"
        );
    }

    /**
     * Generates static view files content
     */
    protected function deployStaticContent()
    {
        $this->log("Generating static content.");

        $this->execute(
            "cd bin/; /usr/bin/php ./magento setup:static-content:deploy"
        );
    }

    /**
     * Update env.php file content
     */
    protected function updateConfiguration()
    {
        $this->log("Updating env.php database configuration.");

        $configFileName = "app/etc/env.php";

        $config = include $configFileName;

        $config['db']['connection']['default']['username'] = $this->dbUser;
        $config['db']['connection']['default']['host'] = $this->dbHost;
        $config['db']['connection']['default']['dbname'] = $this->dbName;

        if (
            isset($config['cache']['frontend']['default']['backend']) &&
            isset($config['cache']['frontend']['default']['backend_options']) &&
            'Cm_Cache_Backend_Redis' == $config['cache']['frontend']['default']['backend']
        ) {
            $this->log("Updating env.php Redis cache configuration.");

            $config['cache']['frontend']['default']['backend_options']['server'] = $this->redisHost;
            $config['cache']['frontend']['default']['backend_options']['port'] = $this->redisPort;
        }

        if (
            isset($config['cache']['frontend']['page_cache']['backend']) &&
            isset($config['cache']['frontend']['page_cache']['backend_options']) &&
            'Cm_Cache_Backend_Redis' == $config['cache']['frontend']['page_cache']['backend']
        ) {
            $this->log("Updating env.php Redis page cache configuration.");

            $config['cache']['frontend']['page_cache']['backend_options']['server'] = $this->redisHost;
            $config['cache']['frontend']['page_cache']['backend_options']['port'] = $this->redisPort;
        }

        $updatedConfig = '<?php'  . "\n" . 'return ' . var_export($config, true) . ';';

        file_put_contents($configFileName, $updatedConfig);
    }

    protected function log($message)
    {
        echo sprintf('[%s] %s', date("Y-m-d H:i:s"), $message) . PHP_EOL;
    }

    protected function execute($command)
    {
        if ($this->debugMode) {
            $this->log('Command:'.$command);
        }

        $this->lastOutput = array();
        $this->lastStatus = null;

        exec(
            $command,
            $this->lastOutput,
            $this->lastStatus
        );

        if ($this->debugMode) {
            $this->log('Status:'.var_export($this->lastStatus, true));
            $this->log('Output:'.var_export($this->lastOutput, true));
        }
    }
}