<?php
require 'autoload.php';

$script = eZScript::instance(array('description' => ("TEST SOAP\n\n"),
    'use-session' => false,
    'use-modules' => true,
    'use-extensions' => true));

$script->startup();

$options = $script->getOptions();
$script->initialize();
$script->setUseDebugAccumulators(true);
$cli = eZCLI::instance();
eZDB::setErrorHandling(eZDB::ERROR_HANDLING_EXCEPTIONS);

try{
    $data = OpenPABolzanoImportJirideHandler::fetchData(null, time());
    print_r($data);

}catch (Exception $e){
    $cli->error($e->getMessage());
    $cli->error($e->getTraceAsString());
}