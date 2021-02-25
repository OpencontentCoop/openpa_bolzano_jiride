<?php
require 'autoload.php';

$script = eZScript::instance(array('description' => ("Remove orphans\n\n"),
    'use-session' => false,
    'use-modules' => true,
    'use-extensions' => true));

$script->startup();

$options = $script->getOptions(
    '[context:]',
    '',
    array('context' => 'sensor, agenda, dimmi, sito')
);
$script->initialize();
$script->setUseDebugAccumulators(true);
$cli = eZCLI::instance();

$user = eZUser::fetchByName('admin');
eZUser::setCurrentlyLoggedInUser($user, $user->attribute('contentobject_id'));

$cli->warning(eZDB::instance()->DB);

$count = eZDB::instance()->arrayQuery("SELECT count(*) FROM ezbinaryfile WHERE contentobject_attribute_id not in (select distinct id from ezcontentobject_attribute);");
$cli->warning("Deleting " . $count[0]['count'] . ' rows from ezbinaryfile... ', false);
$count = eZDB::instance()->arrayQuery("DELETE FROM ezbinaryfile WHERE contentobject_attribute_id not in (select distinct id from ezcontentobject_attribute);");
$cli->warning('done!');

$count = eZDB::instance()->arrayQuery("SELECT count(*) FROM ezimagefile WHERE contentobject_attribute_id not in (select distinct id from ezcontentobject_attribute);");
$cli->warning("Deleting " . $count[0]['count'] . ' rows from ezimagefile... ', false);
$count = eZDB::instance()->arrayQuery("DELETE FROM ezimagefile WHERE contentobject_attribute_id not in (select distinct id from ezcontentobject_attribute);");
$cli->warning('done!');

$script->shutdown();