<?php

use \League\Flysystem\Filesystem;
use \League\Flysystem\Adapter\Local;
use \League\Flysystem\Cached\CachedAdapter;
use \League\Flysystem\Cached\Storage\Adapter as ACache;
use \Hypweb\Flysystem\GoogleDrive\GoogleDriveAdapter;
use \Hypweb\Flysystem\Cached\Extra\Hasdir;
use \Hypweb\Flysystem\Cached\Extra\DisableEnsureParentDirectories;

elFinder::$netDrivers['googledrive'] = 'FlysystemGoogleDriveNetmount';

if (! class_exists('elFinderVolumeFlysystemGoogleDriveCache', false)) {
    class elFinderVolumeFlysystemGoogleDriveCache extends ACache
    {
        use Hasdir;
        use DisableEnsureParentDirectories;
    }
}

class elFinderVolumeFlysystemGoogleDriveNetmount extends \Barryvdh\elFinderFlysystemDriver\Driver
{

    public function __construct()
    {
        parent::__construct();
        
        $opts = array(
            'rootCssClass' => 'elfinder-navbar-root-googledrive',
            'gdCacheDir'     => __DIR__ . '/.tmp',
            'gdCachePrefix'  => 'gd-',
            'gdCacheExpire'  => 600,
        );

        $this->options = array_merge($this->options, $opts);
    }

    /**
     * Prepare driver before mount volume.
     * Return true if volume is ready.
     *
     * @return bool
     **/
    protected function init()
    {
        if (empty($this->options['icon'])) {
            $this->options['icon'] = true;
        }
        if ($res = parent::init()) {
            if ($this->options['icon'] === true) {
                unset($this->options['icon']);
            }
        }
        return $res;
    }

    /**
     * Prepare
     * Call from elFinder::netmout() before volume->mount()
     *
     * @return Array
     * @author Naoki Sawada
     **/
    public function netmountPrepare($options)
    {
        if (empty($options['client_id']) && defined('ELFINDER_GOOGLEDRIVE_CLIENTID')) {
            $options['client_id'] = ELFINDER_GOOGLEDRIVE_CLIENTID;
        }
        if (empty($options['client_secret']) && defined('ELFINDER_GOOGLEDRIVE_CLIENTSECRET')) {
            $options['client_secret'] = ELFINDER_GOOGLEDRIVE_CLIENTSECRET;
        }
        
        $client = new \Google_Client();
        $client->setClientId($options['client_id']);
        $client->setClientSecret($options['client_secret']);
        
        try {
            if (empty($options['user'])) {
                $options = $this->session->get('GoogleDriveAuthParams', []);
            }
            
            $aToken = $this->session->get('GoogleDriveTokens', []);
            
            if ($options['user'] === 'init') {
                if (empty($options['url'])) {
                    $options['url'] = $this->getConnectorUrl();
                }
                
                $callback  = $options['url']
                           . '?cmd=netmount&protocol=googledrive&host=1';
                $client->setRedirectUri($callback);

                if (empty($options['pass']) || (empty($_GET['code']) && !$aToken)) {
                    $html = '';
                    $client->setScopes([ Google_Service_Drive::DRIVE ]);
                    if (! empty($options['offline'])) {
                        $client->setApprovalPrompt('force');
                        $client->setAccessType('offline');
                    }
                    $url = $client->createAuthUrl();
                    
                    $html = '<input id="elf-volumedriver-googledrive-host-btn" class="ui-button ui-widget ui-state-default ui-corner-all ui-button-text-only" value="{msg:btnApprove}" type="button" onclick="window.open(\''.$url.'\')">';
                    $html .= '<script>
                        $("#'.$options['id'].'").elfinder("instance").trigger("netmount", {protocol: "googledrive", mode: "makebtn"});
                    </script>';
                    
                    if (empty($options['pass'])) {
                        $options['pass'] = 'return';
                        $this->session->set('GoogleDriveAuthParams', $options);
                        return array('exit' => true, 'body' => $html);
                    } else {
                        $out = array(
                            'node' => $options['id'],
                            'json' => '{"protocol": "googledrive", "mode": "makebtn", "body" : "'.str_replace($html, '"', '\\"').'", "error" : "errAccess"}',
                            'bind' => 'netmount'
                        );
                        return array('exit' => 'callback', 'out' => $out);
                    }
                } else {
                    if (! empty($_GET['code'])) {
                        $aToken = $client->fetchAccessTokenWithAuthCode($_GET['code']);
                        $this->session->set('GoogleDriveTokens', $aToken);
                    }
                    
                    $out = array(
                        'node' => $options['id'],
                        'json' => '{"protocol": "googledrive", "mode": "done"}',
                        'bind' => 'netmount'
                    );
                    
                    return array('exit' => 'callback', 'out' => $out);
                }
            }
            
            $this->session->remove('GoogleDriveAuthParams');
            
            $options['access_token'] = $aToken;
            $this->session->remove('GoogleDriveTokens');
        } catch (Exception $e) {
            $this->session->remove('GoogleDriveAuthParams');
            $this->session->remove('GoogleDriveTokens');
            return array('exit' => true, 'body' => '{msg:errAccess}'.' '.$e->getMessage());
        }
        
        unset($options['user'], $options['pass']);

        return $options;
    }

    /**
     * process of on netunmount
     * Drop table `dropbox` & rm thumbs
     * 
     * @param array $options
     * @return boolean
     */
    public function netunmount($netVolumes, $key)
    {
        $cache = $this->options['gdCacheDir'] . DIRECTORY_SEPARATOR . $this->options['gdCachePrefix'].$this->netMountKey;
        if (file_exists($cache) && is_writeable($cache)) {
            unlink($cache);
        }
        return true;
    }

    /**
     * "Mount" volume.
     * Return true if volume available for read or write, 
     * false - otherwise
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     * @author Alexey Sukhotin
     **/
    public function mount(array $opts)
    {
        $creds = null;
        if (isset($opts['access_token'])) {
            $this->netMountKey = md5(join('-', array('googledrive', $this->options['path'], (isset($opts['access_token']['refresh_token'])? $opts['access_token']['refresh_token'] : $opts['access_token']['access_token']))));
        }

        $client = new \Google_Client();
        $client->setClientId($opts['client_id']);
        $client->setClientSecret($opts['client_secret']);

        if (!empty($opts['access_token'])) {
            $client->setAccessToken($opts['access_token']);
        }
        if ($client->isAccessTokenExpired()) {
            $creds = $client->fetchAccessTokenWithRefreshToken();
        }

        $service = new \Google_Service_Drive($client);

        // If path is not set, use the root
        if (!isset($opts['path']) || $opts['path'] === '') {
            $opts['path'] = 'root';
        }
        
        $googleDrive = new GoogleDriveAdapter($service, $opts['path'], [ 'useHasDir' => true ]);

        $opts['fscache'] = null;
        if ($this->options['gdCacheDir'] && is_writeable($this->options['gdCacheDir'])) {
            if ($this->options['gdCacheExpire']) {
                $opts['fscache'] = new elFinderVolumeFlysystemGoogleDriveCache(new Local($this->options['gdCacheDir']), $this->options['gdCachePrefix'].$this->netMountKey, $this->options['gdCacheExpire']);
            }
        }
        if ($opts['fscache']) {
            $filesystem = new Filesystem(new CachedAdapter($googleDrive, $opts['fscache']));
        } else {
            $filesystem = new Filesystem($googleDrive);
        }

        $opts['driver'] = 'Flysystem';
        $opts['alias'] = 'MyGoogleDrive';
        $opts['filesystem'] = $filesystem;
        
        if ($res = parent::mount($opts)) {
            // update access_token of session data
            if ($creds) {
                $netVolumes = $this->session->get('netvolume');
                $netVolumes[$this->netMountKey]['access_token'] = array_merge($netVolumes[$this->netMountKey]['access_token'], $creds);
                $this->session->set('netvolume', $netVolumes);
            }
        }

        return $res;
    }

    /**
     * Get script url
     * 
     * @return string full URL
     * @author Naoki Sawada
     */
    private function getConnectorUrl()
    {
        $url  = ((isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')? 'https://' : 'http://')
               . $_SERVER['SERVER_NAME']                                              // host
              . ($_SERVER['SERVER_PORT'] == 80 ? '' : ':' . $_SERVER['SERVER_PORT'])  // port
               . $_SERVER['REQUEST_URI'];                                             // path & query
        list($url) = explode('?', $url);
        return $url;
    }
}
