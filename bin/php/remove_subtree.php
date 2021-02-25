#!/usr/bin/env php
<?php
/**
 * File containing the ezsubtreeremove.php script.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 * @version //autogentag//
 * @package kernel
 */

// Subtree Remove Script
// file  bin/php/ezsubtreeremove.php

// script initializing
require_once 'autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array( 'description' => ( "\n" .
                                                         "This script will make a remove of a content object subtrees.\n" ),
                                      'use-session' => false,
                                      'use-modules' => true,
                                      'use-extensions' => true ) );
$script->startup();

$scriptOptions = $script->getOptions( "[nodes-id:][ignore-trash]",
                                      "",
                                      array( 'nodes-id' => "Subtree nodes ID (separated by comma ',').",
                                             'ignore-trash' => "Ignore trash ('move to trash' by default)."
                                             ),
                                      false );
$script->initialize();
$srcNodesID  = $scriptOptions[ 'nodes-id' ] ? trim( $scriptOptions[ 'nodes-id' ] ) : false;
$moveToTrash = $scriptOptions[ 'ignore-trash' ] ? false : true;
$parentIDArray = $srcNodesID ? explode( ',', $srcNodesID ) : false;

class NullSearchPlugin extends eZSolr
{
    public function __call($name, $arguments)
    {
        return true;
    }

    public static function __callStatic($name, $arguments)
    {
        return true;
    }
}

$instanceName = "eZSearchPlugin_" . $scriptOptions['siteaccess'];
$GLOBALS[$instanceName] = new NullSearchPlugin();

eZDB::setErrorHandling(eZDB::ERROR_HANDLING_EXCEPTIONS);

$ini = eZINI::instance();
$ini->setVariable( 'SearchSettings', 'DelayedIndexing', 'enabled' );
// Get user's ID who can remove subtrees. (Admin by default with userID = 14)
$userCreatorID = $ini->variable( "UserSettings", "UserCreatorID" );
$user = eZUser::fetch( $userCreatorID );
if ( !$user )
{
    $cli->error( "Subtree remove Error!\nCannot get user object by userID = '$userCreatorID'.\n(See site.ini[UserSettings].UserCreatorID)" );
    $script->shutdown( 1 );
}
eZUser::setCurrentlyLoggedInUser( $user, $userCreatorID );

$deleteIDArray = [];
foreach ($parentIDArray as $parentID){
    $node = eZContentObjectTreeNode::fetch($parentID);
    if ($node instanceof eZContentObjectTreeNode){
        foreach ($node->children() as $child){
            $deleteIDArray[] = $child->attribute('node_id');
        }
    }
}

if ( !$deleteIDArray )
{
    $cli->error( "Subtree remove Error!\nCannot get subtree nodes. Please check nodes-id argument and try again." );
    $script->showHelp();
    $script->shutdown( 1 );
}

$deleteIDArrayResult = array();
foreach ( $deleteIDArray as $nodeID )
{
    $node = eZContentObjectTreeNode::fetch( $nodeID );
    if ( $node === null )
    {
        $cli->error( "\nSubtree remove Error!\nCannot find subtree with nodeID: '$nodeID'." );
        continue;
    }
    $deleteIDArrayResult[] = $nodeID;
}
// Get subtree removal information
$info = eZContentObjectTreeNode::subtreeRemovalInformation( $deleteIDArrayResult );

$deleteResult = $info['delete_list'];

if ( count( $deleteResult ) == 0 )
{
    $cli->output( "\nExit." );
    $script->shutdown( 1 );
}

$totalChildCount = $info['total_child_count'];
$canRemoveAll = $info['can_remove_all'];
$moveToTrashStr = $moveToTrash ? 'true' : 'false';
$reverseRelatedCount = $info['reverse_related_count'];

$cli->output( "\nTotal child count: $totalChildCount" );
$cli->output( "Move to trash: $moveToTrashStr" );
$cli->output( "Reverse related count: $reverseRelatedCount\n" );

$cli->output( "Removing subtrees:\n" );

function removeSubtrees( $deleteIDArray, $moveToTrash = true, $infoOnly = false )
{
    $moveToTrashAllowed = true;
    $deleteResult = array();
    $totalChildCount = 0;
    $totalLoneNodeCount = 0;
    $canRemoveAll = true;
    $hasPendingObject = false;

    $db = eZDB::instance();
    $db->begin();

    foreach ( $deleteIDArray as $deleteID )
    {
        $node = eZContentObjectTreeNode::fetch( $deleteID );
        if ( $node === null )
            continue;

        $object = $node->attribute( 'object' );
        if ( $object === null )
            continue;

        $class = $object->attribute( 'content_class' );
        $canRemove = $node->attribute( 'can_remove' );
        $canRemoveSubtree = true;

        $nodeID = $node->attribute( 'node_id' );
        $nodeName = $object->attribute( 'name' );

        $childCount = 0;
        $newMainNodeID = false;
        $objectNodeCount = 0;
        $readableChildCount = 0;

        if ( $canRemove )
        {
            $moveToTrashAllowed = $node->isNodeTrashAllowed();

            $readableChildCount = $node->subTreeCount( array( 'Limitation' => array() ) );
            $childCount = $node->subTreeCount( array( 'IgnoreVisibility' => true ) );
            $totalChildCount += $childCount;

            $allAssignedNodes = $object->attribute( 'assigned_nodes' );
            $objectNodeCount = count( $allAssignedNodes );
            // We need to find a new main node ID if we are trying
            // to remove the current main node.
            if ( $node->attribute( 'main_node_id' ) == $nodeID )
            {
                if ( count( $allAssignedNodes ) > 1 )
                {
                    foreach( $allAssignedNodes as $assignedNode )
                    {
                        $assignedNodeID = $assignedNode->attribute( 'node_id' );
                        if ( $assignedNodeID == $nodeID )
                            continue;
                        $newMainNodeID = $assignedNodeID;
                        break;
                    }
                }
            }

            if ( $infoOnly )
            {
                // Find the number of items in the subtree we are allowed to remove
                // if this differs from the total count it means we have items we cannot remove
                // We do this by fetching the limitation list for content/remove
                // and passing it to the subtree count function.
                $currentUser = eZUser::currentUser();
                $accessResult = $currentUser->hasAccessTo( 'content', 'remove' );
                if ( $accessResult['accessWord'] == 'limited' )
                {
                    $limitationList = $accessResult['policies'];
                    $removeableChildCount = $node->subTreeCount( array( 'Limitation' => $limitationList, 'IgnoreVisibility' => true ) );
                    $canRemoveSubtree = ( $removeableChildCount == $childCount );
                    $canRemove = $canRemoveSubtree;
                }
                //check if there is sub object in pending status
                $limitCount = 100;
                $offset = 0;
                while( 1 )
                {
                    $children = $node->subTree( array( 'Limitation' => array(),
                        'SortBy' => array( 'path' , false ),
                        'Offset' => $offset,
                        'Limit' => $limitCount,
                        'IgnoreVisibility' => true,
                        'AsObject' => false ) );
                    // fetch pending node assignment(pending object)
                    $idList = array();
                    //add node itself into idList
                    if( $offset === 0 )
                    {
                        $idList[] = $nodeID;
                    }
                    foreach( $children as $child )
                    {
                        $idList[] = $child['node_id'];
                    }

                    if( count( $idList ) === 0 )
                    {
                        break;
                    }
                    $pendingChildCount = eZNodeAssignment::fetchChildCountByVersionStatus( $idList,
                        eZContentObjectVersion::STATUS_PENDING );
                    if( $pendingChildCount !== 0 )
                    {
                        // there is pending object
                        $hasPendingObject = true;
                        break;
                    }
                    $offset += $limitCount;
                }
            }

            // We will only remove the subtree if are allowed
            // and are told to do so.
            if ( $canRemove and !$infoOnly )
            {
                $moveToTrashTemp = $moveToTrash;
                if ( !$moveToTrashAllowed )
                    $moveToTrashTemp = false;

                // Remove children, fetching them by 100 to avoid memory overflow.
                // removeNodeFromTree -> removeThis handles cache clearing
                while ( 1 )
                {
                    // We should remove the latest subitems first,
                    // so we should fetch subitems sorted by 'path_string' DESC
                    $children = $node->subTree( array( 'Limitation' => array(),
                        'SortBy' => array( 'path' , false ),
                        'Limit' => 100,
                        'IgnoreVisibility' => true ) );
                    if ( !$children )
                        break;

                    foreach ( $children as $child )
                    {
                        try {
                            $child->removeNodeFromTree($moveToTrashTemp);
                        }catch (eZDBException $e){
                            eZCLI::instance()->error('#' . $child->attribute('contentobject_id') . ' ' . $e->getMessage());
                            eZDB::instance()->rollback();
                        }
                        eZContentObject::clearCache();
                    }
                }
                try {
                    $node->removeNodeFromTree($moveToTrashTemp);
                }catch (eZDBException $e){
                    eZCLI::instance()->error('#' . $node->attribute('contentobject_id') . ' ' . $e->getMessage());
                    eZDB::instance()->rollback();
                }
            }
        }
        if ( !$canRemove )
            $canRemoveAll = false;

        // Do not create info list if we are removing subtrees
        if ( !$infoOnly )
            continue;

        $soleNodeCount = $node->subtreeSoleNodeCount();
        $totalLoneNodeCount += $soleNodeCount;
        if ( $objectNodeCount <= 1 )
            ++$totalLoneNodeCount;

        $item = array( "nodeName" => $nodeName, // Backwards compatibility
            "childCount" => $childCount, // Backwards compatibility
            "additionalWarning" => '', // Backwards compatibility, this will always be empty
            'node' => $node,
            'object' => $object,
            'class' => $class,
            'node_name' => $nodeName,
            'child_count' => $childCount,
            'object_node_count' => $objectNodeCount,
            'sole_node_count' => $soleNodeCount,
            'can_remove' => $canRemove,
            'can_remove_subtree' => $canRemoveSubtree,
            'real_child_count' => $readableChildCount,
            'new_main_node_id' => $newMainNodeID );
        $deleteResult[] = $item;
    }

    $db->commit();

    if ( !$infoOnly )
        return true;

    if ( $moveToTrashAllowed and $totalLoneNodeCount == 0 )
        $moveToTrashAllowed = false;

    return array( 'move_to_trash' => $moveToTrashAllowed,
        'total_child_count' => $totalChildCount,
        'can_remove_all' => $canRemoveAll,
        'delete_list' => $deleteResult,
        'has_pending_object' => $hasPendingObject,
        'reverse_related_count' => eZContentObjectTreeNode::reverseRelatedCount( $deleteIDArray ) );
}


foreach ( $deleteResult as $deleteItem )
{
    $node = $deleteItem['node'];
    $nodeName = $deleteItem['node_name'];
    if ( $node === null )
    {
        $cli->error( "\nSubtree remove Error!\nCannot find subtree '$nodeName'." );
        continue;
    }
    $nodeID = $node->attribute( 'node_id' );
    $childCount = $deleteItem['child_count'];
    $objectNodeCount = $deleteItem['object_node_count'];

    $cli->output( "Node id: $nodeID" );
    $cli->output( "Node name: $nodeName" );

    $canRemove = $deleteItem['can_remove'];
    if ( !$canRemove )
    {
        $cli->error( "\nSubtree remove Error!\nInsufficient permissions. You do not have permissions to remove the subtree with nodeID: $nodeID\n" );
        continue;
    }
    $cli->output( "Child count: $childCount" );
    $cli->output( "Object node count: $objectNodeCount" );

    // Remove subtrees
    removeSubtrees( array( $nodeID ), $moveToTrash );

    // We should make sure that all subitems have been removed.
    $itemInfo = eZContentObjectTreeNode::subtreeRemovalInformation( array( $nodeID ) );
    $itemTotalChildCount = $itemInfo['total_child_count'];
    $itemDeleteList = $itemInfo['delete_list'];

    if ( count( $itemDeleteList ) != 0 or ( $childCount != 0 and $itemTotalChildCount != 0 ) )
        $cli->error( "\nWARNING!\nSome subitems have not been removed.\n" );
    else
        $cli->output( "Successfuly DONE.\n" );
}

$cli->output( "Done." );
$script->shutdown();

?>
