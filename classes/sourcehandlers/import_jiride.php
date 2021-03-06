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

        if (strpos($toTimestamp, '/') !== false){
            list($day, $month, $year) = explode('/', $toTimestamp);
            $toTimestamp = mktime(0,0,0,$month, $day, $year);
        }

        // aggiungo un giorno al parametro AllaDataUpd per vedere le modifiche il giorno stesso
        $to = date('d/m/Y', ($toTimestamp + 86400));

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
        $certFile = getenv('JIRIDE_BOLZANO_CERT');
        if ($certFile){
            $wsdlUrl = 'extension/openpa_bolzano_jiride/WSBachecaSoap-saas.wsdl';

            $context = stream_context_create();
            stream_context_set_option($context, 'ssl', 'capture_peer_cert', true);
            stream_context_set_option($context, 'ssl', 'local_cert', $certFile);
            stream_context_set_option($context, 'ssl', 'ciphers', 'SSLv3');
            stream_context_set_option($context, 'ssl', 'verify_peer', false);
            stream_context_set_option($context, 'ssl', 'verify_peer_name', false);
            stream_context_set_option($context, 'ssl', 'allow_self_signed', true);

            return new SoapClient($wsdlUrl, [
                'local_cert' => $certFile,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'exceptions' => true,
                'stream_context' => $context
            ]);
        }else{
            $wsdlUrl = 'extension/openpa_bolzano_jiride/WSBachecaSoap.wsdl';
            return new SoapClient($wsdlUrl, [
                'cache_wsdl' => WSDL_CACHE_NONE,
            ]);
        }
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
	if ($row->Stato != 'attivo'){
	    eZContentObjectTreeNode::hideSubTree($node);
	}
    }

    private function parseDocument(SimpleXMLElement $document)
    {
        eZLog::write($document->asXML(), 'jiride.log');

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
            $document->TipoAtto_Descrizione,
            $document->ListaTarget->Target->Codice1,
            $document->ListaTarget->Target->Denominazione1,
            $document->ListaTarget->Target->Codice2,
            $document->ListaTarget->Target->Denominazione2,
            $document->ListaTarget->Target->Codice1 . '_' . $document->ListaTarget->Target->Codice2,
        ];

        $numero = (string)$document->Numero;
        $data = (string)$document->DataDocumento;

        if (!empty($numero)){
            $documentTypeNameIta .= ' n. ' . $document->Numero;
            $documentTypeNameGer .= ' Nr. ' . $document->Numero;
        }
        if (!empty($data)){
            $documentTypeNameIta .= ' del ' . $document->DataDocumento;
            $documentTypeNameGer .= ' vom ' . str_replace('/', '.', $document->DataDocumento);
        }

        return [
            'options' => [
                'class_identifier' => 'document',
                'remote_id' => self::$remotePrefix . $document->IdDocumento,
                'section_id' => 1,
            ],
            'fields' => [
                'ita-IT' => [
                    'name' => $documentTypeNameIta,
                    'has_code' => $document->Numero . '/' . $document->Anno,
                    'document_type' => $documentType ? $documentType .'|#ita-IT' : null,
                    'description' => (string)$document->Oggetto,
                    'topics' => $topics,
                    'links' => $links,
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
                    'name' => $documentTypeNameGer,
                    'has_code' => $document->Numero . '/' . $document->Anno,
                    'document_type' => $documentType ? $documentType .'|#ger-DE' : null,
                    'description' => (string)$document->Oggetto2,
                    'topics' => $topics,
                    'links' => $links,
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
        $this->cli->error($document->TipoAtto);
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
//            $data[] = $allegato->Commento . ' ' . $allegato->TipoAllegato . '|' . '/j/download/' . $document->IdDocumento . '/' . $allegato->Serial;
            $data[] = $allegato->Commento . '|' . '/j/download/' . $document->IdDocumento . '/' . $allegato->Serial;
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
