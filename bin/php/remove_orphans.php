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

//$count = eZDB::instance()->arrayQuery("SELECT count(*) FROM ezbinaryfile WHERE contentobject_attribute_id not in (select distinct id from ezcontentobject_attribute);");
//$cli->warning("Deleting " . $count[0]['count'] . ' rows from ezbinaryfile... ', false);
//$count = eZDB::instance()->arrayQuery("DELETE FROM ezbinaryfile WHERE contentobject_attribute_id not in (select distinct id from ezcontentobject_attribute);");
//$cli->warning('done!');
//
//$count = eZDB::instance()->arrayQuery("SELECT count(*) FROM ezimagefile WHERE contentobject_attribute_id not in (select distinct id from ezcontentobject_attribute);");
//$cli->warning("Deleting " . $count[0]['count'] . ' rows from ezimagefile... ', false);
//$count = eZDB::instance()->arrayQuery("DELETE FROM ezimagefile WHERE contentobject_attribute_id not in (select distinct id from ezcontentobject_attribute);");
//$cli->warning('done!');
//
//$count = eZDB::instance()->arrayQuery("SELECT count(*) FROM eznode_assignment WHERE parent_node not in (select distinct node_id from ezcontentobject_tree);");
//$cli->warning("Deleting " . $count[0]['count'] . ' rows from eznode_assignment... ', false);
//$count = eZDB::instance()->arrayQuery("DELETE FROM eznode_assignment WHERE parent_node not in (select distinct node_id from ezcontentobject_tree);");
//$cli->warning('done!');

$missingMains = eZDB::instance()->arrayQuery("SELECT id FROM ezcontentobject WHERE id not in (select contentobject_id from eznode_assignment where is_main = 1);");
foreach ($missingMains as $missingMain){
    $id = $missingMain['id'];
    $parent = eZDB::instance()->arrayQuery("SELECT parent_node FROM eznode_assignment WHERE contentobject_id = " . (int) $id . " limit 1");
    if (isset($parent[0]['parent_node'])){
        $parentNodeId = (int)$parent[0]['parent_node'];
        eZDB::instance()->arrayQuery("UPDATE eznode_assignment SET is_main = 1 WHERE contentobject_id = " . (int) $id . " AND parent_node = " . $parentNodeId);
    }
}

$missingParentNodes = eZDB::instance()->arrayQuery("SELECT * FROM ezcontentobject_tree WHERE main_node_id not in (select node_id from ezcontentobject_tree);");

$script->shutdown();