<?php
/** @var eZModule $module */
$module = $Params['Module'];

$http = eZHTTPTool::instance();
$idDoc = $Params['IdDocumento'];
$serial = $Params['Serial'];

$remoteId = OpenPABolzanoImportJirideHandler::$remotePrefix . $idDoc;

$document = eZContentObject::fetchByRemoteID($remoteId);
if (!$document instanceof eZContentObject) {
    return $module->handleError(eZError::KERNEL_NOT_AVAILABLE, 'kernel');
}

if (!$document->canRead()) {
    return $module->handleError(eZError::KERNEL_ACCESS_DENIED, 'kernel');
}

$isDocumentLink = false;
$dataMap = $document->dataMap();
if (isset($dataMap['link'])){
    $content = $dataMap['link']->content();
    if ($content instanceof eZMatrix){
        foreach ($content->attribute('rows')['sequential'] as $row){
            if ($row['columns'][1] == '/j/download/' . $idDoc . '/' . $serial){
                $isDocumentLink = true;
                break;
            }
        }
    }
}

if (!$isDocumentLink) {
    return $module->handleError(eZError::KERNEL_ACCESS_DENIED, 'kernel');
}

$data = OpenPABolzanoImportJirideHandler::fetchAllegato($serial);
$filename = $data->NomeAllegato;
if (empty($filename)){
    $filename = $serial;
}
eZDir::mkdir(eZSys::cacheDirectory() . '/tmp', false, true);
$file = eZClusterFileHandler::instance(eZSys::cacheDirectory() . '/tmp/' . $filename);
$file->storeContents(base64_decode($data->Image));
$filesize = $file->size();
$mtime = $file->mtime();
$datatype = $file->dataType();

header( "Content-Type: {$datatype}" );
header( "Connection: close" );
header( 'Served-by: ' . $_SERVER["SERVER_NAME"] );
header( "Last-Modified: " . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
header( "ETag: $mtime-$filesize" );
header( "Cache-Control: max-age=2592000 s-max-age=2592000" );

$file->passthrough();

$file->delete();
$file->purge();
eZExecution::cleanExit();
