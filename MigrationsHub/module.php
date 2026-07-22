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

        // Abhak-Liste manuell zu prüfender Fundstellen (Skripte/Events, die die
        // Alt-Variablen-ID referenzieren) — Property, damit der Nutzer sie
        // Stück für Stück abarbeiten kann und der Fortschritt über mehrere
        // Sitzungen hinweg erhalten bleibt, statt bei jedem Formular-Neuöffnen
        // neu anfangen zu müssen.
        // Getrennt nach Skript/Event (statt einer gemeinsamen Liste), weil sich
        // damit im Formular je ein SelectScript-/SelectEvent-Feld verwenden
        // lässt — die haben in der Konsole einen eingebauten "Bearbeiten"-
        // Knopf, der direkt zum Objekt springt.
        $this->RegisterPropertyString('ScriptChecks', '[]');
        $this->RegisterPropertyString('EventChecks', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
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
            return ['success' => false, 'reason' => 'old variable does not exist', 'archived' => false, 'relinked' => 0, 'dryRun' => $dryRun];
        }
        if (!IPS_VariableExists($newVariableID)) {
            return ['success' => false, 'reason' => 'new variable does not exist', 'archived' => false, 'relinked' => 0, 'dryRun' => $dryRun];
        }
        if ($oldVariableID === $newVariableID) {
            return ['success' => false, 'reason' => 'old and new variable are identical', 'archived' => false, 'relinked' => 0, 'dryRun' => $dryRun];
        }

        $archived = false;
        $archiveID = $this->FindArchiveInstance($oldVariableID);
        if ($archiveID !== 0) {
            if ($this->HasArchiveHistory($archiveID, $newVariableID)) {
                // AC_ChangeVariableID funktioniert nur bei "jungfräulichem" Ziel —
                // ein bereits geloggtes Ziel still zu überschreiben wäre falsch.
                // Wichtig: das prüft tatsächlich vorhandene Werte, nicht nur den
                // Logging-Status (eine deaktiviert-geloggte Variable kann trotzdem
                // Altwerte im Archiv haben, siehe MeterHub-Fund #40325).
                return ['success' => false, 'reason' => 'target variable already has archive history', 'archived' => false, 'relinked' => 0, 'dryRun' => $dryRun];
            }
            if ($dryRun) {
                $archived = true; // würde übertragen werden
            } else {
                $archived = AC_ChangeVariableID($archiveID, $oldVariableID, $newVariableID);
                if (!$archived) {
                    return ['success' => false, 'reason' => 'AC_ChangeVariableID failed', 'archived' => false, 'relinked' => 0, 'dryRun' => $dryRun];
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
        $parts[] = $linkCount . ' Link' . ($linkCount === 1 ? '' : 's');
        return implode(', ', $parts);
    }

    // Übernimmt die in Schritt 2 angehakten Alt-Datenpunkte als neue Zeilen in
    // die Migrationsliste (Schritt 3). Ist die Neu-Instanz aus Schritt 1
    // gesetzt, wird als Zielvorschlag die Variable mit demselben Ident dort
    // vorbelegt (nur ein Vorschlag, keine automatische Zuordnung — Status
    // macht das kenntlich und der Nutzer muss ihn trotzdem prüfen/bestätigen).
    // Ohne Treffer bleibt das Ziel leer und wird über den durchsuchbaren
    // SelectVariable-Dialog je Zeile gewählt.
    public function AddSourceVariablesToMigrations($sourceVariables, $migrations, int $targetInstanceID = 0): void
    {
        $sourceVariables = $this->NormalizeFormList($sourceVariables);
        $migrations = $this->NormalizeFormList($migrations);
        $linkCounts = $this->BuildLinkCountMap();

        $targetByIdent = [];
        foreach ($this->GetChildVariableRows($targetInstanceID) as $targetRow) {
            $targetByIdent[$targetRow['Ident']] = (int) $targetRow['VariableID'];
        }

        // array_column() setzt Arrays oder Objekte mit öffentlichen Properties
        // voraus; einzelne Zeilen können hier aber ArrayAccess-Objekte sein —
        // deshalb per Schleife statt array_column() auslesen.
        $existingOldIDs = [];
        foreach ($migrations as $migrationRow) {
            $existingOldIDs[] = (int) $migrationRow['OldVariableID'];
        }

        foreach ($sourceVariables as $row) {
            if (empty($row['Selected'])) {
                continue;
            }
            $oldID = (int) $row['VariableID'];
            if (in_array($oldID, $existingOldIDs, true)) {
                continue;
            }
            $suggestedNewID = $targetByIdent[$row['Ident']] ?? 0;
            $migrations[] = [
                'OldVariableID' => $oldID,
                'NewVariableID' => $suggestedNewID,
                'Status' => $suggestedNewID !== 0 ? 'Vorschlag anhand Ident — bitte prüfen' : 'Ziel wählen',
                'References' => $this->DescribeReferences($oldID, $linkCounts),
            ];
        }
        $this->UpdateFormField('Migrations', 'values', json_encode($migrations));
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
    public function ScanReferences($migrations, $scriptChecks, $eventChecks): void
    {
        $migrations = $this->NormalizeFormList($migrations);
        $scriptChecks = $this->NormalizeFormList($scriptChecks);
        $eventChecks = $this->NormalizeFormList($eventChecks);

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
                'Reason' => 'Abgebrochen: Bestätigungs-Checkbox nicht gesetzt',
                'OldValue' => '',
                'NewValue' => '',
                'Plausible' => '-',
            ]]));
            return;
        }

        [$migrations, $results] = $this->ProcessMigrations($migrations, false);
        $this->UpdateFormField('Migrations', 'values', json_encode($migrations));
        $this->UpdateFormField('Results', 'values', json_encode($results));
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
                $plausible = $dryRun ? 'n/a (Simulation)' : (($oldValue === $newValue) ? 'ja' : 'bitte prüfen');
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
}
