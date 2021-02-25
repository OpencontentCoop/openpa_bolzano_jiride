<?php
require 'autoload.php';

$script = eZScript::instance(array('description' => ("Rimozione gruppi di classi vuoti\n\n"),
    'use-session' => false,
    'use-modules' => true,
    'use-extensions' => true));

$script->startup();

$options = $script->getOptions('[dry-run]',
    '',
    array('dry-run' => 'Drai ran')
);
$script->initialize();
$script->setUseDebugAccumulators(true);

try {
    $user = eZUser::fetchByName('admin');
    eZUser::setCurrentlyLoggedInUser($user, $user->attribute('contentobject_id'));

    /** @var eZContentClassGroup[] $classGroups */
    $classGroups = eZContentClassGroup::fetchList();
    foreach ($classGroups as $classGroup) {
        $classes = eZContentClassClassGroup::fetchClassList(null, $classGroup->attribute('id'));
        if (count($classes) == 0) {
            eZCLI::instance()->warning("Rimuovo " . $classGroup->attribute('name'));
            if (!$options['dry-run']){
                $classGroup->remove();
            }
        }
    }

    $script->shutdown();
} catch (Exception $e) {
    $errCode = $e->getCode();
    $errCode = $errCode != 0 ? $errCode : 1; // If an error has occured, script must terminate with a status other than 0
    $script->shutdown($errCode, $e->getMessage());
}
