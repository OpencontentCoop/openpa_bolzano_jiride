<?php
require 'autoload.php';

$script = eZScript::instance(array('description' => ("Push in S3\n\n"),
    'use-session' => false,
    'use-modules' => true,
    'use-extensions' => true));

$script->startup();

$options = $script->getOptions(
    '[key:][secret:]',
    '',
    []
);
$script->initialize();
$script->setUseDebugAccumulators(true);
$cli = eZCLI::instance();

$startTime = new eZDateTime();

$user = eZUser::fetchByName('admin');
eZUser::setCurrentlyLoggedInUser($user, $user->attribute('contentobject_id'));

putenv("AWS_ACCESS_KEY_ID=" . $options['key']);
putenv("AWS_SECRET_ACCESS_KEY=" . $options['secret']);

function filePathForBinaryFile($fileName, $mimeType)
{
    $storageDir = eZSys::storageDirectory();
    list($group, $type) = explode('/', $mimeType);
    $filePath = $storageDir . '/original/' . $group . '/' . $fileName;
    return $filePath;
}

define('AWS_REGION', 'eu-west-1');
define('AWS_BUCKET', 'static.comune.bolzano.it');

$privateHandler = AWSS3Private::build();
$publicHandler = AWSS3Public::build();

/****
$s3client = $publicHandler->getS3Client();
$bucket = $publicHandler->getBucket();

$key = 'test';
$filePath = eZSys::cacheDirectory() . '/' . 'test-s3.txt';
$testContent = 'Test aws s3 connection';
eZFile::create(basename($filePath), dirname($filePath), $testContent);
try {
    $s3client->putObject(
        array(
            'Bucket' => $bucket,
            'Key' => $key,
            'SourceFile' => $filePath
        )
    );

    $object = $s3client->getObject(
        array(
            'Bucket' => $bucket,
            'Key' => $key
        )
    );
    $content = (string)$object['Body'];
    if ($content == $testContent) {
        $cli->output('Test S3 ok');
    }

    $s3client->deleteObject(
        array(
            'Bucket' => $bucket,
            'Key' => $key,
        )
    );
}catch (Exception $e){
    if ($e instanceof \Aws\Exception\AwsException)
        $cli->error($e->getAwsErrorMessage());
    else
        $cli->error($e->getMessage());
}

$script->shutdown(1);
****/

function getPaths($filePath)
{
    $paths = [];
    foreach (['sensor', 'agenda', 'dimmi', 'sito'] as $context) {
        $varDir = false;
        switch ($context) {
            case 'sensor':
                $varDir = 'var/bolzano_opensegnalazioni/';
                break;

            case 'agenda':
                $varDir = 'var/bolzano_openagenda/';
                break;

            case 'dimmi':
                $varDir = 'var/bolzano_openconsultazioni/';
                break;

            case 'sito':
                $varDir = 'var/bolzano_opencity/';
                break;
        }
        $copyFilePath = $filePath;
        $paths[] = str_replace('var/sensor/', $varDir, $copyFilePath);
    }

    return array_unique($paths);
}

function pushFile($filePath)
{
    global $privateHandler, $publicHandler, $cli;
    if (strpos($filePath, 'var/sensor/storage/images') === false){
        $handler = $privateHandler;
        $cli->output(' (private) ', false);
    }else{
        $handler = $publicHandler;
    }

    $paths = getPaths($filePath);
    if (!file_exists('./' . $filePath)){
        $cli->error('NOT FOUND');
        return;
    }
    foreach ($paths as $path){
        if (!$handler->getFile($path)){
            $handler->copyToDFS('./' . $filePath, $path);
            $cli->warning('*', false);
        }else{
            $cli->output('+', false);
        }
    }
    $cli->output();
}

try {

    $db = eZDB::instance();

    $cli->output("Migrating images and imagealiases files");
    $rows = $db->arrayQuery('select filepath from ezimagefile');
    $total = count($rows);
    foreach ($rows as $index => $row) {
        if ($row['filepath'] == '') continue;
        $filePath = $row['filepath'];
        $message = "$index/$total - " . $filePath . ' ';
        $cli->output($message, false);
        pushFile($filePath);
    }
    $cli->output();

    $cli->output("Migrating binary files");
    $rows = $db->arrayQuery('select filename, mime_type from ezbinaryfile');
    $total = count($rows);
    foreach ($rows as $index => $row) {
        if ($row['filename'] == '') continue;
        $filePath = filePathForBinaryFile($row['filename'], $row['mime_type']);
        $message = "$index/$total - " . $filePath . ' ';
        $cli->output($message, false);
        pushFile($filePath);
    }
    $cli->output();

    $cli->output("Migrating media files");
    $rows = $db->arrayQuery('select filename, mime_type from ezmedia');
    $total = count($rows);
    foreach ($rows as $index => $row) {
        if ($row['filename'] == '') continue;
        $filePath = filePathForBinaryFile($row['filename'], $row['mime_type']);
        $message = "$index/$total - " . $filePath . ' ';
        $cli->output($message, false);
        pushFile($filePath);
    }
    $cli->output();

} catch (Exception $e) {
    $cli->error($e->getMessage());
}

$endTime = new eZDateTime();
$elapsedTime = new eZTime($endTime->timeStamp() - $startTime->timeStamp());
$message = 'Elapsed time: ' . sprintf('%02d:%02d:%02d', $elapsedTime->hour(), $elapsedTime->minute(), $elapsedTime->second());
$cli->output($message);

$script->shutdown();