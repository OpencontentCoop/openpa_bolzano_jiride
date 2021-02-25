<?php
require 'autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance(array('description' => ("OpenContent Change VarDir name"),
    'use-session' => false,
    'use-modules' => true,
    'use-extensions' => true));

$script->startup();

$options = $script->getOptions('[old:][new:]',
    '',
    array(
        'old' => "Nome VarDir da cambiare (esempio:'var/ezdemo_site')",
        'new' => "Nuovo nome VarDir (esempio:'var/pincopallino')"
    )
);
$script->initialize();
$script->setUseDebugAccumulators(true);

$user = eZUser::fetchByName('admin');
eZUser::setCurrentlyLoggedInUser($user, $user->attribute('contentobject_id'));

try {
    $oldName = $options['old'];
    $newName = $options['new'];
    if (empty($oldName) || empty($newName)) {
        throw new Exception("Missing options, run with -h for help");
    }
    $list = eZPersistentObject::fetchObjectList(eZImageFile::definition());
    foreach ($list as $item) {
        $filePath = $item->attribute('filepath');
        if (strpos($filePath, $oldName) !== false) {
            $newFilePath = str_replace($oldName, $newName, $filePath);
            $cli->warning("Change filepath:");
            $cli->output($filePath);
            $cli->warning("in");
            $cli->output($newFilePath);

            $item->setAttribute('filepath', $newFilePath);
            $item->store();

            $contentAttributes = eZPersistentObject::fetchObjectList(eZContentObjectAttribute::definition(), null, array("id" => $item->attribute('contentobject_attribute_id')));
            foreach ($contentAttributes as $attribute) {
                $dataText = $attribute->attribute('data_text');
                $newDataText = str_replace($oldName, $newName, $dataText);
                $cli->warning("Change data_text:");
                $cli->output($dataText);
                $cli->warning("in");
                $cli->output($newDataText);

                $attribute->setAttribute('data_text', $newDataText);
                $attribute->store();

            }
        }
    }
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
