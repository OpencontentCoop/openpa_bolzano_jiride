<?php

use Aws\S3\S3Client as S3Client;

abstract class AWSS3Abstract
{
    /** @var Aws\S3\S3Client */
    protected $s3client;

    /** @var string */
    protected $bucket;

    /** @var string */
    protected $httpHost;

    /** @var string */
    protected $protocol;

    protected function __construct(S3Client $s3client, $bucket, $httpHost, $protocol = 'https')
    {
        $this->s3client = $s3client;
        $this->bucket = $bucket;
        $this->httpHost = $httpHost;
        $this->protocol = $protocol;
    }

    /**
     * @return S3Client
     */
    public function getS3Client()
    {
        return $this->s3client;
    }

    public function getBucket()
    {
        return $this->bucket;
    }

    /**
     * @return static
     */
    public static function build()
    {
        $parameters = array();
        $region = isset($parameters['Region']) ? $parameters['Region'] : static::getRegionConfig();
        $bucket = isset($parameters['Bucket']) ? $parameters['Bucket'] : static::getBucketConfig();

        $args = [
            'region' => $region,
            'version' => 'latest',
        ];

        $httpHost = 's3-' . $region . '.amazonaws.com';

        if (isset($parameters['ServerUri'])){
            $httpHost = $parameters['ServerUri'];
        }

        $protocol = isset($parameters['ServerProtocol']) ? $parameters['ServerProtocol'] : 'https';

        if (isset($parameters['Endpoint'])){
            $args['endpoint'] = $parameters['Endpoint'];
        }

        if (isset($parameters['UsePathStyleEndpoint'])){
            $args['use_path_style_endpoint'] = $parameters['UsePathStyleEndpoint'] == 'enabled';
        }

        // $args['debug'] = [
        //     'logfn' => function($msg){
        //         if (!empty($msg)) eZDebug::writeDebug($msg, get_called_class());
        //     },
        //     'stream_size'  => 0,
        //     'scrub_auth'   => true,
        //     'http'         => true,
        //     'auth_headers' => [
        //         'X-My-Secret-Header' => '[REDACTED]',
        //     ],
        //     'auth_strings' => [
        //         '/SuperSecret=[A-Za-z0-9]{20}/i' => 'SuperSecret=[REDACTED]',
        //     ],
        // ];        

        $sdk = new Aws\Sdk($args);
        $client = $sdk->createS3();

        return new static($client, $bucket, $httpHost, $protocol);
    }

    protected static function getRegionConfig()
    {
        $region = getenv('AWS_REGION');
        if ($region){
            return $region;
        }

        if(defined('AWS_REGION')){
            return AWS_REGION;
        }

        return 'eu-west-1';
    }

    protected  static function getBucketConfig()
    {
        $bucket = getenv('AWS_BUCKET');
        if ($bucket){
            return $bucket;
        }

        if(defined('AWS_BUCKET')){
            return AWS_BUCKET;
        }

        $rootDir = eZSys::rootDir();
        $rootDirParts = explode('/', $rootDir);
        array_pop($rootDirParts); //html
        $bucket = array_pop($rootDirParts);

        return $bucket;
    }
}
