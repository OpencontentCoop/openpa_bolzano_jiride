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

$db = eZDB::instance();

$class = eZContentClass::fetchByIdentifier('sensor_operator');
/** @var eZContentObjectAttribute[] $userClassDataMap */
$classDataMap = $class->dataMap();

$userClass = eZContentClass::fetchByIdentifier('user');
/** @var eZContentObjectAttribute[] $userClassDataMap */
$userClassDataMap = $userClass->dataMap();

function addMissingAttributes(eZContentClass $class, eZContentObject $object)
{
    global $db;

    $classAttributes = $class->fetchAttributes();
    $classAttributeIDs = array();
    foreach ($classAttributes as $classAttribute) {
        $classAttributeIDs[] = $classAttribute->attribute('id');
    }

    $contentObjectID = $object->attribute('id');
    $objectVersions = $object->versions();
    foreach ($objectVersions as $objectVersion) {
        $versionID = $objectVersion->attribute('version');
        $translations = $objectVersion->translations();
        foreach ($translations as $translation) {
            $translationName = $translation->attribute('language_code');

            // Class attribute IDs of object attributes (not necessarily the same as those in the class, hence the manual sql)
            $objectClassAttributeIDs = array();
            $rows = $db->arrayQuery("SELECT id,contentclassattribute_id, data_type_string
                                              FROM ezcontentobject_attribute
                                              WHERE contentobject_id = '$contentObjectID' AND
                                                    version = '$versionID' AND
                                                    language_code='$translationName'");
            foreach ($rows as $row) {
                $objectClassAttributeIDs[$row['id']] = $row['contentclassattribute_id'];
            }

            // Quick array diffs
            $attributesToRemove = array_diff($objectClassAttributeIDs, $classAttributeIDs); // Present in the object, not in the class
            $attributesToAdd = array_diff($classAttributeIDs, $objectClassAttributeIDs); // Present in the class, not in the object

            // Remove old attributes
            foreach ($attributesToRemove as $objectAttributeID => $classAttributeID) {
                $objectAttribute = eZContentObjectAttribute::fetch($objectAttributeID, $versionID);
                if (!is_object($objectAttribute))
                    continue;
                $objectAttribute->remove($objectAttributeID);
            }

            // Add new attributes
            foreach ($attributesToAdd as $classAttributeID) {
                $objectAttribute = eZContentObjectAttribute::create($classAttributeID, $contentObjectID, $versionID, $translationName);
                if (!is_object($objectAttribute))
                    continue;
                $objectAttribute->setAttribute('language_code', $translationName);
                $objectAttribute->initialize();
                $objectAttribute->store();
                $objectAttribute->postInitialize();
            }
        }
    }
}

function migrateToUser(eZContentObject $object)
{
    global $db, $userClass, $classDataMap, $userClassDataMap;

    $objectId = (int)$object->attribute('id');

    if ($userClass instanceof eZContentClass) {
        $userClassId = (int)$userClass->attribute('id');
        $first_name = (int)$userClassDataMap['first_name']->attribute('id');
        $user_account = (int)$userClassDataMap['user_account']->attribute('id');

        $name = (int)$classDataMap['name']->attribute('id');
        $account = (int)$classDataMap['account']->attribute('id');
        $e_mail = (int)$classDataMap['e_mail']->attribute('id');
        $competenza = (int)$classDataMap['competenza']->attribute('id');
        $struttura_di_competenza = (int)$classDataMap['struttura_di_competenza']->attribute('id');
        $ruolo = (int)$classDataMap['ruolo']->attribute('id');
        $competenze = (int)$classDataMap['competenze']->attribute('id');

        $db->begin();

        $sql = [];
        $sql[] = "UPDATE ezcontentobject SET contentclass_id = $userClassId WHERE id = $objectId;";
        $sql[] = "UPDATE ezcontentobject_attribute SET contentclassattribute_id = $first_name WHERE contentclassattribute_id = $name AND contentobject_id = $objectId;";
        $sql[] = "UPDATE ezcontentobject_attribute SET contentclassattribute_id = $user_account WHERE contentclassattribute_id = $account AND contentobject_id = $objectId;";
        $sql[] = "DELETE FROM ezcontentobject_attribute WHERE contentclassattribute_id = $e_mail AND contentobject_id = $objectId AND contentobject_id = $objectId;";
        $sql[] = "DELETE FROM ezcontentobject_attribute WHERE contentclassattribute_id = $competenza AND contentobject_id = $objectId AND contentobject_id = $objectId;";
        $sql[] = "DELETE FROM ezcontentobject_attribute WHERE contentclassattribute_id = $struttura_di_competenza AND contentobject_id = $objectId AND contentobject_id = $objectId;";
        $sql[] = "DELETE FROM ezcontentobject_attribute WHERE contentclassattribute_id = $ruolo AND contentobject_id = $objectId AND contentobject_id = $objectId;";
        $sql[] = "DELETE FROM ezcontentobject_attribute WHERE contentclassattribute_id = $competenze AND contentobject_id = $objectId AND contentobject_id = $objectId;";

        foreach ($sql as $item){
            $db->arrayQuery($item);
        }
        addMissingAttributes($userClass, $object);

        $db->commit();
    }
}

if ($class instanceof eZContentClass) {
    /** @var eZContentObject $object */
    foreach ($class->objectList() as $object) {
        $cli->output('#' . $object->attribute('id') . ' ' . $object->attribute('name'));

        $versionCount = $object->getVersionCount();
        $versionLimit = 1;
        $versionsToRemove = $versionCount - $versionLimit;
        $removedVersions = 0;
        $batchVersionsToRemove = 20;
        while ($removedVersions < $versionsToRemove) {
            $versions = $object->versions(true, array(
                'conditions' => array('status' => eZContentObjectVersion::STATUS_ARCHIVED),
                'sort' => array('modified' => 'asc'),
                'limit' => array('limit' => $batchVersionsToRemove, 'offset' => $removedVersions),
            ));

            $db->begin();
            foreach ($versions as $version) {
                $version->removeThis();
                $removedVersions++;
            }
            $db->commit();
        }

        migrateToUser($object);
    }
}

$script->shutdown();