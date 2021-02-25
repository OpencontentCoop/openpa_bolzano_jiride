<?php
require 'autoload.php';

$script = eZScript::instance(array('description' => ("Rimuove classi non instanziate\n\n"),
    'use-session' => false,
    'use-modules' => true,
    'use-extensions' => true));

$script->startup();

$options = $script->getOptions();
$script->initialize();
$script->setUseDebugAccumulators(true);
$cli = eZCLI::instance();

$user = eZUser::fetchByName('admin');
eZUser::setCurrentlyLoggedInUser($user, $user->attribute('contentobject_id'));

$db = eZDB::instance();
$rows = $db->arrayQuery("SELECT DISTINCT cc.id, cc.identifier " .
    "FROM ezcontentclass cc " .
    "WHERE cc.version = " . eZContentClass::VERSION_STATUS_DEFINED .
    "ORDER BY cc.identifier ASC");
$classes = eZPersistentObject::handleRows($rows, 'eZContentClass', false);

$excludeList = ['image', 'file'];

if ($options['siteaccess'] == 'bolzano_backend'){
    $excludeList = array_merge($excludeList, [
        "administrative_area",
        "apps_container",
        "article",
        "audio",
        "banner",
        "channel",
        "chart",
        "dataset",
        "document",
        "employee",
        //"event",
        "file",
        "folder",
        "frontpage",
        "gallery",
        "homepage",
        "homogeneous_organizational_area",
        "image",
        "link",
        "offer",
        "office",
        "office_with_related",
        "online_contact_point",
        "opening_hours_specification",
        "output",
        "pagina_sito",
        "partecipazione_societaria",
        "person",
        "place",
        "political_body",
        "politico",
        "politico_with_related",
        "private_organization",
        "public_organization",
        "public_service",
        "public_service_with_related",
        "restriced_area",
        "rule",
        "time_indexed_role",
        "topic",
        "user",
        "user_group",
        "valuation",
        "cjw_newsletter_article",
        "cjw_newsletter_edition",
        "cjw_newsletter_list",
        "cjw_newsletter_root",
        "cjw_newsletter_system",
        "nota_trasparenza",
        "pagina_trasparenza",
        "trasparenza",
    ]);
}

foreach ($classes as $classArray) {
    $class = eZContentClass::fetch($classArray['id']);
    $objectCount = (int)$class->objectCount();
    $cli->output($class->attribute('identifier') . ' ' . $objectCount . ' -> ', false);
    if ($objectCount === 0 && !in_array($class->attribute('identifier'), $excludeList)) {
        $cli->warning('REMOVE');
        eZContentClassOperations::remove($classArray['id']);
        ezpEvent::getInstance()->notify('content/class/cache', array($classArray['id']));
    } else {
        $cli->output('OK');
    }
}

$script->shutdown();