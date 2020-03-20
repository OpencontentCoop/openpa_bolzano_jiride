<?php

class OpenPABolzanoImportJirideHandler extends SQLIImportAbstractHandler implements ISQLIImportHandler
{
    const OFFICE_PARENT_NODE = 15889;

    const DOCUMENT_PARENT_NODE = 15896;

    const DEFAULT_TOPIC_OBJECT = 23200;

    private static $enableDebug = false;

    protected $rowIndex = 0;

    protected $rowCount;

    protected $currentGUID;

    private static $data = array();

    public static $remotePrefix = 'jiride-';

    /**
     * @var OpenPASectionTools
     */
    private $sectionTools;

    /**
     * (non-PHPdoc)
     * @see extension/sqliimport/classes/sourcehandlers/ISQLIImportHandler::initialize()
     */
    public function initialize()
    {                        
        $this->sectionTools = new OpenPASectionTools();
        
        if (isset($this->options['from'])){
            $fromTimestamp = $this->options['from'];
        }else{
            $fromTimestamp = null;
            $lastImportItem = eZDB::instance()->arrayQuery("SELECT * FROM sqliimport_item WHERE handler = 'importjirideimporthandler' ORDER BY requested_time desc LIMIT 1");
    		if(count($lastImportItem) > 0){
    			$fromTimestamp = $lastImportItem[0]['requested_time'];
    			$fromTimestamp = $fromTimestamp - 86400;			
    		}
        }

        if (isset($this->options['to'])){
            $toTimestamp = $this->options['to'];
        }else{
            $toTimestamp = time();
        }
        
        $this->dataSource = self::fetchData($fromTimestamp, $toTimestamp);
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
        if (self::$enableDebug){            
            if ($this->rowIndex == 1){
                print_r($row); 
                print_r($contentData); 
                die();
            }
            return;
        }

        $this->currentGUID = $contentData['options']['remote_id'];        
        $content = SQLIContent::create(new SQLIContentOptions($contentData['options']));
        foreach ($contentData['fields'] as $key => $value) {
            $content->fields->{$key} = $value;
        }
        $content->addLocation( SQLILocation::fromNodeID(self::DOCUMENT_PARENT_NODE));
        $publisher = SQLIContentPublisher::getInstance();
        $publisher->publish($content);
        $node = $content->mainNode();
        unset($content);
        
        try {            
            $this->sectionTools->changeSection($node);            
        }catch (Exception $e){
            $cli->error($e->getMessage());
        }
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

    private static function getSoapClient()
    {
        $wsdlUrl = 'extension/openpa_bolzano_jiride/WSBachecaSoap.wsdl';
        
        return new SoapClient($wsdlUrl);
    }

    public static function fetchData($fromTimestamp, $toTimestamp, $page = 1)
    {
        if ($page === 1){
            self::$data = array();
        }

        if (empty($fromTimestamp)) {
            $from = '01/01/2020';
        } else {
            $from = strpos($fromTimestamp, '/') === false ? date('d/m/Y', $fromTimestamp) : $fromTimestamp;
        }

        $to = strpos($toTimestamp, '/') === false  ? date('d/m/Y', $toTimestamp) : $toTimestamp;
        
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
        
        if (self::$enableDebug){
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

        foreach ($data->Documenti->Documento as $doc){
            self::$data[] = $doc;
        }

        $totaleDocumenti = (int)$data->Documenti['totaleDocumenti'];
        $dimensionePagina = (int)$data->Documenti['DimensionePagina'];
        $indicePagina = (int)$data->Documenti['IndicePagina'];

        if (count(self::$data) < $totaleDocumenti){
            $page++;
            self::fetchData($fromTimestamp, $toTimestamp, $page);
        }

        return self::$data;
    }

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

    private function parseDocument(SimpleXMLElement $document)
    {
    	return [
    		'options' => [
    			'class_identifier' => 'document',
    			'remote_id' => self::$remotePrefix . $document->IdDocumento,
                'section_id' => 1,
    		],
            'fields' => [
                'name' => $document->TipoAtto_Descrizione . ' del ' . $document->DataDocumento,
                'has_code' => $document->Numero . '/' . $document->Anno,
                'document_type' => $this->getDocumentType($document),
                'description' => $document->Oggetto . ' ' . $document->Oggetto2 . ' ' . $document->Oggetto3,
                'topics' => $this->getTopics($document),
                'link' => $this->getLinks($document),
                'area' => $this->getArea($document),
                'has_organization' => $this->getOffice($document),
                'start_time' => $this->getTimetamp((string)$document->DataEsecutivita),
                'publication_start_time' => $this->getTimetamp((string)$document->ListaTarget->Target->DataInizioPubblicazione),
                'publication_end_time' => $this->getTimetamp((string)$document->ListaTarget->Target->DataFinePubblicazione),
                'data_di_firma' => $this->getTimetamp((string)$document->ListaTarget->Target->DataDocumento),
                'protocollo' => (string)$document->NumeroProtocollo,
                'anno_protocollazione' => (string)$document->AnnoProtocollo,                
                'keyword' => $document->ListaTarget->Target->Denominazione1 . ',' . $document->ListaTarget->Target->Denominazione2,
            ]
    	];
    }

    private function getDocumentType(SimpleXMLElement $document)
    {
        if ($document->TipoAtto_Descrizione == 'DETERMINA'){
            return 'Detereminazione';
        }

        return 'Deliberazione';
    }

    private function getTopics(SimpleXMLElement $document)
    {
        return self::DEFAULT_TOPIC_OBJECT;
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
        if ((string)$document->Struttura == ''){
            return null;
        }

        $areaRemoteId = 'area_' . $document->Struttura;
        if (self::$enableDebug){
            return $areaRemoteId;
        }
        $area = eZContentObject::fetchByRemoteID($areaRemoteId);
        
        return $area instanceof eZContentObject ? $area->attribute('id') : null;
    }

    private function getOffice(SimpleXMLElement $document)
    {
        if ((string)$document->Proponente == ''){
            return null;
        }

        $officeRemoteId = 'office_' . $document->Proponente;
        if (self::$enableDebug){
            return $officeRemoteId;
        }
        $office = eZContentObject::fetchByRemoteID($officeRemoteId);
        if (!$office instanceof eZContentObject){
            $office = eZContentFunctions::createAndPublishObject(
                array(
                    'class_identifier' => 'office',
                    'parent_node_id' => self::OFFICE_PARENT_NODE,
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

    private function getTimetamp($string)
    {
        if (empty($string)){
            return null;
        }

        //@see https://www.php.net/manual/en/function.strtotime.php
        $date = str_replace('/', '-', $string);

        return strtotime($date);
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
}
