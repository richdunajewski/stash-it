<?php
error_reporting(E_ALL ^ E_NOTICE);

include 'SabreDAV/vendor/autoload.php';

//connection settings for WebDAV client to connect to device
$stash = new StashUpload(array(
    'baseUri'  => 'http://192.168.1.100/files/',
    'userName' => 'anonymous'
));

$stash->dir = 'C:\\Users\\Rich\\Desktop\\music_to_upload';
$files         = $stash->find_all_files($stash->dir);
foreach ($files as $file)
{
    //check only for .mp3 files
    $info = pathinfo($file);
    if ( strtoupper($info['extension']) === 'MP3' )
    {
        $stash->upload_file($file, $info);
    }
}


Class StashUpload
{
    /**
     * @var array
     */
    public $settings;

    /**
     * @var string
     */
    public $dir;

    /**
     * @var Sabre\DAV\Client
     */
    protected $client;

    /**
     * Class constructor
     *
     * @param array $settings
     */
    public function __construct($settings)
    {
        //create WebDAV client for talking to device
        $this->settings = $settings;
        $this->client   = new \Sabre\DAV\Client($this->settings);
    }


    /**
     * Creates directory on the WebDAV server and will rawurlencode the name so special characters do not create wacky files on the host
     * (particularly a nuisance on Windows, which may not be able to delete these files)
     *
     * @param string $coll
     */
    protected function make_collection($coll)
    {
        try
        {
            $response = $this->client->request('MKCOL', rawurlencode($coll));
        }
        catch ( Exception $e )
        {
            //print_r($e);
            //this is okay to ignore, it creates the collection but still bitches
        }
    }


    /**
     * Recursively checks a given path to see if each directory already exists on the WebDAV host, and if not, will create each directory in the hierarchy before the file can be PUT
     *
     * @param string $coll
     * @return bool
     */
    protected function make_collection_recursively($coll)
    {
        $last_dir = '';
        foreach (explode('/', $coll) as $dir)
        {

            echo "Looking for: " . $this->settings['base_url'] . $last_dir . $dir . PHP_EOL;

            try
            {
                $response = $this->client->propFind($this->settings['base_url'] . rawurlencode($last_dir) . rawurlencode($dir), array( '{DAV:}getcontenttype' ), 0);
            }
            catch ( Exception $e )
            {
                echo "\"{$dir}\" still not found, creating collection... " . $last_dir . $dir . PHP_EOL;
                $this->make_collection($last_dir . $dir);
            }

            $last_dir .= $dir . '/';
        }

        return TRUE;
    }


    /**
     * Upload file to WebDAV host via PUT method (which is appropriate for creating AND updating files, the host will respond with appropriate statusCode depending on whether the file was created or modified
     *
     * @todo Refactor this so only the source filename is required, the info parameter is redundant
     *
     * @param string $file Full path of the source file
     * @param array  $info File info consisting of basename, extension, and path
     */
    public function upload_file($file, $info)
    {
        $relative_dir = str_replace($this->dir . '/', '', $info['dirname']);
        $filepath     = $relative_dir . '/' . $info['basename'];

        $mdate = date("Y-m-d H:i:sO", filemtime($file));

        //this will create the directory structure if needed, otherwise keep going so we can PUT the file next
        $this->make_collection_recursively($relative_dir);

        //now actually PUT that file up there... X-Airstash-Date header is required to set the modified date/time on the remote host, otherwise you'll get strange results like the Unix Epoch or worse!
        $response = $this->client->request('PUT', rawurlencode($filepath), file_get_contents($file), array( "X-Airstash-Date" => $mdate ));

        //handle return status code
        switch ( $response["statusCode"] )
        {
            case 201:
                echo $filepath . " created successfully\n";
                break;
            case 204:
                echo $filepath . " updated successfully\n";
                break;
            default:
                echo "An error has occurred:\n";
                print_r($response);
                break;
        }
    }


    /**
     * Deletes a single file on the WebDAV host
     *
     * @param string $file
     */
    public function delete_file($file)
    {
        try
        {
            $response = $this->client->request('DELETE', $file);

            return TRUE;
        }
        catch ( Exception $e )
        {
            print_r($e);

            return FALSE;
        }

    }


    /**
     * Recursively scans through directory and returns all files nested within it
     *
     * http://www.php.net/manual/en/function.scandir.php#107117
     *
     * @param string $dir
     * @return array
     */
    public function find_all_files($dir)
    {
        $root = scandir($dir);
        foreach ($root as $value)
        {
            if ( $value === '.' || $value === '..' )
            {
                continue;
            }
            if ( is_file("$dir/$value") )
            {
                $result[] = "$dir/$value";
                continue;
            }
            foreach ($this->find_all_files("$dir/$value") as $value)
            {
                $result[] = $value;
            }
        }
        return $result;
    }

}