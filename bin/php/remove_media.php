<?php
require 'autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance(array(
    'description' => ( "\nRimozione di oggetti di classe image e file dalle cartelle media/images e media/files che hanno una sola collocazione e non hanno alcuna relazione diretta o inversa \n\n" . 
                       "Esempi di utilizzo:\n\n" . 
                       "  -> per contare gli oggetti ruomivibili\n" . 
                       "  php extension/oc_tcu/bin/php/remove_media.php \n\n" . 
                       "  -> per rimuovere\n" . 
                       "  php extension/oc_tcu/bin/php/remove_media.php --remove\n\n"
    ),
    'use-session' => false,
    'use-modules' => true,
    'use-extensions' => true
));

$script->startup();

$options = $script->getOptions('[remove]',
    '',
    array(
        'remove' => "Rimuove gli oggetti"
    )
);
$script->initialize();
$script->setUseDebugAccumulators(true);

$user = eZUser::fetchByName('admin');
eZUser::setCurrentlyLoggedInUser($user, $user->attribute('contentobject_id'));

$action = false;
if($options['remove']){
    $action = 'remove';
}

$now = time();

function remove($item)
{
    global $action;

    if (!$action){
        return false;
    }

    if ($action == 'remove'){
        eZContentOperationCollection::deleteObject(array($item->attribute('node_id')));
    }
}

function isRemovable($item)
{
    $object = $item->object();
    
    $assignedNodes = $object->assignedNodes();
    if (count($assignedNodes) > 1){
        return false;    
    }
    
    $relatedObjectsCount = eZFunctionHandler::execute('content', 'related_objects_count', array( 
        'object_id' => $object->attribute('id'), 
        'all_relations' => true 
    ));
    if ($relatedObjectsCount > 0){
        return false;    
    }
    
    $reverseRelatedObjectsCount = eZFunctionHandler::execute('content', 'reverse_related_objects_count', array( 
        'object_id' => $object->attribute('id'), 
        'all_relations' => true 
    ));
    if ($reverseRelatedObjectsCount > 0){
        return false;    
    }

    return true;
}

function run($rootNode, $fetchParams, $count)
{
    $output = new ezcConsoleOutput();
    $errors = array();
    $removed = array();
    $done = 0;
    $length = 50;
    $fetchParams = array_merge_recursive($fetchParams,
        array('Offset' => 0, 'Limit' => $length)
    );

    $progressBar = new ezcConsoleProgressbar($output, intval($count), array(
        'emptyChar' => ' ',
        'barChar' => '='
    ));
    $progressBar->start();

    while(true)
    {            
        $items = $rootNode->subTree($fetchParams);
        if (count($items) == 0 || ($done >= $count)){
            break 1;
        }
        foreach ($items as $item) {
            $done++;                
            $progressBar->advance();
            try{
                if (isRemovable($item)){                
                    remove($item);
                    $removed[] = $item->attribute('contentobject_id');
                }                
            } catch (Exception $e) {
                $errors[$item->attribute('contentobject_id')] = $e->getMessage();
            }
        }
        eZContentObject::clearCache();            
    }
    $progressBar->finish();

    return array(
        'removed' => $removed,
        'errors' => $errors
    );
}

try {
    
    $logVerb = 'Rimuovibili';
    if ($action == 'remove'){
        $logVerb = 'Rimossi';
    }

    $imagesNode = eZContentObjectTreeNode::fetchByURLPath('media/images');
    if ($imagesNode instanceof eZContentObjectTreeNode){
        $fetchParams = array(
            'ClassFilterType' => 'include',
            'ClassFilterArray' => array('image')
        );
        $imagesCount = $imagesNode->subTreeCount($fetchParams);
        $cli->warning("Esamino $imagesCount immagini");
        
        $result = run($imagesNode, $fetchParams, $imagesCount);
        
        $cli->output('');
        $cli->warning("$logVerb " . count($result['removed']) . " oggetti di tipo image");
        foreach ($result['errors'] as $id => $error) {
            $cli->error("Errore elaborando l'oggetto #$id: $error");
        }
        $cli->output('');        

    }else{
        $cli->error("Nodo media/images non trovato");
    }
    
    $filesNode = eZContentObjectTreeNode::fetchByURLPath('media/files');
    if ($filesNode instanceof eZContentObjectTreeNode){
        $fetchParams = array(
            'ClassFilterType' => 'include',
            'ClassFilterArray' => array('file')
        );
        $filesCount = $imagesNode->subTreeCount($fetchParams);
        $cli->warning("Esamino $filesCount file");

        $result = run($filesNode, $fetchParams, $filesCount);
        
        $cli->output('');
        $cli->warning("$logVerb " . count($result['removed']) . " oggetti di tipo file");
        foreach ($result['errors'] as $id => $error) {
            $cli->error("Errore elaborando l'oggetto #$id: $error");
        }
        $cli->output(''); 

    }else{
        $cli->error("Nodo media/files non trovato");
    }

    $memoryMax = memory_get_peak_usage(); // Result is in bytes
    $memoryMax = round($memoryMax / 1024 / 1024, 2); // Convert in Megabytes
    $cli->output('Peak memory usage : ' . $memoryMax . 'M');

    $script->shutdown();

} catch (eZDBException $e) {
    $errCode = $e->getCode();
    $errCode = $errCode != 0 ? $errCode : 1; // If an error has occured, script must terminate with a status other than 0
    $script->shutdown($errCode, $e->getMessage());

} catch (Exception $e) {
    $errCode = $e->getCode();
    $errCode = $errCode != 0 ? $errCode : 1; // If an error has occured, script must terminate with a status other than 0
    $script->shutdown($errCode, $e->getMessage());
}