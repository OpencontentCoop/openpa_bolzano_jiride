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

$data = OpenPABolzanoImportJirideHandler::fetchAllegato($serial);
$filename = $data->NomeAllegato;
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
