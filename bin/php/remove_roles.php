<?php
require 'autoload.php';

$script = eZScript::instance(array('description' => ("Rimuove ruoli\n\n"),
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

$roles = [
    "Administrator" => 'keep',
    "Agenda Anonymous" => 'agenda',
    "Agenda Associations" => 'agenda',
    "Agenda Member" => 'agenda',
    "Agenda Moderators" => 'agenda',
    "Amministratore trasparenza" => 'sito',
    "Anonymous" => 'keep',
    "Anonymous Opencity" => 'sito',
    "Anonymous Sito (temp)" => 'sito',
    "Crea documenti in Applicazioni" => 'sito',
    "Dimmi Admin" => 'dimmi',
    "Dimmi Anonymous" => 'dimmi',
    "Dimmi Participant" => 'dimmi',
    "Editor" => 'sito',
    "Editor Amministrazione" => 'sito',
    "Editor Base" => 'sito',
    "Editor Classificazioni" => 'sito',
    "Editor Documenti" => 'sito',
    "Editor Novita" => 'sito',
    "Editor Servizi" => 'sito',
    "Editor Utenti" => 'sito',
    "Editor backend (import csv)" => 'sito',
    "Gestione Area riservata" => 'sito',
    "Member" => 'keep',
    "Members Opencity" => 'sito',
    "Newsletter anonimo" => 'sito',
    "Newsletter editor" => 'sito',
    "Sensor Admin" => 'sensor',
    "Sensor Anonymous" => 'sensor',
    "Sensor Assistant" => 'sensor',
    "Sensor Operators" => 'sensor',
    "Sensor Reporter" => 'sensor',
];

$context = $options['context'];
if (empty($context)){
    $cli->error('context???');
}else {
    foreach ($roles as $roleName => $contextId) {
        $cli->output($roleName . ' ', false);
        if ($contextId == $context || $contextId == 'keep') {
            $cli->output('KEEP');
        } else {
            $role = eZRole::fetchByName($roleName);
            if ($role instanceof eZRole) {
                $cli->warning('REMOVE');
                $role->removeThis();
            }else{
                $cli->warning('NOT FOUND');
            }
        }
    }
}

$script->shutdown();