<?php

class OpenPABolzanoImportJirideHandler extends SQLIImportAbstractHandler implements ISQLIImportHandler
{
    public static $remotePrefix = 'jiride-';
    private static $OFFICE_PARENT_NODE = 15889;
    private static $DOCUMENT_PARENT_NODE = 16718;
    private static $DEFAULT_TOPIC_OBJECT = 23200;
    private static $enableDebug = false;
    private static $data = array();
    protected $rowIndex = 0;
    protected $rowCount;
    protected $currentGUID;
    /**
     * @var OpenPASectionTools
     */
    private $sectionTools;

    public static function fetchAllegato($serial)
    {
        $client = self::getSoapClient();

        $response = $client->DownloadAllegatoString(array(
            "TipoDownload" => 'BIN',
            "Serial" => $serial,
            "CodiceAmministrazione" => "amt",
            "CodiceAOO" => "",
        ));

        $xmlParser = new SQLIXMLParser(new SQLIXMLOptions(array(
            'xml_string' => $response->DownloadAllegatoStringResult,
            'xml_parser' => 'simplexml'
        )));

        return $xmlParser->parse();
    }

    /**
     * (non-PHPdoc)
     * @see extension/sqliimport/classes/sourcehandlers/ISQLIImportHandler::initialize()
     */
    public function initialize()
    {
        $this->sectionTools = new OpenPASectionTools();

        if (isset($this->options['from'])) {
            $fromTimestamp = $this->options['from'];
        } else {
            $fromTimestamp = null;
            $lastImportItem = eZDB::instance()->arrayQuery("SELECT * FROM sqliimport_item WHERE handler = 'importjirideimporthandler' ORDER BY requested_time desc LIMIT 1");
            if (count($lastImportItem) > 0) {
                $fromTimestamp = $lastImportItem[0]['requested_time'];
                $fromTimestamp = $fromTimestamp - 86400;
            }
        }

        if (isset($this->options['to'])) {
            $toTimestamp = $this->options['to'];
        } else {
            $toTimestamp = time();
        }

        try {
            $this->dataSource = self::fetchData($fromTimestamp, $toTimestamp);
        } catch (Exception $e) {
            $this->cli->error($e->getMessage());
            $this->cli->output($e->getTraceAsString());
            $this->dataSource = [];
        }
    }

    public static function fetchData($fromTimestamp, $toTimestamp, $page = 1)
    {
        if ($page === 1) {
            self::$data = array();
        }

        if (empty($fromTimestamp)) {
            $from = '01/01/2020';
        } else {
            $from = strpos($fromTimestamp, '/') === false ? date('d/m/Y', $fromTimestamp) : $fromTimestamp;
        }

        $to = strpos($toTimestamp, '/') === false ? date('d/m/Y', $toTimestamp) : $toTimestamp;

        $client = self::getSoapClient();

        $bachecaFiltri = [
            'TipoBacheca' => 'P',
            'DallaDataUpd' => $from,
            'AllaDataUpd' => $to,
            'Paginazione' => [
                'IndicePagina' => $page,
                'DimensionePagina' => 30
            ],
            'Ordinamento' => [
                'CodiceOrdinamento' => 'DT_UPD',
                'TipoOrdinamento' => 'ASC'
            ]
        ];

        $bachecaFiltriXML = new SimpleXMLElement('<BachecaFiltri/>');
        self::array_to_xml($bachecaFiltri, $bachecaFiltriXML);
        $dom = dom_import_simplexml($bachecaFiltriXML);
        $bachecaFiltriXMLString = $dom->ownerDocument->saveXML($dom->ownerDocument->documentElement);

        if (self::$enableDebug) {
            eZCLI::instance()->warning($bachecaFiltriXMLString);
        }

        $response = $client->ListaTrasparenza2String(array(
            "BachecaFiltriStr" => $bachecaFiltriXMLString,
            "CodiceAmministrazione" => "amt",
            "CodiceAOO" => "",
        ));

        $xmlParser = new SQLIXMLParser(new SQLIXMLOptions(array(
            'xml_string' => $response->ListaTrasparenza2StringResult,
            'xml_parser' => 'simplexml'
        )));
        $data = $xmlParser->parse();

        foreach ($data->Documenti->Documento as $doc) {
            self::$data[] = $doc;
        }

        $totaleDocumenti = (int)$data->Documenti['totaleDocumenti'];
        $dimensionePagina = (int)$data->Documenti['DimensionePagina'];
        $indicePagina = (int)$data->Documenti['IndicePagina'];

        if (count(self::$data) < $totaleDocumenti) {
            $page++;
            self::fetchData($fromTimestamp, $toTimestamp, $page);
        }

        return self::$data;
    }

    private static function getSoapClient()
    {
        $wsdlUrl = 'extension/openpa_bolzano_jiride/WSBachecaSoap.wsdl';

        return new SoapClient($wsdlUrl);
    }

    private static function array_to_xml($data, SimpleXMLElement &$xml_data)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (is_numeric($key)) {
                    $key = 'item' . $key; //dealing with <0/>..<n/> issues
                }
                $subNode = $xml_data->addChild($key);
                self::array_to_xml($value, $subNode);
            } else {
                $xml_data->addChild("$key", htmlspecialchars("$value"));
            }
        }
    }

    /**
     * (non-PHPdoc)
     * @see extension/sqliimport/classes/sourcehandlers/ISQLIImportHandler::getProcessLength()
     */
    public function getProcessLength()
    {
        if (!isset($this->rowCount)) {
            $this->rowCount = count($this->dataSource);
        }
        return $this->rowCount;
    }

    /**
     * (non-PHPdoc)
     * @see extension/sqliimport/classes/sourcehandlers/ISQLIImportHandler::getNextRow()
     */
    public function getNextRow()
    {
        if ($this->rowIndex < $this->rowCount) {
            $row = $this->dataSource[$this->rowIndex];
            $this->rowIndex++;
        } else {
            $row = false; // We must return false if we already processed all rows
        }

        return $row;
    }

    /**
     * (non-PHPdoc)
     * @see extension/sqliimport/classes/sourcehandlers/ISQLIImportHandler::process()
     */
    public function process($row)
    {
        $contentData = $this->parseDocument($row);
        if (self::$enableDebug) {
            if ($this->rowIndex == 1) {
                print_r($row);
                print_r($contentData);
                die();
            }
            return;
        }

        $this->currentGUID = $contentData['options']['remote_id'];
        $content = SQLIContent::create(new SQLIContentOptions($contentData['options']));
        foreach ($contentData['fields'] as $language => $fields) {
            if ($language == 'ita-IT') {
                foreach ($fields as $key => $value) {
                    $content->fields[$language]->{$key} = $value;
                }
            } else {
                $content->addTranslation($language);
                foreach ($fields as $key => $value) {
                    $content->fields[$language]->{$key} = $value;
                }
            }
        }
        $content->addLocation(SQLILocation::fromNodeID(self::$DOCUMENT_PARENT_NODE));
        $publisher = SQLIContentPublisher::getInstance();
        $publisher->publish($content);
        $node = $content->mainNode();
        unset($content);

        try {
            $this->sectionTools->changeSection($node);
        } catch (Exception $e) {
            $this->cli->error($e->getMessage());
        }
    }

    private function parseDocument(SimpleXMLElement $document)
    {
        $documentTypeTag = $this->getDocumentTypeTag($document);
        $documentType = null;
        $documentTypeNameIta = 'Documento';
        $documentTypeNameGer = 'Dokument';
        if ($documentTypeTag instanceof eZTagsObject){
            $stringArray = array();
            $stringArray[] = $documentTypeTag->attribute( 'id' );
            $stringArray[] = $documentTypeTag->attribute( 'keyword' );
            $stringArray[] = $documentTypeTag->attribute( 'parent_id' );

            $documentType = implode( '|#', $stringArray );
            $translations = $documentTypeTag->getTranslations();
            foreach ($translations as $translation){
                if ($translation->attribute('locale') == 'ita-IT'){
                    $documentTypeNameIta = $translation->attribute('keyword');
                }
                if ($translation->attribute('locale') == 'ger-DE'){
                    $documentTypeNameGer = $translation->attribute('keyword');
                }
            }
        }
        $topics = $this->getTopics($document);
        $links = $this->getLinks($document);
        $area = $this->getArea($document);
        $office = null; //$this->getOffice($document)
        $keywords = [
            $document->ListaTarget->Target->Codice1,
            $document->ListaTarget->Target->Denominazione1,
            $document->ListaTarget->Target->Codice2,
            $document->ListaTarget->Target->Denominazione2,
        ];

        return [
            'options' => [
                'class_identifier' => 'document',
                'remote_id' => self::$remotePrefix . $document->IdDocumento,
                'section_id' => 1,
            ],
            'fields' => [
                'ita-IT' => [
                    'name' => $documentTypeNameIta . ' n. ' . $document->Numero . ' del ' . $document->DataDocumento,
                    'has_code' => $document->Numero . '/' . $document->Anno,
                    'document_type' => $documentType ? $documentType .'|#ita-IT' : null,
                    'description' => $document->Oggetto,
                    'topics' => $topics,
                    'link' => $links,
                    'area' => $area,
                    'has_organization' => $office,
                    'start_time' => $this->getTimetamp((string)$document->DataEsecutivita),
                    'publication_start_time' => $this->getTimetamp((string)$document->ListaTarget->Target->DataInizioPubblicazione),
                    'publication_end_time' => $this->getTimetamp((string)$document->ListaTarget->Target->DataFinePubblicazione),
                    'data_di_firma' => $this->getTimetamp((string)$document->ListaTarget->Target->DataDocumento),
                    'protocollo' => (string)$document->NumeroProtocollo,
                    'anno_protocollazione' => (string)$document->AnnoProtocollo,
                    'keyword' => implode(',', $keywords),
                ],
                'ger-DE' => [
                    'name' => $documentTypeNameGer . ' Nr. ' . $document->Numero . ' vom ' . str_replace('/', '.', $document->DataDocumento),
                    'has_code' => $document->Numero . '/' . $document->Anno,
                    'document_type' => $documentType ? $documentType .'|#ger-DE' : null,
                    'description' => $document->Oggetto2,
                    'topics' => $topics,
                    'link' => $links,
                    'area' => $area,
                    'has_organization' => $office,
                    'start_time' => $this->getTimetamp((string)$document->DataEsecutivita),
                    'publication_start_time' => $this->getTimetamp((string)$document->ListaTarget->Target->DataInizioPubblicazione),
                    'publication_end_time' => $this->getTimetamp((string)$document->ListaTarget->Target->DataFinePubblicazione),
                    'data_di_firma' => $this->getTimetamp((string)$document->ListaTarget->Target->DataDocumento),
                    'protocollo' => (string)$document->NumeroProtocollo,
                    'anno_protocollazione' => (string)$document->AnnoProtocollo,
                    'keyword' => implode(',', $keywords),
                ]
            ]
        ];
    }

    /**
     * @param SimpleXMLElement $document
     * @return eZTagsObject|null
     */
    private function getDocumentTypeTag(SimpleXMLElement $document)
    {
        $tags = eZTagsObject::fetchByKeyword($document->TipoAtto);
        foreach ($tags as $tag){
            if ($tag->isSynonym()){
                return $tag->getMainTag();
            }
        }

        return null;

//        if ($document->TipoAtto_Descrizione == 'DETERMINA') {
//            return '143|#Determinazione|#132|#ita-IT';
//        }
//
//        return '136|#Deliberazione|#132|#ita-IT';
    }

    private function getTopics(SimpleXMLElement $document)
    {
        return self::$DEFAULT_TOPIC_OBJECT;
    }

    private function getLinks(SimpleXMLElement $document)
    {
        $data = [];
        foreach ($document->Allegati->Allegato as $allegato) {
            $data[] = $allegato->Commento . ' ' . $allegato->TipoAllegato . '|' . '/j/download/' . $document->IdDocumento . '/' . $allegato->Serial;
        }

        return implode('&', $data);
    }

    private function getArea(SimpleXMLElement $document)
    {
        if ((string)$document->Struttura == '') {
            return null;
        }

        $areaRemoteId = 'area_' . $document->Struttura;
        if (self::$enableDebug) {
            return $areaRemoteId;
        }
        $area = eZContentObject::fetchByRemoteID($areaRemoteId);

        return $area instanceof eZContentObject ? $area->attribute('id') : null;
    }

    private function getTimetamp($string)
    {
        if (empty($string)) {
            return null;
        }

        //@see https://www.php.net/manual/en/function.strtotime.php
        $date = str_replace('/', '-', $string);

        return strtotime($date);
    }

    /**
     * (non-PHPdoc)
     * @see extension/sqliimport/classes/sourcehandlers/ISQLIImportHandler::cleanup()
     */
    public function cleanup()
    {
        // Nothing to clean up
        return;
    }

    /**
     * (non-PHPdoc)
     * @see extension/sqliimport/classes/sourcehandlers/ISQLIImportHandler::getHandlerName()
     */
    public function getHandlerName()
    {
        return 'Import Jiride';
    }

    /**
     * (non-PHPdoc)
     * @see extension/sqliimport/classes/sourcehandlers/ISQLIImportHandler::getHandlerIdentifier()
     */
    public function getHandlerIdentifier()
    {
        return 'importjirideimporthandler';
    }

    /**
     * (non-PHPdoc)
     * @see extension/sqliimport/classes/sourcehandlers/ISQLIImportHandler::getProgressionNotes()
     */
    public function getProgressionNotes()
    {
        return 'Currently importing : ' . $this->currentGUID;
    }

    private function getOffice(SimpleXMLElement $document)
    {
        if ((string)$document->Proponente == '') {
            return null;
        }

        $officeRemoteId = 'office_' . $document->Proponente;
        if (self::$enableDebug) {
            return $officeRemoteId;
        }
        $office = eZContentObject::fetchByRemoteID($officeRemoteId);
        if (!$office instanceof eZContentObject) {
            $office = eZContentFunctions::createAndPublishObject(
                array(
                    'class_identifier' => 'office',
                    'parent_node_id' => self::$OFFICE_PARENT_NODE,
                    'remote_id' => 'office_' . $document->Proponente,
                    'attributes' => array(
                        'legal_name' => (string)$document->Proponente_Descrizione,
                        'identifier' => $document->Proponente,
                        'is_part_of' => $this->getArea($document),
                    )
                )
            );
        }
        return $office instanceof eZContentObject ? $office->attribute('id') : null;
    }
}
