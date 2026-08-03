<?php

// ===========================================================================
// MigrationsHub — Werkzeug zur Migration von Bestandsgeräten: Verknüpfung
// alter Variablen mit neuen (z. B. nach Gerätetausch oder Umstieg auf ein
// Hub-Modul) sowie Übernahme historischer Archivwerte auf die neue Variable.
//
// Kerntechnik für die Archivübernahme: AC_ChangeVariableID($ArchiveID,
// $OldVariableID, $NewVariableID). Funktioniert nur, wenn $NewVariableID noch
// NIE geloggt wurde ("jungfräulicher" Archiv-Zustand) — das ist keine
// Dokumentations-API, sondern per get_defined_functions() gefunden und in der
// Praxis (GoodweET-Migration) empirisch bestätigt. Siehe README.md.
//
// Stand: Gerüst. Die eigentliche Zuordnungs- und Migrationslogik (Formular,
// Massenverarbeitung mehrerer Variablenpaare) folgt als nächster Schritt.
// ===========================================================================

class MigrationsHub extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Liste von Migrationsaufträgen: je Eintrag altes/neues Variablenpaar
        // und Status. Property statt Attribut, damit der Nutzer sie im
        // Formular pflegen kann (analog Channels-Liste in GleitenderMittelwert).
        $this->RegisterPropertyString('Migrations', '[]');

        // Aktuell im Formular gewählte Alt-/Neu-Instanz (Schritt 1), damit die
        // Auswahl über Formular-Neuöffnungen hinweg erhalten bleibt.
        $this->RegisterPropertyInteger('SourceInstanceID', 0);
        $this->RegisterPropertyInteger('TargetInstanceID', 0);

        // Abhak-Listen manuell zu prüfender Fundstellen (Skripte/Events, die
        // die Alt-Variablen-ID referenzieren) — bewusst Attribute statt
        // Properties: beim "Änderungen übernehmen" schreibt IP-Symcon nur die
        // editierbaren Spalten einer Formular-Liste in die Property zurück,
        // reine Anzeige-Spalten wie OldName gehen dabei verloren. Attribute
        // hängen nicht am Speichern-Zyklus des Formulars; geschrieben wird
        // direkt beim Scan bzw. per onEdit-Handler beim Abhaken.
        // Getrennt nach Skript/Event (statt einer gemeinsamen Liste), weil
        // sich damit im Formular je ein SelectScript-/SelectEvent-Feld
        // verwenden lässt — die haben in der Konsole einen eingebauten
        // "Bearbeiten"-Knopf, der direkt zum Objekt springt.
        $this->RegisterAttributeString('ScriptChecks', '[]');
        $this->RegisterAttributeString('EventChecks', '[]');

        // Persistenter, deterministischer Nachweis jedes ECHTEN Migrations-/
        // Adoptionslaufs (kein Probelauf) — was wurde wann mit welchem Ergebnis
        // übertragen. Anders als die Ergebnisliste im Formular (die beim
        // nächsten Lauf überschrieben wird) bleibt das erhalten; wichtig für
        // Nachvollziehbarkeit bei abrechnungsrelevanten Variablen (z. B.
        // Zählerstände). Auf MAX_LOG_ENTRIES gedeckelt (älteste zuerst raus).
        $this->RegisterAttributeString('MigrationLog', '[]');
    }

    private const MAX_LOG_ENTRIES = 500;

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }

    // --- Discovery-Integration für andere NRG-Stack-Module ---------------
    //
    // Damit die Migration Teil des normalen Geräte-Scans werden kann (statt
    // ein separates Werkzeug, das der Nutzer erst finden muss): ein Hub-Modul
    // wie InverterHub/MeterHub kann nach einem Discovery-Treffer bei uns
    // nachfragen, ob es dafür schon eine Alt-Instanz gibt — und bei Zustimmung
    // unsere Instanz direkt vorbelegt öffnen. Aufruf immer hinter
    // function_exists('MIGHUB_...') absichern (Eigenständigkeitsregel).
    //
    // Ablauf für das aufrufende Modul:
    // 1. Existiert noch keine MigrationsHub-Instanz, selbst eine anlegen
    //    (IPS_CreateInstance('{330717BB-E309-41A2-90A8-FDA3179ED948}')) — dafür
    //    braucht es keine Funktion von uns, das ist normale IPS-Handhabung.
    // 2. MIGHUB_FindLegacyCandidates($migId, $host, $port, $unitId) aufrufen.
    // 3. Bestätigt der Nutzer einen Treffer: MIGHUB_PrefillMigration($migId,
    //    $oldInstanceID, $newInstanceID) aufrufen (setzt Quelle/Ziel bei uns).
    // 4. Zur MigrationsHub-Instanz navigieren — dafür im eigenen Formular ein
    //    "OpenObjectButton" auf die (jetzt vorbelegte) Instanz-ID einsetzen,
    //    exakt das Element, das wir selbst für Skript-/Ereignis-Funde nutzen.

    // Sucht unter allen Instanzen nach möglichen Alt-Instanzen mit passendem
    // Host/Port/Unit-ID — NIE über den Namen, der ist frei vergeben und schon
    // zweimal irreführend gewesen (ModBus-Gateway namens "Goodwe
    // Wechselrichter", fünf gleichnamige, aber unterschiedliche "Siemens
    // PAC2200"-Instanzen). $port/$unitId = 0 bedeutet "nicht einschränken".
    public function FindLegacyCandidates(string $host, int $port = 0, int $unitId = 0): array
    {
        $candidates = [];
        foreach (IPS_GetInstanceList() as $instanceID) {
            $config = json_decode(@IPS_GetConfiguration($instanceID) ?: '', true);
            if (!is_array($config)) {
                continue;
            }
            if (!$this->ConfigMatchesHost($config, $host)) {
                continue;
            }
            if ($port > 0) {
                $configPort = $this->ExtractConfigValue($config, ['Port']);
                if ($configPort !== null && (int) $configPort !== $port) {
                    continue;
                }
            }
            if ($unitId > 0) {
                $configUnitId = $this->ExtractConfigValue($config, ['UnitId', 'UnitID', 'SlaveId', 'SlaveID', 'ModbusUnitId']);
                if ($configUnitId !== null && (int) $configUnitId !== $unitId) {
                    continue;
                }
            }
            $candidates[] = [
                'InstanceID' => $instanceID,
                'Name' => IPS_GetName($instanceID),
                'ModuleName' => IPS_GetInstance($instanceID)['ModuleInfo']['ModuleName'] ?? '',
                // Bei mehreren Treffern mit identischer IP (z. B. zwei baugleiche
                // Geräte ohne unterscheidbare Port/Unit-ID) kann das aufrufende
                // Modul den Pfad als Unterscheidungshinweis anzeigen, statt
                // blind zu raten — echter Fall: zwei goE-Charger-Instanzen
                // gleicher IP, eine unter "API", eine unter "Sicherung".
                'Path' => $this->BuildObjectPath($instanceID),
            ];
        }
        return $candidates;
    }

    // Kategorie-Pfad eines Objekts von der Wurzel bis (ausschließlich) zum
    // Objekt selbst, z. B. "Geräte / Module / Wallbox / Sicherung".
    private function BuildObjectPath(int $objectID): string
    {
        $parts = [];
        $cursor = IPS_GetObject($objectID)['ParentID'];
        while ($cursor > 0) {
            array_unshift($parts, IPS_GetName($cursor));
            $cursor = IPS_GetObject($cursor)['ParentID'];
        }
        return implode(' / ', $parts);
    }

    private function ExtractConfigValue(array $config, array $keys)
    {
        foreach ($keys as $key) {
            if (isset($config[$key]) && $config[$key] !== '') {
                return $config[$key];
            }
        }
        return null;
    }

    // Statt einer festen Schlüsselliste (die bei jedem neuen Modul mit einer
    // abweichenden Benennung wie "IPAddressCharger" wieder danebenläge): jeden
    // Konfigurationsschlüssel prüfen, dessen NAME nach Host/IP/Adresse klingt
    // (unabhängig von Modul-Eigenheiten in der genauen Schreibweise), und
    // dessen WERT dem gesuchten Host entspricht.
    private function ConfigMatchesHost(array $config, string $host): bool
    {
        foreach ($config as $key => $value) {
            if (!is_string($value) && !is_int($value)) {
                continue;
            }
            if (!preg_match('/host|ip|address/i', (string) $key)) {
                continue;
            }
            if (strcasecmp((string) $value, $host) === 0) {
                return true;
            }
        }
        return false;
    }

    // Belegt Quelle/Ziel dieser Instanz vor — die "Aktion", die ein anderes
    // Modul nach einer bestätigten Discovery-Übereinstimmung aufrufen kann,
    // bevor es den Nutzer per OpenObjectButton zu uns weiterleitet. Das ist
    // eine externe Instanz, die unsere Properties setzt, keine Formular-
    // Selbstpersistenz — die Stable-Regel dazu betrifft nur unsere EIGENEN
    // Formular-Schaltflächen.
    public function PrefillMigration(int $oldInstanceID, int $newInstanceID): void
    {
        $this->SetProperty('SourceInstanceID', $oldInstanceID);
        $this->SetProperty('TargetInstanceID', $newInstanceID);
        $this->ApplyChanges();
    }

    // Befüllt die attribut-gestützten Checklisten beim Öffnen des Formulars —
    // deren Inhalt steht nicht in form.json ("values": []) und käme sonst
    // nach einem Neuöffnen leer zurück.
    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $scriptChecks = json_decode($this->ReadAttributeString('ScriptChecks'), true);
        $eventChecks = json_decode($this->ReadAttributeString('EventChecks'), true);
        $migrationLog = json_decode($this->ReadAttributeString('MigrationLog'), true);

        // "Alt-Datenpunkte" (Schritt 2) ist — anders als "Migrations" — keine
        // Instanz-Eigenschaft, sondern ein rein clientseitiger Listenwert:
        // ohne diese Zeile bliebe die Tabelle nach jedem Neuöffnen des
        // Formulars leer, obwohl die Migrationsliste (Schritt 3) weiter
        // Einträge zeigt — für den Nutzer nicht als "muss neu geladen werden"
        // erkennbar, sondern wie ein Fehler. Bei gesetzter Alt-Instanz daher
        // bei jedem Öffnen automatisch neu befüllen.
        $sourceInstanceID = $this->ReadPropertyInteger('SourceInstanceID');
        $sourceVariables = $sourceInstanceID !== 0
            ? $this->GetChildVariableRows($sourceInstanceID, $this->BuildLinkCountMap())
            : [];

        $form['elements'] = $this->InjectDynamicFormValues(
            $form['elements'],
            $scriptChecks,
            $eventChecks,
            $migrationLog,
            $sourceVariables
        );
        return json_encode($form);
    }

    // Läuft rekursiv durch die Formularelemente (auch verschachtelt in
    // ExpansionPanel/RowLayout-"items") und befüllt die dynamischen Felder.
    // Rekursiv statt nur oberste Ebene, weil ScriptChecks/EventChecks/
    // SourceVariables im Panel "Manuell / Feinabstimmung" verschachtelt sind.
    private function InjectDynamicFormValues(
        array $elements,
        array $scriptChecks,
        array $eventChecks,
        array $migrationLog,
        array $sourceVariables
    ): array {
        $result = [];
        foreach ($elements as $element) {
            if (isset($element['items']) && is_array($element['items'])) {
                $element['items'] = $this->InjectDynamicFormValues(
                    $element['items'],
                    $scriptChecks,
                    $eventChecks,
                    $migrationLog,
                    $sourceVariables
                );
            }
            $result[] = $element;

            // Direkt-Öffnen-Buttons unter die jeweilige Checkliste einfügen:
            // Listen-Spalten können keine Links darstellen, aber das Element
            // OpenObjectButton öffnet ein Objekt (Skript/Event) direkt zur
            // Bearbeitung in der Konsole. Die Buttons entstehen beim Laden des
            // Formulars — nach einem neuen Scan das Formular einmal neu öffnen,
            // damit sie zu den aktuellen Funden passen.
            if (($element['name'] ?? '') === 'ScriptChecks') {
                $result[count($result) - 1]['values'] = $scriptChecks;
                $panel = $this->BuildOpenButtonsPanel($scriptChecks, 'Skripte direkt öffnen');
                if ($panel !== null) {
                    $result[] = $panel;
                }
            } elseif (($element['name'] ?? '') === 'EventChecks') {
                $result[count($result) - 1]['values'] = $eventChecks;
                $panel = $this->BuildOpenButtonsPanel($eventChecks, 'Ereignisse direkt öffnen');
                if ($panel !== null) {
                    $result[] = $panel;
                }
            } elseif (($element['name'] ?? '') === 'StatusLabel') {
                $result[count($result) - 1]['caption'] = $this->BuildStatusLine($scriptChecks, $eventChecks);
            } elseif (($element['name'] ?? '') === 'MigrationLog') {
                $result[count($result) - 1]['values'] = array_reverse($migrationLog); // neueste zuerst
            } elseif (($element['name'] ?? '') === 'SourceVariables') {
                $result[count($result) - 1]['values'] = $sourceVariables;
            }
        }
        return $result;
    }

    // Eine Zeile Status auf einen Blick — ohne dass der Nutzer in die
    // Ergebnistabellen schauen muss. Fasst offene Prüfpunkte aus dem
    // Referenz-Scan zusammen; "bereit" heißt nur, dass nichts offen aussteht,
    // nicht dass bereits erfolgreich migriert wurde.
    private function BuildStatusLine(array $scriptChecks, array $eventChecks): string
    {
        $open = 0;
        foreach (array_merge($scriptChecks, $eventChecks) as $row) {
            if (empty($row['Done'])) {
                $open++;
            }
        }
        if ($open > 0) {
            return '⚠️ ' . $open . ' offene(r) Prüfpunkt(e) aus der Referenz-Suche — noch nicht abgehakt.';
        }
        return '✅ Bereit — keine offenen Prüfpunkte aus der Referenz-Suche.';
    }

    // Hängt Zeilen eines echten (nicht simulierten) Laufs an das persistente
    // Migrations-Log an, gedeckelt auf MAX_LOG_ENTRIES (älteste zuerst raus).
    private function AppendToMigrationLog(array $entries): void
    {
        if ($entries === []) {
            return;
        }
        $log = json_decode($this->ReadAttributeString('MigrationLog'), true);
        $timestamp = date('Y-m-d H:i:s');
        foreach ($entries as $entry) {
            $entry['Timestamp'] = $timestamp;
            $log[] = $entry;
        }
        if (count($log) > self::MAX_LOG_ENTRIES) {
            $log = array_slice($log, -self::MAX_LOG_ENTRIES);
        }
        $this->WriteAttributeString('MigrationLog', json_encode($log));
    }

    // Leert das persistente Migrations-Log (z. B. nach externer Archivierung).
    public function ClearMigrationLog(): void
    {
        $this->WriteAttributeString('MigrationLog', '[]');
        $this->UpdateFormField('MigrationLog', 'values', json_encode([]));
    }

    // Einklappbares Panel mit einem OpenObjectButton je (noch existierendem)
    // Fundobjekt — dedupliziert, weil dasselbe Skript für mehrere Alt-
    // Variablen gefunden werden kann, geöffnet werden muss es nur einmal.
    private function BuildOpenButtonsPanel(array $checks, string $caption): ?array
    {
        $buttons = [];
        $seen = [];
        foreach ($checks as $row) {
            $objectID = (int) ($row['ObjectID'] ?? 0);
            if ($objectID === 0 || isset($seen[$objectID]) || !IPS_ObjectExists($objectID)) {
                continue;
            }
            $seen[$objectID] = true;
            $buttons[] = [
                'type' => 'OpenObjectButton',
                'caption' => IPS_GetName($objectID) . ' (#' . $objectID . ')',
                'objectID' => $objectID,
            ];
        }
        if ($buttons === []) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel',
            'caption' => $caption,
            'items' => $buttons,
        ];
    }

    // onEdit-Handler der beiden Checklisten: schreibt den aktuellen Stand
    // (v. a. die Erledigt-Haken) sofort ins Attribut, damit der Fortschritt
    // ohne "Änderungen übernehmen" erhalten bleibt. Wichtig: der Client
    // liefert nur die editierbaren Spalten (ObjectID, Done) — die Zeilen
    // werden deshalb per Index in die vollständig gespeicherten Attribut-
    // Zeilen zurückgemergt statt sie zu ersetzen (sonst gingen OldName/
    // OldVariableID genauso verloren wie zuvor beim Property-Speichern).
    public function SaveScriptChecks($scriptChecks): void
    {
        $this->WriteAttributeString('ScriptChecks', json_encode(
            $this->MergeCheckEdits(json_decode($this->ReadAttributeString('ScriptChecks'), true), $this->NormalizeFormList($scriptChecks))
        ));
    }

    public function SaveEventChecks($eventChecks): void
    {
        $this->WriteAttributeString('EventChecks', json_encode(
            $this->MergeCheckEdits(json_decode($this->ReadAttributeString('EventChecks'), true), $this->NormalizeFormList($eventChecks))
        ));
    }

    private function MergeCheckEdits(array $stored, array $incoming): array
    {
        // Zeilenanzahl gleich (Normalfall: nur ein Haken wurde geändert) →
        // Index-Merge. Weniger Zeilen vom Client → Nutzer hat Zeilen gelöscht;
        // dann werden die gespeicherten Zeilen anhand der noch vorhandenen
        // ObjectID/Done-Paare gefiltert (Reihenfolge bleibt erhalten).
        if (count($incoming) === count($stored)) {
            foreach ($incoming as $i => $row) {
                $stored[$i]['Done'] = !empty($row['Done']);
                if (isset($row['ObjectID'])) {
                    $stored[$i]['ObjectID'] = (int) $row['ObjectID'];
                }
            }
            return $stored;
        }

        $result = [];
        $cursor = 0;
        foreach ($incoming as $row) {
            for ($i = $cursor; $i < count($stored); $i++) {
                if ((int) $stored[$i]['ObjectID'] === (int) ($row['ObjectID'] ?? 0)) {
                    $stored[$i]['Done'] = !empty($row['Done']);
                    $result[] = $stored[$i];
                    $cursor = $i + 1;
                    break;
                }
            }
        }
        return $result;
    }

    // Verknüpft eine alte mit einer neuen Variable: übernimmt, sofern möglich,
    // die Archivhistorie per AC_ChangeVariableID und hängt bestehende Links
    // (WebFront-Verknüpfungen), die auf die alte Variable zeigen, auf die neue
    // um. Gibt ein Ergebnis-Array zurück (Erfolg/Grund/Details), damit der
    // Aufrufer (Formular oder ein anderes Modul) den Ausgang anzeigen kann
    // statt nur true/false.
    // $dryRun=true durchläuft exakt dieselben Prüfungen, schreibt aber nichts:
    // kein AC_ChangeVariableID, kein AC_SetLoggingStatus, keine Links werden
    // umgehängt. 'archived'/'relinked' zeigen dann an, was passieren WÜRDE.
    public function MigrateVariable(int $oldVariableID, int $newVariableID, bool $dryRun = false): array
    {
        if (!IPS_VariableExists($oldVariableID)) {
            return ['success' => false, 'reason' => 'Alte Variable existiert nicht', 'archived' => false, 'relinked' => 0, 'dryRun' => $dryRun];
        }
        if (!IPS_VariableExists($newVariableID)) {
            return ['success' => false, 'reason' => 'Neue Variable existiert nicht', 'archived' => false, 'relinked' => 0, 'dryRun' => $dryRun];
        }
        if ($oldVariableID === $newVariableID) {
            return ['success' => false, 'reason' => 'Alte und neue Variable sind identisch', 'archived' => false, 'relinked' => 0, 'dryRun' => $dryRun];
        }

        $archived = false;
        $archiveID = $this->FindArchiveInstance($oldVariableID);
        if ($archiveID !== 0) {
            if ($this->HasArchiveHistory($archiveID, $newVariableID)) {
                // AC_ChangeVariableID funktioniert nur bei "jungfräulichem" Ziel —
                // ein bereits geloggtes Ziel still zu überschreiben wäre falsch.
                // Wichtig: das prüft tatsächlich vorhandene Werte, nicht nur den
                // Logging-Status (eine deaktiviert-geloggte Variable kann trotzdem
                // Altwerte im Archiv haben — realer Fall aus einer MeterHub-Analyse).
                return ['success' => false, 'reason' => 'Zielvariable hat bereits Archivhistorie', 'archived' => false, 'relinked' => 0, 'dryRun' => $dryRun];
            }
            if ($dryRun) {
                $archived = true; // würde übertragen werden
            } else {
                $archived = AC_ChangeVariableID($archiveID, $oldVariableID, $newVariableID);
                if (!$archived) {
                    return ['success' => false, 'reason' => 'AC_ChangeVariableID fehlgeschlagen', 'archived' => false, 'relinked' => 0, 'dryRun' => $dryRun];
                }
                // Zielvariable nach der Übernahme aktiv weiterloggen lassen — sie
                // muss vorher nicht zwingend für Logging aktiviert gewesen sein.
                AC_SetLoggingStatus($archiveID, $newVariableID, true);
                IPS_ApplyChanges($archiveID);
            }
        }

        $links = $this->FindLinksToVariable($oldVariableID);
        $relinked = 0;
        if ($dryRun) {
            $relinked = count($links); // würde umgehängt werden
        } else {
            foreach ($links as $linkID) {
                if (IPS_SetLinkTargetID($linkID, $newVariableID)) {
                    $relinked++;
                }
            }
        }

        return ['success' => true, 'reason' => '', 'archived' => $archived, 'relinked' => $relinked, 'dryRun' => $dryRun];
    }

    // --- Adoptions-Modus (bevorzugter, verlustfreier Migrationsweg) --------
    //
    // Statt die Historie per AC_ChangeVariableID umzuhängen, wird die ALTE
    // Variable selbst übernommen: sie behält ihre Objekt-ID, damit bleiben
    // Historie UND alle Variablen-Referenzen (Skripte/Ereignisse/Links) ohne
    // Umbau intakt. $newVariableID ist die vom Zielmodul frisch angelegte
    // Variable — aus ihr lesen wir Ident, Typ, Profil, Kategorie und Instanz.
    //
    // Scharfe Kante: das Zielmodul ruft in ApplyChanges PruneForeignObjects()
    // auf und LÖSCHT jede Variable mit ungültigem Ident/Typ. Adoption schlägt
    // also DESTRUKTIV fehl. Deshalb: Typ vorab prüfen, per Preflight-Sonde
    // (Wegwerf-Variable) absichern, und die echte historienbehaftete Variable
    // erst anfassen, wenn die Sonde überlebt hat. Wo Adoption nicht geht
    // (Typ-Mismatch, Sonde stirbt), signalisiert die Methode den Rückfall auf
    // AC_ChangeVariableID.
    public function AdoptVariable(int $oldVariableID, int $newVariableID, bool $dryRun = false): array
    {
        $fail = fn ($reason, $fallback = false) => ['success' => false, 'mode' => $fallback ? 'Rückfall' : '-', 'reason' => $reason, 'fallback' => $fallback, 'dryRun' => $dryRun];

        if (!IPS_VariableExists($oldVariableID)) {
            return $fail('Alte Variable existiert nicht');
        }
        if (!IPS_VariableExists($newVariableID)) {
            // Idempotenz-Fall: AdoptVariable() ist die einzige Stelle, die die
            // frische Modul-Variable löscht (Schritt 1 im scharfen Lauf). Fehlt
            // sie beim erneuten Aufruf desselben Paares, wurde dieses Paar
            // vermutlich in einem früheren Lauf schon adoptiert — statt eines
            // verwirrenden generischen Fehlers das klar so benennen, ohne einen
            // neuen (unnötigen) Preflight-Sonden-Lauf zu versuchen.
            if (IPS_ObjectExists($oldVariableID)) {
                return ['success' => true, 'mode' => 'Adoption', 'reason' => 'vermutlich bereits adoptiert (Zielvariable existiert nicht mehr — kein erneuter Versuch nötig)', 'fallback' => false, 'dryRun' => $dryRun];
            }
            return $fail('Neue (Modul-)Variable existiert nicht');
        }
        if ($oldVariableID === $newVariableID) {
            return $fail('Alte und neue Variable sind identisch');
        }

        $newObj = IPS_GetObject($newVariableID);
        $newVar = IPS_GetVariable($newVariableID);
        $targetIdent = $newObj['ObjectIdent'];
        $targetType = $newVar['VariableType'];
        $targetProfile = $newVar['VariableCustomProfile'] !== '' ? $newVar['VariableCustomProfile'] : $newVar['VariableProfile'];
        $targetInstance = $this->FindOwningInstance($newVariableID);

        if ($targetIdent === '') {
            return $fail('Neue Variable hat keinen Ident — Adoption braucht einen Ident');
        }
        if ($targetInstance === 0) {
            return $fail('Zielinstanz zur neuen Variable nicht gefunden');
        }

        $targetAggregation = $this->ReadAggregationType($newVariableID); // -1 = unbekannt

        // Typ nach dem Anlegen nicht mehr änderbar — reines Umhängen (Objekt-ID
        // erhalten) geht dann technisch nicht. Rückfall auf AC_ChangeVariableID
        // wird hier TATSÄCHLICH ausgeführt, nicht nur gemeldet — ein Nutzer soll
        // für einen einzelnen Typ-Mismatch nicht manuell zum zweiten Weg
        // wechseln müssen, das "Übernahme ausführen" soll in jedem Fall das
        // technisch bestmögliche Ergebnis liefern.
        $oldType = IPS_GetVariable($oldVariableID)['VariableType'];
        if ($oldType !== $targetType) {
            $reason = 'Typ passt nicht (' . $this->TypeName($oldType) . ' vs. ' . $this->TypeName($targetType) . ')';
            if ($dryRun) {
                return ['success' => true, 'mode' => 'Rückfall', 'reason' => $reason . ' — würde auf AC_ChangeVariableID zurückfallen', 'fallback' => true, 'dryRun' => true];
            }
            $classic = $this->MigrateVariable($oldVariableID, $newVariableID, false);
            $classic['mode'] = 'Rückfall';
            $classic['reason'] = $reason . ($classic['success'] ? ' — per AC_ChangeVariableID verknüpft' : ' — Rückfall ebenfalls fehlgeschlagen: ' . $classic['reason']);
            return $classic;
        }

        if ($dryRun) {
            // Simulation: nicht-mutierende Vorhersage. Die echte Preflight-Sonde
            // läuft erst im scharfen Lauf (sie muss Objekte anlegen/löschen).
            return ['success' => true, 'mode' => 'Adoption', 'reason' => 'würde adoptiert (Ident ' . $targetIdent . ', Profil ' . ($targetProfile !== '' ? $targetProfile : '—') . ')', 'fallback' => false, 'dryRun' => true];
        }

        // --- scharfer Lauf ---
        // 1) Modul-Variable entfernen, damit der Ident frei ist.
        IPS_DeleteVariable($newVariableID);

        // 2) Preflight-Sonde: Wegwerf-Variable gleichen Typs/Idents anhängen und
        //    ApplyChanges — überlebt sie, ist der Ident im gültigen Set und die
        //    echte Variable kann gefahrlos adoptiert werden.
        if (!$this->ProbeAdoption($targetInstance, $targetIdent, $targetType)) {
            // Ident überlebt die Prune nicht → Adoption unsicher. Modul-Variable
            // neu erzeugen lassen und auf AC_ChangeVariableID zurückfallen.
            IPS_ApplyChanges($targetInstance);
            $recreated = $this->FindVarByIdentUnder($targetInstance, $targetIdent);
            if ($recreated !== 0) {
                $classic = $this->MigrateVariable($oldVariableID, $recreated, false);
                $classic['mode'] = 'Rückfall';
                return $classic;
            }
            return $fail('Preflight-Sonde entfernt — Ident nicht adoptierbar und kein Rückfallziel erzeugbar');
        }

        // 3) echte Alt-Variable adoptieren.
        IPS_SetParent($oldVariableID, $targetInstance);
        IPS_SetIdent($oldVariableID, $targetIdent);
        IPS_ApplyChanges($targetInstance);

        // 4) Sicherung gegen die destruktive Prune-Kante (trotz Sonde).
        if (!IPS_ObjectExists($oldVariableID)) {
            return $fail('KRITISCH: Alt-Variable nach ApplyChanges verschwunden — Historie verloren');
        }

        // 5) Profil des Zielmoduls nachziehen (das Modul setzt beim Wiederver-
        //    wenden keins — bewusst, Stable-Regel; hier als Migrationsaktion).
        if ($targetProfile !== '') {
            @IPS_SetVariableCustomProfile($oldVariableID, $targetProfile);
        }

        // 6) Aggregationstyp des Ziels nachziehen (z. B. Counter für Zähler-
        //    stände) — die adoptierte Variable behält sonst den der Quelle, und
        //    eine spätere Auswertung würde Zählerstände als Momentanwerte lesen.
        if ($targetAggregation >= 0) {
            $archiveID = $this->FindArchiveInstance($oldVariableID);
            if ($archiveID === 0) {
                $archiveID = $this->GetPrimaryArchive();
            }
            if ($archiveID !== 0) {
                @AC_SetAggregationType($archiveID, $oldVariableID, $targetAggregation);
                IPS_ApplyChanges($archiveID);
            }
        }

        // Links/Referenzen bleiben durch die erhaltene Objekt-ID automatisch gültig.
        return ['success' => true, 'mode' => 'Adoption', 'reason' => 'adoptiert, Objekt-ID ' . $oldVariableID . ' erhalten', 'fallback' => false, 'dryRun' => false];
    }

    // Liest den Aggregationstyp einer Variable (0 = Standard/Mittelwert,
    // 1 = Counter). -1, falls kein Archiv verfügbar oder nicht ermittelbar.
    private function ReadAggregationType(int $variableID): int
    {
        $archiveID = $this->FindArchiveInstance($variableID);
        if ($archiveID === 0) {
            $archiveID = $this->GetPrimaryArchive();
        }
        if ($archiveID === 0 || !function_exists('AC_GetAggregationType')) {
            return -1;
        }
        $type = @AC_GetAggregationType($archiveID, $variableID);
        return is_int($type) ? $type : -1;
    }

    // Erste ArchiveControl-Instanz (in der Regel gibt es genau eine).
    private function GetPrimaryArchive(): int
    {
        $list = IPS_GetInstanceListByModuleID(self::ARCHIVE_CONTROL_GUID);
        return $list === [] ? 0 : (int) $list[0];
    }

    // Hängt eine Wegwerf-Variable mit exakt $ident/$type unter $instanceID und
    // wendet die Instanz an. Überlebt sie (Objekt-ID noch da), ist der Ident im
    // gültigen Set des Zielmoduls. Sonde wird danach entfernt. Rein für den
    // scharfen Adoptionslauf, bevor die echte Variable angefasst wird.
    private function ProbeAdoption(int $instanceID, string $ident, int $type): bool
    {
        $probe = IPS_CreateVariable($type);
        IPS_SetIdent($probe, 'zz_mighub_probe_' . $probe);
        IPS_SetParent($probe, $instanceID);
        IPS_SetIdent($probe, $ident);
        IPS_ApplyChanges($instanceID);
        $survived = IPS_ObjectExists($probe);
        if ($survived) {
            IPS_DeleteVariable($probe);
        }
        return $survived;
    }

    // Läuft von einer Variable die Elternkette hinauf bis zur tragenden
    // Instanz (Variablen liegen bei Hub-Modulen unter Kategorien, nicht direkt
    // unter der Instanz). 0, falls keine Instanz gefunden.
    private function FindOwningInstance(int $objectID): int
    {
        $cursor = IPS_GetObject($objectID)['ParentID'];
        while ($cursor > 0) {
            if (IPS_InstanceExists($cursor)) {
                return $cursor;
            }
            $cursor = IPS_GetObject($cursor)['ParentID'];
        }
        return 0;
    }

    // Sucht rekursiv unter $instanceID eine Variable mit $ident.
    private function FindVarByIdentUnder(int $instanceID, string $ident): int
    {
        foreach (IPS_GetChildrenIDs($instanceID) as $childID) {
            $object = IPS_GetObject($childID);
            if ($object['ObjectType'] === 2 && $object['ObjectIdent'] === $ident) {
                return $childID;
            }
            $deeper = $this->FindVarByIdentUnder($childID, $ident);
            if ($deeper !== 0) {
                return $deeper;
            }
        }
        return 0;
    }

    private function TypeName(int $type): string
    {
        return ['Boolean', 'Integer', 'Float', 'String'][$type] ?? ('Typ ' . $type);
    }

    // Liefert die ArchiveControl-Instanz, in der $variableID geloggt wird,
    // oder 0, falls die Variable in keinem Archiv geloggt ist.
    private function FindArchiveInstance(int $variableID): int
    {
        foreach (IPS_GetInstanceListByModuleID(self::ARCHIVE_CONTROL_GUID) as $archiveID) {
            if ($this->IsVariableLogged($archiveID, $variableID)) {
                return (int) $archiveID;
            }
        }
        return 0;
    }

    private function IsVariableLogged(int $archiveID, int $variableID): bool
    {
        return (bool) AC_GetLoggingStatus($archiveID, $variableID);
    }

    // Prüft, ob im Archiv tatsächlich schon Werte für $variableID vorliegen —
    // im Unterschied zu IsVariableLogged() (Logging aktiviert/deaktiviert),
    // das eine deaktivierte Variable mit vorhandener Altdatenhistorie nicht
    // erkennen würde.
    private function HasArchiveHistory(int $archiveID, int $variableID): bool
    {
        // AC_GetLoggedValues() liefert false statt eines (leeren) Arrays und
        // erzeugt zusätzlich eine PHP-Warnung ("Logging ist für diese Variable
        // nicht verfügbar"), wenn die Variable in diesem Archiv gar nicht für
        // Logging registriert ist — das ist kein Fehlerfall, sondern bedeutet
        // schlicht: keine Historie vorhanden (genau der erwartete Fall bei
        // einem noch "jungfräulichen" Ziel). Die Warnung wird hier bewusst
        // unterdrückt, der false-Rückgabewert aber weiterhin ausgewertet.
        $values = @AC_GetLoggedValues($archiveID, $variableID, 0, 0, 1);
        if ($values === false) {
            return false;
        }
        return count($values) > 0;
    }

    // Findet alle Link-Objekte (WebFront-Verknüpfungen) in der gesamten
    // Objekthierarchie, deren Ziel $variableID ist.
    private function FindLinksToVariable(int $variableID): array
    {
        $linkIDs = [];
        foreach (IPS_GetObjectList() as $objectID) {
            $object = IPS_GetObject($objectID);
            if ($object['ObjectType'] === 6 /* Link */ && IPS_GetLink($objectID)['TargetID'] === $variableID) {
                $linkIDs[] = $objectID;
            }
        }
        return $linkIDs;
    }

    // GUID des Archive Control-Moduls (fest, von IP-Symcon vorgegeben).
    private const ARCHIVE_CONTROL_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    // --- Formular: Schritt 1+2 — Alt-Instanz wählen, Datenpunkte übernehmen ---

    // Füllt die Alt-Datenpunkt-Liste (Schritt 2) mit den Kindvariablen der in
    // Schritt 1 gewählten Alt-Instanz.
    public function LoadSourceVariables(int $sourceInstanceID): void
    {
        $linkCounts = $this->BuildLinkCountMap();
        $this->UpdateFormField('SourceVariables', 'values', json_encode($this->GetChildVariableRows($sourceInstanceID, $linkCounts)));
    }

    private function GetChildVariableRows(int $instanceID, array $linkCounts = []): array
    {
        if ($instanceID === 0 || !IPS_InstanceExists($instanceID)) {
            return [];
        }
        return $this->CollectVariableRows($instanceID, '', $linkCounts);
    }

    // Sammelt Variablen rekursiv über Unterkategorien hinweg — viele Hub-
    // Module (z. B. GoodweET) legen ihre Datenpunkte nicht als direkte
    // Kindvariablen der Instanz an, sondern gruppiert in Kategorien wie
    // "PV / MPPT", "Netz", "Batterie 1" usw. $linkCounts (VariableID => Anzahl
    // Links) ist optional — nur mit dieser Map wird die Referenzen-Spalte
    // gefüllt (siehe BuildLinkCountMap()); ohne sie bleibt sie leer, z. B. beim
    // reinen Ident-Abgleich für Zielvorschläge, wo sie nicht gebraucht wird.
    private function CollectVariableRows(int $parentID, string $pathPrefix, array $linkCounts = []): array
    {
        $rows = [];
        foreach (IPS_GetChildrenIDs($parentID) as $childID) {
            $object = IPS_GetObject($childID);
            if ($object['ObjectType'] === 2 /* Variable */) {
                $rows[] = [
                    'Selected' => false,
                    'Ident' => $object['ObjectIdent'],
                    'Name' => ($pathPrefix !== '' ? $pathPrefix . ' / ' : '') . IPS_GetName($childID),
                    'VariableID' => $childID,
                    'References' => $linkCounts === [] ? '' : $this->DescribeReferences($childID, $linkCounts),
                ];
            } elseif ($object['ObjectType'] === 0 /* Category */) {
                $rows = array_merge(
                    $rows,
                    $this->CollectVariableRows($childID, ($pathPrefix !== '' ? $pathPrefix . ' / ' : '') . IPS_GetName($childID), $linkCounts)
                );
            }
        }
        return $rows;
    }

    // Baut in einem einzigen Durchlauf über den gesamten Objektbaum eine Map
    // VariableID => Anzahl Links, die auf sie zeigen — deutlich schneller als
    // pro Variable erneut den kompletten Baum zu durchsuchen (FindLinksToVariable
    // würde das bei z. B. 100 Datenpunkten 100-mal tun).
    private function BuildLinkCountMap(): array
    {
        $map = [];
        foreach (IPS_GetObjectList() as $objectID) {
            $object = IPS_GetObject($objectID);
            if ($object['ObjectType'] === 6 /* Link */) {
                $targetID = IPS_GetLink($objectID)['TargetID'];
                $map[$targetID] = ($map[$targetID] ?? 0) + 1;
            }
        }
        return $map;
    }

    // Kurzbeschreibung der Referenzen einer Variable für die Einschätzung vor
    // der Migration: ob sie archiviert wird und wie viele WebFront-Links
    // (Kacheln/Verknüpfungen) auf sie zeigen.
    private function DescribeReferences(int $variableID, array $linkCounts): string
    {
        $parts = [];
        $archiveID = $this->FindArchiveInstance($variableID);
        $parts[] = $archiveID !== 0 ? 'archiviert' : 'nicht archiviert';
        $linkCount = $linkCounts[$variableID] ?? 0;
        $parts[] = $linkCount . ($linkCount === 1 ? ' Verknüpfung' : ' Verknüpfungen');
        return implode(', ', $parts);
    }

    // Übernimmt die in Schritt 2 angehakten Alt-Datenpunkte als neue Zeilen in
    // die Migrationsliste (Schritt 3). Ist die Neu-Instanz aus Schritt 1
    // gesetzt, wird als Zielvorschlag die Variable mit demselben Ident dort
    // vorbelegt (nur ein Vorschlag, keine automatische Zuordnung — Status
    // macht das kenntlich und der Nutzer muss ihn trotzdem prüfen/bestätigen).
    // Ohne Treffer bleibt das Ziel leer und wird über den durchsuchbaren
    // SelectVariable-Dialog je Zeile gewählt.
    public function AddSourceVariablesToMigrations($sourceVariables, $migrations, int $targetInstanceID = 0, int $sourceInstanceID = 0): array
    {
        $sourceVariables = $this->NormalizeFormList($sourceVariables);
        $migrations = $this->NormalizeFormList($migrations);
        $linkCounts = $this->BuildLinkCountMap();

        $targetByIdent = [];
        foreach ($this->GetChildVariableRows($targetInstanceID) as $targetRow) {
            $targetByIdent[$targetRow['Ident']] = (int) $targetRow['VariableID'];
        }

        // Bekannte Ident-Übersetzung für dieses Quellmodul (falls hinterlegt) —
        // greift nur, wenn der direkte Ident-Abgleich nichts findet. Wird
        // beim ersten Gebrauch dieser Instanz-Kombination einmal aufgebaut.
        $knownTranslation = $this->GetKnownIdentTranslation($sourceInstanceID);

        // array_column() setzt Arrays oder Objekte mit öffentlichen Properties
        // voraus; einzelne Zeilen können hier aber ArrayAccess-Objekte sein —
        // deshalb per Schleife statt array_column() auslesen.
        $existingOldIDs = [];
        foreach ($migrations as $migrationRow) {
            $existingOldIDs[] = (int) $migrationRow['OldVariableID'];
        }

        $selected = array_values(array_filter($sourceVariables, fn ($r) => !empty($r['Selected'])));
        $whTwins = $this->DetectWhTwins($selected);

        foreach ($selected as $row) {
            $oldID = (int) $row['VariableID'];
            if (in_array($oldID, $existingOldIDs, true)) {
                continue;
            }
            $suggestedNewID = $targetByIdent[$row['Ident']] ?? 0;
            $viaKnownTranslation = false;
            if ($suggestedNewID === 0 && $knownTranslation !== null) {
                $translatedIdent = $knownTranslation($row['Ident']);
                if ($translatedIdent !== null && isset($targetByIdent[$translatedIdent])) {
                    $suggestedNewID = $targetByIdent[$translatedIdent];
                    $viaKnownTranslation = true;
                }
            }
            if (isset($whTwins[$oldID])) {
                // Wh-Variante mit vorhandenem kWh-Zwilling: kWh bevorzugen, den
                // Wh-Eintrag markieren (nicht nur warnen). Ziel bewusst leer, um
                // versehentliches Migrieren der um Faktor 1000 versetzten Reihe
                // zu verhindern.
                $status = 'Wh-Zwilling → kWh-Variante »' . $whTwins[$oldID] . '« bevorzugen';
                $suggestedNewID = 0;
            } elseif ($viaKnownTranslation) {
                $status = 'Vorschlag anhand bekannter Fremdmodul-Übersetzung — bitte prüfen';
            } elseif ($suggestedNewID !== 0) {
                $status = 'Vorschlag anhand Ident — bitte prüfen';
            } elseif ($targetInstanceID !== 0) {
                // Ident wurde in der GESAMTEN Zielinstanz gesucht (nicht nur
                // unter den in Schritt 2 sichtbaren Zeilen) und nicht gefunden —
                // das neue Modul kennt diesen Datenpunkt wahrscheinlich gar
                // nicht. Klar von "einfach noch nicht zugeordnet" abgrenzen,
                // damit vor dem späteren Löschen der Alt-Instanz nicht
                // übersehen wird, dass hier keine automatische Zuordnung
                // möglich ist — der Datenpunkt bleibt sonst auf der Alt-Instanz
                // mitsamt Historie zurück.
                $status = 'Kein Ziel im neuen Modul gefunden — bleibt sonst auf der Alt-Instanz';
            } else {
                $status = 'Ziel wählen';
            }
            $migrations[] = [
                'OldVariableID' => $oldID,
                'NewVariableID' => $suggestedNewID,
                'Status' => $status,
                'References' => $this->DescribeReferences($oldID, $linkCounts),
            ];
        }
        $this->UpdateFormField('Migrations', 'values', json_encode($migrations));
        return $migrations;
    }

    // Bekannte Ident-Übersetzungen für verbreitete Fremdmodule, deren Idents
    // nichts mit unseren eigenen Suite-Modulen gemein haben. Kein direkter
    // Ident-Abgleich möglich (z. B. go-e-Fremdmodul camelCase vs. ChargerHub
    // snake_case) — verifiziert per Quellcode-Abgleich und Live-Wertevergleich
    // (siehe CLAUDE.md, ChargerHub-Testfall #48730/#11507, 30 Paare, 03.08.2026).
    // Liefert eine Übersetzungsfunktion (Ident → Ident|null) oder null, wenn
    // für die Quellinstanz kein Modul mit hinterlegter Tabelle erkannt wurde.
    private function GetKnownIdentTranslation(int $sourceInstanceID): ?\Closure
    {
        if ($sourceInstanceID === 0 || !IPS_InstanceExists($sourceInstanceID)) {
            return null;
        }
        $moduleID = IPS_GetInstance($sourceInstanceID)['ModuleInfo']['ModuleID'] ?? '';

        // go-e Charger (bis HW Rev. v2), github.com/IPSCoyote/GO-eCharger, → ChargerHub.
        if ($moduleID === '{B4624A42-F80A-4975-B692-7FB4D06CC805}') {
            $table = [
                'status' => 'state',
                'powerToCarTotal' => 'power',
                'powerToCarLineL1' => 'power_l1',
                'powerToCarLineL2' => 'power_l2',
                'powerToCarLineL3' => 'power_l3',
                'ampToCarLineL1' => 'current_l1',
                'ampToCarLineL2' => 'current_l2',
                'ampToCarLineL3' => 'current_l3',
                'energyTotal' => 'energy_total',
                'energyLoadCycle' => 'energy_session',
                'serialID' => 'dev_serial',
                'error' => 'dev_error',
                'adapterAttached' => 'adapter',
                'unlockedByRFID' => 'unlocked_by',
                'cableUnlockMode' => 'ctl_cable_lock',
                'accessControl' => 'ctl_access',
                'cableCapability' => 'cable_current',
                'supplyLineL1' => 'voltage_l1',
                'supplyLineL2' => 'voltage_l2',
                'supplyLineL3' => 'voltage_l3',
                'supplyLineN' => 'voltage_n',
            ];
            return function (string $ident) use ($table): ?string {
                if (isset($table[$ident])) {
                    return $table[$ident];
                }
                // energyChargedCard{N} (1-basiert) -> card{N-1}_energy (0-basiert).
                if (preg_match('/^energyChargedCard(\d+)$/', $ident, $m)) {
                    return 'card' . ((int) $m[1] - 1) . '_energy';
                }
                return null;
            };
        }

        return null;
    }

    // Erkennt Wh/kWh-Zwillinge unter den gewählten Alt-Datenpunkten: dieselbe
    // physikalische Größe liegt oft zweimal vor, um Faktor 1000 versetzt (z. B.
    // »…_Wh« und »…_kWh«). Liefert Map Wh-VariableID => Name der kWh-Variante,
    // damit der Aufrufer die Wh-Variante markieren und die kWh-Variante
    // bevorzugen kann (belegter Fall aus einer MeterHub-Analyse).
    private function DetectWhTwins(array $rows): array
    {
        $byBase = [];
        foreach ($rows as $r) {
            $ident = strtolower((string) ($r['Ident'] ?? ''));
            $name = (string) ($r['Name'] ?? '');
            $unit = null;
            $base = null;
            if (preg_match('/^(.*?)[_\- ]*kwh$/', $ident, $m)) {
                $unit = 'kwh';
                $base = $m[1];
            } elseif (preg_match('/^(.*?)[_\- ]*wh$/', $ident, $m)) {
                $unit = 'wh';
                $base = $m[1];
            } elseif (stripos($name, 'kwh') !== false) {
                $unit = 'kwh';
                $base = strtolower(preg_replace('/\(?\s*k?wh\s*\)?/i', '', $name));
            } elseif (preg_match('/(\(|\b)wh(\)|\b)/i', $name)) {
                $unit = 'wh';
                $base = strtolower(preg_replace('/\(?\s*k?wh\s*\)?/i', '', $name));
            }
            if ($unit === null) {
                continue;
            }
            $base = trim($base);
            $byBase[$base][$unit] = ['id' => (int) $r['VariableID'], 'name' => $name];
        }
        $twins = [];
        foreach ($byBase as $pair) {
            if (isset($pair['wh'], $pair['kwh'])) {
                $twins[$pair['wh']['id']] = $pair['kwh']['name'];
            }
        }
        return $twins;
    }

    // Leert die Migrationsliste komplett — Alternative zum einzelnen Löschen
    // jeder Zeile per Papierkorb-Symbol.
    public function ClearMigrations(): void
    {
        $this->UpdateFormField('Migrations', 'values', json_encode([]));
    }

    // Setzt die Auswahl-Checkbox aller Zeilen der Alt-Datenpunkt-Liste auf
    // $select — Komfortfunktion, damit man bei vielen Datenpunkten nicht jede
    // Zeile einzeln ankreuzen muss.
    public function SetAllSourceVariablesSelected($sourceVariables, bool $select): void
    {
        $sourceVariables = $this->NormalizeFormList($sourceVariables);
        foreach ($sourceVariables as &$row) {
            $row['Selected'] = $select;
        }
        unset($row);
        $this->UpdateFormField('SourceVariables', 'values', json_encode($sourceVariables));
    }

    // Kreuzt nur die Datenpunkte an, bei denen Migration überhaupt etwas
    // bringt: archiviert (Historie ginge sonst verloren) oder verknüpft
    // (WebFront-Kacheln würden ins Leere zeigen). Alles andere legt das neue
    // Modul ohnehin frisch an — dafür braucht es keine Übernahme. Reduziert
    // bei Fremdmodulen mit vielen Datenpunkten (z. B. go-e-Charger, 60
    // Idents, aber oft nur eine Handvoll mit echter Historie) die Auswahl auf
    // das Wesentliche, statt "alles ankreuzen und Ballast mitschleppen".
    public function SelectOnlyReferencedSourceVariables($sourceVariables): void
    {
        $sourceVariables = $this->NormalizeFormList($sourceVariables);
        $linkCounts = $this->BuildLinkCountMap();
        foreach ($sourceVariables as &$row) {
            $variableID = (int) $row['VariableID'];
            $hasArchive = $this->FindArchiveInstance($variableID) !== 0;
            $hasLinks = ($linkCounts[$variableID] ?? 0) > 0;
            $row['Selected'] = $hasArchive || $hasLinks;
        }
        unset($row);
        $this->UpdateFormField('SourceVariables', 'values', json_encode($sourceVariables));
    }

    // Bündelt den gesamten Vorbereitungsablauf (Schritt 2+3) in einem Klick:
    // Alt-Datenpunkte laden → nur archivierte/verknüpfte auswählen → in die
    // Migrationsliste übernehmen (mit Ident-Vorschlag/-Übersetzung) →
    // Skript-/Event-Referenzen suchen → Übernahme simulieren. Ersetzt die
    // bisher nötige Klickfolge über vier Formularabschnitte für den
    // Normalfall, in dem man ohnehin nur die archivierten/verknüpften
    // Datenpunkte migrieren will. Führt nichts aus — endet in der Simulation,
    // damit der Nutzer das Ergebnis vor der echten Ausführung noch sieht.
    // Baut die Migrationsliste dabei komplett neu auf (verwirft manuell
    // gepflegte Einträge) — gedacht als Einstieg in einen frischen Lauf, nicht
    // zum Ergänzen einer bereits bearbeiteten Liste.
    public function PrepareAllAdoptions(int $sourceInstanceID, int $targetInstanceID): void
    {
        $linkCounts = $this->BuildLinkCountMap();
        $sourceVariables = $this->GetChildVariableRows($sourceInstanceID, $linkCounts);
        foreach ($sourceVariables as &$row) {
            $variableID = (int) $row['VariableID'];
            $hasArchive = $this->FindArchiveInstance($variableID) !== 0;
            $hasLinks = ($linkCounts[$variableID] ?? 0) > 0;
            $row['Selected'] = $hasArchive || $hasLinks;
        }
        unset($row);
        $this->UpdateFormField('SourceVariables', 'values', json_encode($sourceVariables));

        $migrations = $this->AddSourceVariablesToMigrations($sourceVariables, [], $targetInstanceID, $sourceInstanceID);
        $this->ScanReferences($migrations);
        $this->SimulateAdoptions($migrations);
    }

    // Alternative zum Formular: kompletter Übernahme-Ablauf in einem einzigen
    // Aufruf für ein eigenes Konsolen-Skript, ohne offene Formular-Session.
    // UpdateFormField() (in AddSourceVariablesToMigrations()/SimulateAdoptions()/
    // RunAdoptions()) wirkt ohne offenes Formular nicht, liefert aber auch
    // keinen Fehler — die eigentlichen Ergebnisse kommen hier stattdessen als
    // Rückgabewert, den ein Skript direkt ausgeben kann. $execute=false ist
    // der Probelauf (nichts wird geschrieben); erst bei $execute=true wird
    // wirklich übernommen (inkl. Migrations-Log-Eintrag).
    public function RunFullAdoption(int $sourceInstanceID, int $targetInstanceID, bool $execute): array
    {
        $linkCounts = $this->BuildLinkCountMap();
        $sourceVariables = $this->GetChildVariableRows($sourceInstanceID, $linkCounts);
        foreach ($sourceVariables as &$row) {
            $variableID = (int) $row['VariableID'];
            $hasArchive = $this->FindArchiveInstance($variableID) !== 0;
            $hasLinks = ($linkCounts[$variableID] ?? 0) > 0;
            $row['Selected'] = $hasArchive || $hasLinks;
        }
        unset($row);

        $migrations = $this->AddSourceVariablesToMigrations($sourceVariables, [], $targetInstanceID, $sourceInstanceID);
        [$migrations, $results] = $this->ProcessAdoptions($migrations, !$execute);

        if ($execute) {
            $this->AppendToMigrationLog(array_map(fn ($r) => $r + ['Mode' => 'Übernahme'], $results));
        }

        return [
            'sourceCount' => count($sourceVariables),
            'selectedCount' => count(array_filter($sourceVariables, fn ($r) => !empty($r['Selected']))),
            'migrations' => $migrations,
            'results' => $results,
        ];
    }

    // --- Referenz-Scan: Skripte/Events, die die Alt-Variable evtl. fest per
    // ID referenzieren. AC_ChangeVariableID und die Link-Umhängung erfassen
    // nur Archiv und WebFront-Links — Skriptcode, Event-Trigger, IPSView-
    // Konfigurationen, Workflows oder Properties fremder Module bleiben davon
    // unberührt. Das lässt sich nicht gefahrlos automatisch umschreiben, daher
    // hier nur ein rein lesender Scan, dessen Treffer der Nutzer manuell
    // prüft und abhakt.

    // Durchsucht alle Migrationspaare nach Skript-/Event-Referenzen auf die
    // jeweilige Alt-Variable und ergänzt neue Funde in den persistenten
    // Abhak-Listen (ScriptChecks/EventChecks) — bereits vorhandene Einträge
    // (inkl. bereits gesetztem "Erledigt"-Haken) bleiben unangetastet, damit
    // ein erneuter Scan den Fortschritt nicht zurücksetzt.
    public function ScanReferences($migrations): void
    {
        $migrations = $this->NormalizeFormList($migrations);
        // Bestand aus den Attributen lesen (nicht aus dem Formular): dort
        // liegen die Zeilen vollständig, inkl. der Anzeige-Spalten, die der
        // Client beim Übertragen von Listen-Werten verwirft.
        $scriptChecks = json_decode($this->ReadAttributeString('ScriptChecks'), true);
        $eventChecks = json_decode($this->ReadAttributeString('EventChecks'), true);

        $existingScriptKeys = [];
        foreach ($scriptChecks as $row) {
            $existingScriptKeys[$row['OldVariableID'] . '|' . $row['ObjectID']] = true;
        }
        $existingEventKeys = [];
        foreach ($eventChecks as $row) {
            $existingEventKeys[$row['OldVariableID'] . '|' . $row['ObjectID']] = true;
        }

        foreach ($migrations as $migrationRow) {
            $oldID = (int) $migrationRow['OldVariableID'];
            if ($oldID === 0 || !IPS_VariableExists($oldID)) {
                continue;
            }
            $oldName = IPS_GetName($oldID);

            foreach ($this->FindScriptReferences($oldID) as $scriptID) {
                $key = $oldID . '|' . $scriptID;
                if (isset($existingScriptKeys[$key])) {
                    continue;
                }
                $existingScriptKeys[$key] = true;
                $scriptChecks[] = [
                    'OldVariableID' => $oldID,
                    'OldName' => $oldName,
                    'ObjectID' => $scriptID,
                    'Done' => false,
                ];
            }

            foreach ($this->FindEventReferences($oldID) as $eventID) {
                $key = $oldID . '|' . $eventID;
                if (isset($existingEventKeys[$key])) {
                    continue;
                }
                $existingEventKeys[$key] = true;
                $eventChecks[] = [
                    'OldVariableID' => $oldID,
                    'OldName' => $oldName,
                    'ObjectID' => $eventID,
                    'Done' => false,
                ];
            }
        }

        $this->WriteAttributeString('ScriptChecks', json_encode($scriptChecks));
        $this->WriteAttributeString('EventChecks', json_encode($eventChecks));
        $this->UpdateFormField('ScriptChecks', 'values', json_encode($scriptChecks));
        $this->UpdateFormField('EventChecks', 'values', json_encode($eventChecks));
    }

    // Textsuche (keine Codeanalyse!) nach der Alt-Variablen-ID als eigenständige
    // Zahl im Skriptquelltext — liefert auch False Positives (z. B. wenn die
    // Zahl zufällig anderswo vorkommt), ist aber die einzige generische
    // Möglichkeit, feste ID-Referenzen in PHP-Skripten überhaupt aufzuspüren.
    private function FindScriptReferences(int $variableID): array
    {
        $matches = [];
        foreach (IPS_GetScriptList() as $scriptID) {
            $content = @IPS_GetScriptContent($scriptID);
            if ($content === false) {
                continue;
            }
            if (preg_match('/(?<!\d)' . $variableID . '(?!\d)/', $content)) {
                $matches[] = $scriptID;
            }
        }
        return $matches;
    }

    // Events, deren Auslöser (TriggerVariableID) die Alt-Variable ist.
    private function FindEventReferences(int $variableID): array
    {
        $matches = [];
        foreach (IPS_GetEventList() as $eventID) {
            $event = IPS_GetEvent($eventID);
            if (isset($event['TriggerVariableID']) && (int) $event['TriggerVariableID'] === $variableID) {
                $matches[] = $eventID;
            }
        }
        return $matches;
    }

    // Form-Felder vom Typ List übergibt IP-Symcon dem Handler als IPSList-
    // Objekt, nicht als PHP-Array — hier auf ein gewöhnliches Array normieren.
    private function NormalizeFormList($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        // IPSList implementiert Traversable (foreach funktioniert), aber
        // json_encode() liefert dafür kein brauchbares Array — die Klasse hat
        // keine öffentlichen Properties und ist nicht JsonSerializable, das
        // Ergebnis wäre ein leeres Objekt. iterator_to_array() greift stattdessen
        // direkt auf die Zeilen zu.
        if ($value instanceof Traversable) {
            return iterator_to_array($value);
        }
        return (array) $value;
    }

    // --- Formular: Schritt 3 — Simulation, Migration, Plausibilitätsprüfung ---

    // Simuliert alle Paare der Migrationsliste (Dry-Run): durchläuft dieselben
    // Prüfungen wie eine echte Migration, schreibt aber nichts. Damit lässt
    // sich vorab sehen, welche Paare fehlschlagen würden (z. B. Ziel bereits
    // archiviert), bevor der unwiderrufliche Schritt ausgeführt wird. Braucht
    // bewusst keine Confirmed-Checkbox, weil dabei nichts verändert wird.
    public function SimulateMigrations($migrations): void
    {
        [$migrations, $results] = $this->ProcessMigrations($this->NormalizeFormList($migrations), true);
        $this->UpdateFormField('Migrations', 'values', json_encode($migrations));
        $this->UpdateFormField('Results', 'values', json_encode($results));
    }

    // Führt alle Paare der Migrationsliste wirklich aus. Der Button im
    // Formular hat zusätzlich einen nativen Bestätigungsdialog (form.json
    // "confirm"); die Confirmed-Checkbox ist ein zweites, unabhängiges
    // Sicherheits-Gate, weil der Vorgang Archivhistorie unwiderruflich
    // überträgt.
    public function RunMigrations(bool $confirmed, $migrations): void
    {
        $migrations = $this->NormalizeFormList($migrations);

        if (!$confirmed) {
            $this->UpdateFormField('Results', 'values', json_encode([[
                'OldName' => '',
                'NewName' => '',
                'Success' => 'nein',
                'Reason' => 'Abgebrochen: Bestätigungsschalter nicht gesetzt',
                'OldValue' => '',
                'NewValue' => '',
                'Plausible' => '-',
            ]]));
            return;
        }

        [$migrations, $results] = $this->ProcessMigrations($migrations, false);
        $this->UpdateFormField('Migrations', 'values', json_encode($migrations));
        $this->UpdateFormField('Results', 'values', json_encode($results));
        $this->AppendToMigrationLog(array_map(fn ($r) => $r + ['Mode' => 'Verknüpfen'], $results));
    }

    // Gemeinsame Schleife für RunMigrations() und SimulateMigrations() — nur
    // $dryRun unterscheidet, ob MigrateVariable() tatsächlich schreibt.
    private function ProcessMigrations(array $migrations, bool $dryRun): array
    {
        $results = [];
        foreach ($migrations as &$row) {
            $oldID = (int) $row['OldVariableID'];
            $newID = (int) $row['NewVariableID'];
            $oldName = IPS_VariableExists($oldID) ? IPS_GetName($oldID) : (string) $oldID;
            $newName = IPS_VariableExists($newID) ? IPS_GetName($newID) : (string) $newID;

            $result = $this->MigrateVariable($oldID, $newID, $dryRun);
            $statusPrefix = $dryRun ? '[Simulation] ' : '';
            $row['Status'] = $statusPrefix . ($result['success'] ? ($dryRun ? 'würde migriert' : 'migriert') : ('Fehler: ' . $result['reason']));

            // Plausibilitätsprüfung: aktueller Wert alt gegen neu vergleichen.
            // Das ersetzt keine fachliche Prüfung (z. B. Wh/kWh-Zwillinge,
            // Vorzeichenkonvention), zeigt aber offensichtliche Fehlzuordnungen.
            // Im Dry-Run ist ein Unterschied normal (Archiv wurde ja noch nicht
            // übertragen) und daher kein Plausibilitätsproblem.
            $oldValue = IPS_VariableExists($oldID) ? GetValueFormatted($oldID) : '';
            $newValue = IPS_VariableExists($newID) ? GetValueFormatted($newID) : '';
            $plausible = '-';
            if ($result['success']) {
                $plausible = $dryRun ? 'entfällt (Simulation)' : (($oldValue === $newValue) ? 'ja' : 'bitte prüfen');
            }

            $results[] = [
                'OldName' => $oldName,
                'NewName' => $newName,
                'Success' => $result['success'] ? 'ja' : 'nein',
                'Reason' => $statusPrefix . $result['reason'],
                'OldValue' => $oldValue,
                'NewValue' => $newValue,
                'Plausible' => $plausible,
            ];
        }
        unset($row);

        return [$migrations, $results];
    }

    // Simuliert den Adoptions-Lauf (nicht-mutierend): sagt je Paar voraus, ob
    // adoptiert würde oder auf AC_ChangeVariableID zurückgefallen wird.
    public function SimulateAdoptions($migrations): void
    {
        [$migrations, $results] = $this->ProcessAdoptions($this->NormalizeFormList($migrations), true);
        $this->UpdateFormField('Migrations', 'values', json_encode($migrations));
        $this->UpdateFormField('Results', 'values', json_encode($results));
    }

    // Führt den Adoptions-Lauf wirklich aus (Preflight-Sonde + Profil-Nachzug
    // + AC_ChangeVariableID-Rückfall). Dreifach abgesichert: der bestehende
    // Bestätigungsschalter, das native confirm() des Buttons UND der eigene
    // Risiko-Schalter $riskAcknowledged, der die destruktive Prune-Kante
    // ausdrücklich benennt — die Übernahme funktioniert technisch mit jedem
    // Zielmodul (die Preflight-Sonde sichert generisch ab), aber nur unsere
    // eigenen Suite-Module haben wir selbst gegen dieses Verhalten getestet.
    // Bei Fremdmodulen ist dieser Schalter die "Einverständniserklärung", auf
    // die der Nutzer bewusst eingeht.
    public function RunAdoptions(bool $confirmed, bool $riskAcknowledged, $migrations): void
    {
        $migrations = $this->NormalizeFormList($migrations);
        if (!$confirmed || !$riskAcknowledged) {
            $this->UpdateFormField('Results', 'values', json_encode([[
                'OldName' => '', 'NewName' => '', 'Success' => 'nein',
                'Reason' => 'Abgebrochen: ' . (!$confirmed ? 'Bestätigungsschalter' : 'Risiko-Schalter (Prune-Kante)') . ' nicht gesetzt',
                'OldValue' => '', 'NewValue' => '', 'Plausible' => '-',
            ]]));
            return;
        }

        // Quell-Instanz je Alt-Variable VOR der Übernahme merken — danach hat
        // sich der Parent der Variable ja gerade geändert.
        $sourceInstances = [];
        foreach ($migrations as $row) {
            $oldID = (int) $row['OldVariableID'];
            if (IPS_VariableExists($oldID)) {
                $src = $this->FindOwningInstance($oldID);
                if ($src !== 0) {
                    $sourceInstances[$src] = true;
                }
            }
        }

        [$migrations, $results] = $this->ProcessAdoptions($migrations, false);
        $this->UpdateFormField('Migrations', 'values', json_encode($migrations));
        $this->AppendToMigrationLog(array_map(fn ($r) => $r + ['Mode' => 'Übernahme'], $results));

        // Automatischer Hinweis: Quell-Instanzen, die durch diesen Lauf keine
        // Kindvariablen mehr haben, sind vollständig übernommen — direkt zur
        // Lösch-Prüfung überleiten statt den Nutzer manuell suchen zu lassen.
        $emptied = [];
        foreach (array_keys($sourceInstances) as $instanceID) {
            if (IPS_InstanceExists($instanceID) && $this->CollectVariableIDs($instanceID) === []) {
                $emptied[] = $instanceID;
            }
        }
        if ($emptied !== []) {
            $names = array_map(fn ($id) => '»' . IPS_GetName($id) . '« (#' . $id . ')', $emptied);
            $results[] = [
                'OldName' => '',
                'NewName' => '',
                'Success' => 'ja',
                'Reason' => '💡 Alt-Instanz(en) jetzt leer, alle Datenpunkte übernommen — bitte prüfen & löschen: ' . implode(', ', $names) . '. „Zu prüfende Instanz" im Bereich „Instanz analysieren & löschen" wurde vorbelegt.',
                'OldValue' => '',
                'NewValue' => '',
                'Plausible' => '-',
            ];
            $this->UpdateFormField('AnalyzeInstanceID', 'value', $emptied[0]);
            $this->UpdateFormField('InstanceReport', 'values', json_encode($this->BuildInstanceReport($emptied[0])));
        }

        $this->UpdateFormField('Results', 'values', json_encode($results));
    }

    // Gemeinsame Schleife für RunAdoptions()/SimulateAdoptions().
    private function ProcessAdoptions(array $migrations, bool $dryRun): array
    {
        $results = [];
        foreach ($migrations as &$row) {
            $oldID = (int) $row['OldVariableID'];
            $newID = (int) $row['NewVariableID'];
            $oldName = IPS_VariableExists($oldID) ? IPS_GetName($oldID) : (string) $oldID;
            $newName = IPS_VariableExists($newID) ? IPS_GetName($newID) : (string) $newID;

            $result = $this->AdoptVariable($oldID, $newID, $dryRun);
            $prefix = $dryRun ? '[Simulation] ' : '';
            if ($result['success']) {
                $row['Status'] = $prefix . ($dryRun ? 'würde übernommen (Adoption)' : 'übernommen (Adoption)');
            } elseif (!empty($result['fallback'])) {
                $row['Status'] = $prefix . 'Rückfall AC_ChangeVariableID: ' . $result['reason'];
            } else {
                $row['Status'] = $prefix . 'Fehler: ' . $result['reason'];
            }

            $results[] = [
                'OldName' => $oldName,
                'NewName' => $newName,
                'Success' => $result['success'] ? 'ja' : 'nein',
                'Reason' => $prefix . ($result['mode'] !== '-' ? '[' . $result['mode'] . '] ' : '') . $result['reason'],
                'OldValue' => IPS_VariableExists($oldID) ? GetValueFormatted($oldID) : '',
                'NewValue' => (!$dryRun && IPS_VariableExists($oldID)) ? GetValueFormatted($oldID) : (IPS_VariableExists($newID) ? GetValueFormatted($newID) : ''),
                'Plausible' => $result['success'] ? ($dryRun ? 'entfällt (Simulation)' : 'Objekt-ID erhalten') : '-',
            ];
        }
        unset($row);
        return [$migrations, $results];
    }

    // --- Instanz analysieren & (nach Prüfung) löschen ---------------------
    //
    // Vor dem Löschen einer Alt-Instanz alle Abhängigkeiten sichtbar machen.
    // Wichtigste Erkenntnis aus der Praxis: eine Instanz kann ein Transport-
    // Knoten sein (Splitter/Gateway/Socket), an dem weitere Instanzen als
    // Verbindungskinder hängen — die zu löschen reißt einen ganzen Gerätebaum
    // vom Bus. Solche Struktur-Abhängigkeiten blockieren das Löschen hart;
    // die weicheren Referenzen (Verknüpfungen, Skripte, Ereignisse, fremde
    // Modul-Einstellungen, eigene Profile) werden nur als Warnung gemeldet.

    public function AnalyzeInstance(int $instanceID): void
    {
        $this->UpdateFormField('InstanceReport', 'values', json_encode($this->BuildInstanceReport($instanceID)));
    }

    // Baut den Abhängigkeits-Report als Zeilenliste. Jede Zeile:
    // Kategorie / Fund / Objekt(-ID) / Schweregrad ("blockiert" | "Warnung").
    private function BuildInstanceReport(int $instanceID): array
    {
        $rows = [];
        if ($instanceID === 0 || !IPS_InstanceExists($instanceID)) {
            return [[
                'Category' => 'Instanz',
                'Detail' => 'Instanz existiert nicht',
                'ObjectID' => $instanceID,
                'Severity' => 'blockiert',
            ]];
        }

        // 1) Verbindungskinder (Transport-Abhängigkeit) — hart blockierend.
        foreach ($this->FindConnectionChildren($instanceID) as $childID) {
            $rows[] = [
                'Category' => 'Verbindungskind (Transport)',
                'Detail' => IPS_GetName($childID) . ' hängt als Instanz an diesem Verbindungsknoten',
                'ObjectID' => $childID,
                'Severity' => 'blockiert',
            ];
        }

        // Kindvariablen der Instanz einmalig sammeln (für 2–4 wiederverwendet).
        $variableIDs = $this->CollectVariableIDs($instanceID);

        // 2) WebFront-Verknüpfungen + Archivierung je Kindvariable.
        $linkCounts = $this->BuildLinkCountMap();
        foreach ($variableIDs as $variableID) {
            $linkCount = $linkCounts[$variableID] ?? 0;
            if ($linkCount > 0) {
                $rows[] = [
                    'Category' => 'Verknüpfung',
                    'Detail' => $linkCount . ($linkCount === 1 ? ' Verknüpfung zeigt' : ' Verknüpfungen zeigen') . ' auf »' . IPS_GetName($variableID) . '«',
                    'ObjectID' => $variableID,
                    'Severity' => 'Warnung',
                ];
            }
            if ($this->FindArchiveInstance($variableID) !== 0) {
                $rows[] = [
                    'Category' => 'Archiv',
                    'Detail' => '»' . IPS_GetName($variableID) . '« wird noch archiviert (Historie ginge verloren)',
                    'ObjectID' => $variableID,
                    'Severity' => 'Warnung',
                ];
            }
        }

        // 3) Skripte/Ereignisse, die eine Kindvariable per ID referenzieren.
        foreach ($variableIDs as $variableID) {
            foreach ($this->FindScriptReferences($variableID) as $scriptID) {
                $rows[] = [
                    'Category' => 'Skript',
                    'Detail' => 'Skript »' . IPS_GetName($scriptID) . '« nennt die ID von »' . IPS_GetName($variableID) . '« (Textfund, ggf. Fehltreffer)',
                    'ObjectID' => $scriptID,
                    'Severity' => 'Warnung',
                ];
            }
            foreach ($this->FindEventReferences($variableID) as $eventID) {
                $rows[] = [
                    'Category' => 'Ereignis',
                    'Detail' => 'Ereignis »' . IPS_GetName($eventID) . '« wird durch »' . IPS_GetName($variableID) . '« ausgelöst',
                    'ObjectID' => $eventID,
                    'Severity' => 'Warnung',
                ];
            }
        }

        // 4) Fremde Instanzen, die eine Kindvariable in ihrer Konfiguration
        //    als Property halten (Textsuche über die Konfigurations-JSON).
        foreach ($this->FindForeignConfigReferences($instanceID, $variableIDs) as $foreignID => $variableID) {
            $rows[] = [
                'Category' => 'Fremde Instanz',
                'Detail' => 'Instanz »' . IPS_GetName($foreignID) . '« nennt in ihrer Konfiguration die ID von »' . IPS_GetName($variableID) . '«',
                'ObjectID' => $foreignID,
                'Severity' => 'Warnung',
            ];
        }

        // 5) Eigene Profile der Kindvariablen (nur benutzerdefinierte, die nach
        //    dem Löschen verwaist zurückblieben).
        foreach ($this->FindCustomProfiles($variableIDs) as $profileName) {
            $rows[] = [
                'Category' => 'Profil',
                'Detail' => 'Benutzerprofil »' . $profileName . '« bliebe nach dem Löschen verwaist',
                'ObjectID' => 0,
                'Severity' => 'Warnung',
            ];
        }

        if ($rows === []) {
            $rows[] = [
                'Category' => 'Ergebnis',
                'Detail' => 'Keine Abhängigkeiten gefunden — Löschen erscheint gefahrlos',
                'ObjectID' => $instanceID,
                'Severity' => 'ok',
            ];
        }
        return $rows;
    }

    // Löscht die Instanz — aber nur, wenn keine Transport-Abhängigkeit besteht
    // (Verbindungskinder). Weiche Referenzen werden per Bestätigungsschalter
    // überstimmt; der native confirm-Dialog der Schaltfläche ist zusätzlich
    // vorgeschaltet.
    public function DeleteInstanceChecked(int $instanceID, bool $confirmed): void
    {
        if ($instanceID === 0 || !IPS_InstanceExists($instanceID)) {
            $this->UpdateFormField('InstanceReport', 'values', json_encode([[
                'Category' => 'Löschen', 'Detail' => 'Abgebrochen: Instanz existiert nicht', 'ObjectID' => $instanceID, 'Severity' => 'blockiert',
            ]]));
            return;
        }
        $connectionChildren = $this->FindConnectionChildren($instanceID);
        if ($connectionChildren !== []) {
            $this->UpdateFormField('InstanceReport', 'values', json_encode([[
                'Category' => 'Löschen',
                'Detail' => 'Blockiert: ' . count($connectionChildren) . ' Instanz(en) hängen als Verbindungskinder daran — erst umhängen/entfernen',
                'ObjectID' => $instanceID,
                'Severity' => 'blockiert',
            ]]));
            return;
        }
        if (!$confirmed) {
            $this->UpdateFormField('InstanceReport', 'values', json_encode([[
                'Category' => 'Löschen', 'Detail' => 'Abgebrochen: Bestätigungsschalter nicht gesetzt', 'ObjectID' => $instanceID, 'Severity' => 'blockiert',
            ]]));
            return;
        }
        $name = IPS_GetName($instanceID);
        @IPS_DeleteInstance($instanceID);
        // IPS_DeleteInstance greift über den Automations-/Skript-Kanal nicht
        // immer zuverlässig — Erfolg daher prüfen und sonst auf das Löschen in
        // der Konsole verweisen, statt stumm „erledigt" zu melden.
        if (IPS_InstanceExists($instanceID)) {
            $this->UpdateFormField('InstanceReport', 'values', json_encode([[
                'Category' => 'Löschen',
                'Detail' => 'Instanz »' . $name . '« (#' . $instanceID . ') konnte nicht entfernt werden — bitte in der Konsole (Objektbaum) löschen',
                'ObjectID' => $instanceID,
                'Severity' => 'blockiert',
            ]]));
            return;
        }
        $this->UpdateFormField('InstanceReport', 'values', json_encode([[
            'Category' => 'Löschen', 'Detail' => 'Instanz »' . $name . '« (#' . $instanceID . ') wurde gelöscht', 'ObjectID' => 0, 'Severity' => 'ok',
        ]]));
    }

    // Instanzen, deren ConnectionID auf $instanceID zeigt — also alles, was
    // seinen Bus/Transport über diese Instanz führt.
    private function FindConnectionChildren(int $instanceID): array
    {
        $children = [];
        foreach (IPS_GetInstanceList() as $iid) {
            if (IPS_GetInstance($iid)['ConnectionID'] === $instanceID) {
                $children[] = $iid;
            }
        }
        return $children;
    }

    // Alle Kindvariablen einer Instanz (rekursiv über Unterkategorien).
    private function CollectVariableIDs(int $parentID): array
    {
        $ids = [];
        foreach (IPS_GetChildrenIDs($parentID) as $childID) {
            $object = IPS_GetObject($childID);
            if ($object['ObjectType'] === 2 /* Variable */) {
                $ids[] = $childID;
            }
            if (in_array($object['ObjectType'], [0 /* Category */, 2 /* Variable */], true)) {
                $ids = array_merge($ids, $this->CollectVariableIDs($childID));
            }
        }
        return $ids;
    }

    // Fremde Instanzen (nicht $instanceID selbst), deren Konfigurations-JSON
    // eine der Variablen-IDs als Zahl enthält. Liefert Map fremdeInstanz =>
    // erste gefundene VariableID.
    private function FindForeignConfigReferences(int $instanceID, array $variableIDs): array
    {
        if ($variableIDs === []) {
            return [];
        }
        $idSet = array_flip($variableIDs);
        $result = [];
        foreach (IPS_GetInstanceList() as $iid) {
            if ($iid === $instanceID) {
                continue;
            }
            $config = @IPS_GetConfiguration($iid);
            if (!is_string($config) || $config === '') {
                continue;
            }
            foreach ($this->ExtractIntegers($config) as $number) {
                if (isset($idSet[$number])) {
                    $result[$iid] = $number;
                    break;
                }
            }
        }
        return $result;
    }

    // Zieht alle ganzzahligen Zahlen aus einem Konfigurations-JSON, um sie
    // gegen die Variablen-IDs abzugleichen (IDs stehen dort je nach Modul als
    // Zahl oder in einer Liste; ein simpler strpos würde Teiltreffer liefern).
    private function ExtractIntegers(string $text): array
    {
        preg_match_all('/\d+/', $text, $matches);
        return array_map('intval', $matches[0]);
    }

    // Benutzerdefinierte Profile (nicht ~Standardprofile), die von den
    // Variablen genutzt werden und nach dem Löschen niemand mehr referenziert.
    private function FindCustomProfiles(array $variableIDs): array
    {
        $profiles = [];
        foreach ($variableIDs as $variableID) {
            if (!IPS_VariableExists($variableID)) {
                continue;
            }
            $profile = IPS_GetVariable($variableID)['VariableCustomProfile'] ?: IPS_GetVariable($variableID)['VariableProfile'];
            if ($profile !== '' && $profile[0] !== '~') {
                $profiles[$profile] = true;
            }
        }
        return array_keys($profiles);
    }
}
