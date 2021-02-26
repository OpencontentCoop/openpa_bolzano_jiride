<?php
require 'autoload.php';

$script = eZScript::instance(array('description' => ("Rimuove utenti guest\n\n"),
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
eZDB::setErrorHandling(eZDB::ERROR_HANDLING_EXCEPTIONS);

$user = eZUser::fetchByName('admin');
eZUser::setCurrentlyLoggedInUser($user, $user->attribute('contentobject_id'));

// 12 Members
$node = eZContentObjectTreeNode::fetch(12);

$limitCount = 100;
$offset = 0;

$removeNodes = [];
while (1) {
    $children = $node->subTree(array(
        'Limitation' => array(),
        'SortBy' => array('path', false),
        'Offset' => $offset,
        'Limit' => $limitCount,
        'IgnoreVisibility' => true));

    /** @var eZContentObjectTreeNode $child */
    foreach ($children as $child) {
        $cli->output('Check user ' . $child->attribute('name') . ' ', false);
        $user = eZUser::fetch($child->attribute('contentobject_id'));
        if ($user instanceof eZUser){
            $roleNames = [];
            /** @var eZRole[] $roles */
            $roles = $user->roles();
            foreach ($roles as $role){
                $roleNames[] = $role->attribute('name');
            }
            sort($roleNames);
            $roleString = implode('#', $roleNames);
            if ($roleString !== 'Anonymous#Anonymous Opencity#Member#Members Opencity'){
                $cli->warning($roleString);
            }else{
                $cli->output('REMOVE');
                $removeNodes[] = $child->attribute('node_id');
            }
        }
    }

    if (count($children) === 0) {
        break;
    }

    $offset += $limitCount;
}

if (count($removeNodes) > 0) {
    $cli->output('Remove ' . count($removeNodes) . ' users');
    eZContentOperationCollection::removeNodes($removeNodes);
}

$script->shutdown();