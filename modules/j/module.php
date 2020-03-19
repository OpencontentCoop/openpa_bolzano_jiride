<?php
$Module = array( 'name' => 'Bolzano Jiride' );

$ViewList = array();
$ViewList['download'] = array(
	'script' =>	'download.php',
    'params' => array( "IdDocumento", "Serial", "FileName" ),
	'functions' => array( 'download' )
);

$FunctionList = array();
$FunctionList['download'] = array();

