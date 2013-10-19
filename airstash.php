<?php
error_reporting(E_ALL ^ E_NOTICE);

include 'SabreDAV/vendor/autoload.php';

//connection settings for WebDAV client to connect to device
$airstash = new AirstashUpload(array(
    'baseUri'  => 'http://192.168.1.100/files/',
    'userName' => 'anonymous'
));

//$files = scandir('C:\\Users\\Rich\\Desktop\\music_to_upload');
$airstash->dir = 'C:\\Users\\Rich\\Desktop\\music_to_upload';
$files         = $airstash->find_all_files($airstash->dir);
foreach ($files as $file)
{
    //check only for .mp3 files
    $info = pathinfo($file);
    if ( strtoupper($info['extension']) === 'MP3' )
    {
        $airstash->upload_file($file, $info);

        //exit;
    }
}


Class AirstashUpload
{
    public $settings;
    protected $client;
    public $dir;

    public function __construct($settings)
    {
        //create WebDAV client for talking to device
        $this->settings = $settings;
        $this->client   = new \Sabre\DAV\Client($this->settings);
    }


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


    public function upload_file($file, $info)
    {
        $relative_dir = str_replace($this->dir . '/', '', $info['dirname']);
        $filepath     = $relative_dir . '/' . $info['basename'];

        $mdate = date("Y-m-d H:i:sO", filemtime($file));

        //this will create the directory structure if needed, otherwise keep going so we can PUT the file next
        $this->make_collection_recursively($relative_dir);

        //now actually PUT that file up there...
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


    public function delete_file($file)
    {
        try
        {
            $response = $this->client->request('DELETE', $file);
        }
        catch ( Exception $e )
        {
            print_r($e);
        }
    }


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