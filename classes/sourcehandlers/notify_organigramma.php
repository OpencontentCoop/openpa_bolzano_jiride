<?php

class OpenPABolzanoNotifyOrganigrammaHandler extends SQLIImportAbstractHandler implements ISQLIImportHandler
{
    private $done;

    public function initialize()
    {
        $this->progressionNotes = '...';
    }

    public function getProcessLength()
    {
        return 1;
    }

    public function getNextRow()
    {
        return $this->done === null;
    }

    public function process($row)
    {
        $this->done = true;
        try {
            if (!isset($this->options['to']) || empty($this->options['to'])) {
                throw new Exception("Opzione 'to' non trovata");
            }

            $strutture = $this->getStruttureWsData();
            $dipendenti = $this->getDipendentiWsData($strutture);
            $dipendentiByEmail = [];
            foreach ($dipendenti as $dipendente) {
                if (!empty($dipendente['email'])) {
                    $dipendentiByEmail[$dipendente['email']] = $dipendente;
                }
            }

            $struttureOnLine = $this->getAllResources(['administrative_area', 'office']);
            $struttureOnLineDaRimuovere = [];
            foreach ($struttureOnLine as $strutturaOnLine) {
                $remoteId = $strutturaOnLine->attribute('object')->attribute('remote_id');
                if (!isset($strutture[$remoteId])) {
                    $struttureOnLineDaRimuovere[] = [
                        'url' => "https://opencity.comune.bolzano.it/openpa/object/{$remoteId}",
                        'descrizione' => $strutturaOnLine->attribute('name')
                    ];
                } else {
                    unset($strutture[$remoteId]);
                }
            }
            $struttureDaAggiungere = [];
            foreach ($strutture as $struttura) {
                $struttureDaAggiungere[] = [
                    'codice' => $struttura['codiceIride'],
                    'descrizione' => $struttura['descrizioneIt']
                ];
            }

            $dipendentiOnLine = $this->getAllResources(['employee']);
            $dipendentiOnLineDaRimuovere = [];
            foreach ($dipendentiOnLine as $dipendenteOnLine) {
                $remoteId = $dipendenteOnLine->attribute('object')->attribute('remote_id');
                $dataMap = $dipendenteOnLine->dataMap();
                $email = isset($dataMap['email']) ? $dataMap['email']->toString() : $remoteId;

                $existsByRemoteId = isset($dipendenti[$remoteId]);
                $existsByEmail = isset($dipendentiByEmail[$email]);
                $isAlreadyRestricted = $dipendenteOnLine->attribute('object')->attribute('section_id') != 1;

                if (!$existsByRemoteId && !$existsByEmail && !$isAlreadyRestricted) {
                    $codice = str_replace('employee_', '', $remoteId);
                    if (!is_numeric($codice)) {
                        $codice = '?';
                    }
                    $dipendentiOnLineDaRimuovere[$dipendenteOnLine->attribute('name')] = [
                        'url' => "https://opencity.comune.bolzano.it/openpa/object/{$remoteId}",
                        'nome' => $dipendenteOnLine->attribute('name'),
                        'email' => $email,
                        'codice' => $codice,
                        'sezione' => $dipendenteOnLine->attribute('object')->attribute('section_id')
                    ];
                } else {
                    unset($dipendenti[$remoteId]);
                }
            }
            ksort($dipendentiOnLineDaRimuovere);

            $dipendentiDaAggiungere = [];
            foreach ($dipendenti as $dipendente) {
                if (!isset($dipendente['_struttura']['descrizioneIt'])) {
                    $strutturaDiRiferimento = implode(',', array_column($dipendente['_struttura'], 'descrizioneIt'));
                } else {
                    $strutturaDiRiferimento = $dipendente['_struttura']['descrizioneIt'];
                }
                $dipendentiDaAggiungere[$dipendente['cognome'] . $dipendente['nome'] . $dipendente['codice']] = [
                    'codice' => $dipendente['codice'],
                    'nome' => $dipendente['cognome'] . ' ' . $dipendente['nome'],
                    'email' => $dipendente['email'],
                    'struttura' => $strutturaDiRiferimento
                ];
            }
            ksort($dipendentiDaAggiungere);

            if (count($struttureDaAggiungere) || count($struttureOnLineDaRimuovere) || count($dipendentiDaAggiungere) || count($dipendentiOnLineDaRimuovere)) {
                $message = $this->compileNotificationMessage($struttureDaAggiungere, $struttureOnLineDaRimuovere, $dipendentiDaAggiungere, $dipendentiOnLineDaRimuovere);
                $subject = 'Aggiornamenti necessari organigramma sito (scansione del ' . date('d/M/Y', time()) . ')';
                $emailSender = eZINI::instance()->variable('MailSettings', 'EmailSender');
                if (!$emailSender)
                    $emailSender = eZINI::instance()->variable('MailSettings', 'AdminEmail');
                $receivers = explode(';', $this->options['to']);

                $mail = new ezcMailComposer();
                $mail->from = new ezcMailAddress($emailSender);
                foreach ($receivers as $receiver) {
                    $mail->addTo(new ezcMailAddress($receiver));
                }
                $mail->subject = $subject;
                $mail->plainText = $subject;
                $mail->htmlText = $message;
                $mail->addFileAttachment(__DIR__ . '/strutture.json');
                $mail->addFileAttachment(__DIR__ . '/dipendenti.json');
                $mail->build();

                $ezMail = new eZMail();
                $ezMail->Mail = $mail;

                eZMailTransport::send($ezMail);
                $this->progressionNotes = 'Individuate variazioni: notifica inviata';
                @unlink(__DIR__ . '/strutture.json');
                @unlink(__DIR__ . '/dipendenti.json');

            } else {
                $this->progressionNotes = 'Nessuna variazione';
            }

        } catch (Exception $e) {
            $this->progressionNotes = 'Errore: ' . $e->getMessage();
            SQLIImportLogger::logError($e->getMessage());
        }
    }

    private function getStruttureWsData()
    {
        $opts = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false],];
        $context = stream_context_create($opts);

        $url = 'https://www.comune.bolzano.it/open-data/datigenerali/strutture.json';
        $struttureSourceData = file_get_contents($url, false, $context);
        if (!$struttureSourceData) {
            throw new Exception('Errore ricavando le strutture');
        }

        $strutture = [];
        $struttureSourceData = json_decode($struttureSourceData, true);
        foreach ($struttureSourceData as $strutturaSourceData) {
            if (empty($strutturaSourceData['codice']) || trim($strutturaSourceData['codice']) == '-') continue;
            $strutture[$this->calculateStrutturaRemoteId($strutturaSourceData)] = $strutturaSourceData;
        }
        file_put_contents(__DIR__ . '/strutture.json', json_encode($strutture));

        return $strutture;
    }

    private function calculateStrutturaRemoteId($struttura, $prefix = null)
    {
        $codiceIrideNormalizzato = str_replace('.', '', $struttura['codiceIride']);
        $codiceIrideNormalizzato = eZCharTransform::instance()->transformByGroup($codiceIrideNormalizzato, 'identifier');
        if (!$prefix) {
            if ($struttura['tipo'] === 'RIP') {
                $prefix = 'area_';
            } else {
                $prefix = 'office_';
            }
        }
        return $prefix . $codiceIrideNormalizzato;
    }

    private function getDipendentiWsData($strutture)
    {
        $opts = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false],];
        $context = stream_context_create($opts);

        $dipendenti = [];
        foreach ($strutture as $remoteId => $struttura) {
            $url = 'https://www.comune.bolzano.it/open-data/datigenerali/dipendenti.json?struttura=' . $struttura['codice'];
            $dipendentiSourceData = file_get_contents($url, false, $context);
            if (!$dipendentiSourceData) {
                throw new Exception('Errore ricavando i dipendenti');
            }
            $data = json_decode($dipendentiSourceData, true);
            foreach ($data as $item) {
                $dipendenteRemoteId = 'employee_' . $item['codice'];
                if (isset($dipendenti[$dipendenteRemoteId])) {
                    $dipendenti[$dipendenteRemoteId]['_struttura'][] = $remoteId;
                } else {
                    $item['_struttura'] = [$remoteId];
                    $dipendenti[$dipendenteRemoteId] = $item;
                }
            }
        }
        ksort($dipendenti);
        file_put_contents(__DIR__ . '/dipendenti.json', json_encode($dipendenti));

        return $dipendenti;
    }

    /**
     * @param $classes
     * @return eZContentObjectTreeNode[]
     */
    private function getAllResources($classes)
    {
        return eZContentObjectTreeNode::subTreeByNodeID(array(
            'ClassFilterType' => 'include',
            'ClassFilterArray' => $classes,
            'Limitation' => []
        ), 1);
    }

    private function compileNotificationMessage($struttureDaAggiungere, $struttureOnLineDaRimuovere, $dipendentiDaAggiungere, $dipendentiOnLineDaRimuovere)
    {
        $message = '<html lang="it"><head><style>*{font-family: sans-serif}</style></head><body>';
        if (count($struttureDaAggiungere)) {
            $message .= '<br /><h3>' . count($struttureDaAggiungere) . ' strutture NON presenti sul sito ma presenti nei webservices</h3>';
            $message .= '<table width="100%" border="1" cellspacing="0" cellpadding="4">';
            $message .= '<tr><th>Codice</th><th>Nome</th></tr>';
            foreach ($struttureDaAggiungere as $item) {
                $message .= '<tr><td>' . $item['codice'] . '</td><td>' . $item['descrizione'] . '</td></tr>';
            }
            $message .= '</table>';
        }
        if (count($struttureOnLineDaRimuovere)) {
            $message .= '<br /><h3>' . count($struttureOnLineDaRimuovere) . ' strutture presenti sul sito ma NON presenti nei webservices</h3>';
            $message .= '<table width="100%" border="1" cellspacing="0" cellpadding="4">';
            foreach ($struttureOnLineDaRimuovere as $item) {
                $message .= '<tr><td><a href="' . $item['url'] . '">' . $item['descrizione'] . '</a></td></tr>';
            }
            $message .= '</table>';
        }
        if (count($dipendentiDaAggiungere)) {
            $message .= '<br /><h3>' . count($dipendentiDaAggiungere) . ' dipendenti NON presenti sul sito ma presenti nei webservices</h3>';
            $message .= '<table width="100%" border="1" cellspacing="0" cellpadding="4">';
            $message .= '<tr><th width="1">Codice</th><th>Nome</th><th>Email</th><th>Struttura</th></tr>';
            foreach ($dipendentiDaAggiungere as $item) {
                $message .= '<tr><td>' . $item['codice'] . '</td><td>' . $item['nome'] . '</td><td>' . $item['email'] . '</td><td>' . $item['struttura'] . '</td></tr>';
            }
            $message .= '</table>';
        }
        if (count($dipendentiOnLineDaRimuovere)) {
            $message .= '<br /><h3>' . count($dipendentiOnLineDaRimuovere) . ' dipendenti presenti sul sito ma NON presenti nei webservices</h3>';
            $message .= '<table width="100%" border="1" cellspacing="0" cellpadding="4">';
            $message .= '<tr><th width="1">Codice</th><th>Nome</th><th>Email</th><th>Sezione</th></tr>';
            foreach ($dipendentiOnLineDaRimuovere as $item) {
                $message .= '<tr><td>' . $item['codice'] . '</td><td><a href="' . $item['url'] . '">' . $item['nome'] . '</a></td><td>' . $item['email'] . '</td><td>' . $item['sezione'] . '</td></tr>';
            }
            $message .= '</table>';
        }
        $message .= '</body></html>';

        return $message;
    }

    public function cleanup()
    {
        return false;
    }

    public function getHandlerName()
    {
        return 'Notifica variazioni organigramma';
    }

    public function getHandlerIdentifier()
    {
        return 'notifyorganigrammahandler';
    }

    public function getProgressionNotes()
    {
        return $this->progressionNotes;
    }
}
