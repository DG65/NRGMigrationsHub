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
    public function MigrateVariable(int $oldVariableID, int $newVariableID): array
    {
        if (!IPS_VariableExists($oldVariableID)) {
            return ['success' => false, 'reason' => 'old variable does not exist', 'archived' => false, 'relinked' => 0];
        }
        if (!IPS_VariableExists($newVariableID)) {
            return ['success' => false, 'reason' => 'new variable does not exist', 'archived' => false, 'relinked' => 0];
        }
        if ($oldVariableID === $newVariableID) {
            return ['success' => false, 'reason' => 'old and new variable are identical', 'archived' => false, 'relinked' => 0];
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
                return ['success' => false, 'reason' => 'target variable already has archive history', 'archived' => false, 'relinked' => 0];
            }
            $archived = AC_ChangeVariableID($archiveID, $oldVariableID, $newVariableID);
            if (!$archived) {
                return ['success' => false, 'reason' => 'AC_ChangeVariableID failed', 'archived' => false, 'relinked' => 0];
            }
            // Zielvariable nach der Übernahme aktiv weiterloggen lassen — sie muss
            // vorher nicht zwingend für Logging aktiviert gewesen sein.
            AC_SetLoggingStatus($archiveID, $newVariableID, true);
            IPS_ApplyChanges($archiveID);
        }

        $relinked = 0;
        foreach ($this->FindLinksToVariable($oldVariableID) as $linkID) {
            if (IPS_SetLinkTargetID($linkID, $newVariableID)) {
                $relinked++;
            }
        }

        return ['success' => true, 'reason' => '', 'archived' => $archived, 'relinked' => $relinked];
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
        $values = AC_GetLoggedValues($archiveID, $variableID, 0, 0, 1);
        return count($values) > 0;
    }

    // Findet alle Link-Objekte (WebFront-Verknüpfungen) in der gesamten
    // Objekthierarchie, deren Ziel $variableID ist.
    private function FindLinksToVariable(int $variableID): array
    {
        $linkIDs = [];
        foreach (IPS_GetObjectIDList() as $objectID) {
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
        foreach (IPS_GetObjectIDList() as $objectID) {
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

    // --- Formular: Schritt 3 — Migration ausführen + Plausibilitätsprüfung ---

    // Führt alle Paare der Migrationsliste aus. Der Button im Formular hat
    // zusätzlich einen nativen Bestätigungsdialog (form.json "confirm"); die
    // Confirmed-Checkbox ist ein zweites, unabhängiges Sicherheits-Gate, weil
    // der Vorgang Archivhistorie unwiderruflich überträgt.
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

        $results = [];
        foreach ($migrations as &$row) {
            $oldID = (int) $row['OldVariableID'];
            $newID = (int) $row['NewVariableID'];
            $oldName = IPS_VariableExists($oldID) ? IPS_GetName($oldID) : (string) $oldID;
            $newName = IPS_VariableExists($newID) ? IPS_GetName($newID) : (string) $newID;

            $result = $this->MigrateVariable($oldID, $newID);
            $row['Status'] = $result['success'] ? 'migriert' : ('Fehler: ' . $result['reason']);

            // Plausibilitätsprüfung: aktueller Wert alt gegen neu vergleichen.
            // Das ersetzt keine fachliche Prüfung (z. B. Wh/kWh-Zwillinge,
            // Vorzeichenkonvention), zeigt aber offensichtliche Fehlzuordnungen.
            $oldValue = IPS_VariableExists($oldID) ? GetValueFormatted($oldID) : '';
            $newValue = IPS_VariableExists($newID) ? GetValueFormatted($newID) : '';

            $results[] = [
                'OldName' => $oldName,
                'NewName' => $newName,
                'Success' => $result['success'] ? 'ja' : 'nein',
                'Reason' => $result['reason'],
                'OldValue' => $oldValue,
                'NewValue' => $newValue,
                'Plausible' => $result['success'] ? (($oldValue === $newValue) ? 'ja' : 'bitte prüfen') : '-',
            ];
        }
        unset($row);

        $this->UpdateFormField('Migrations', 'values', json_encode($migrations));
        $this->UpdateFormField('Results', 'values', json_encode($results));
    }
}
