<?php
require 'autoload.php';

set_time_limit( 0 );

$cli = eZCLI::instance();
$script = eZScript::instance( array( 'description' => ( "Test WSDL" ),
    'use-session' => false,
    'use-modules' => true,
    'use-extensions' => true ) );

$script->startup();

$beforeDate = strtotime('-2 months');

$options = $script->getOptions(
    '[args:]',
    '',
    array(
        'args'  => '...'
    )
);
$script->initialize();
$script->setUseDebugAccumulators( true );

$user = eZUser::fetchByName( 'admin' );
eZUser::setCurrentlyLoggedInUser( $user , $user->attribute( 'contentobject_id' ) );
eZDB::setErrorHandling(eZDB::ERROR_HANDLING_EXCEPTIONS);

try
{
    $result = OpenPABolzanoImportJirideHandler::fetchData(
        mktime(0,0,0, 2, 6, 2020),
        mktime(0,0,0, 7, 16, 2020)
    );

    print_r($result); 
}
catch ( Exception $e )
{
    $cli->error($e->getMessage());
    $cli->output($e->getTraceAsString());
}

$script->shutdown();
