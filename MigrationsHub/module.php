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
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }

    // Verknüpft eine alte mit einer neuen Variable und übernimmt, sofern
    // möglich, deren Archivhistorie per AC_ChangeVariableID. Gibt ein
    // Ergebnis-Array zurück (Erfolg/Grund), damit der Aufrufer (Formular oder
    // ein anderes Modul) den Ausgang anzeigen kann statt nur true/false.
    public function MigrateVariable(int $oldVariableID, int $newVariableID): array
    {
        // TODO: Archiv-Instanz ermitteln, Prüfen ob $newVariableID bereits
        // geloggt ist (dann AC_ChangeVariableID ablehnen und melden, statt
        // stillschweigend nichts zu tun), sonst AC_ChangeVariableID aufrufen.
        return ['success' => false, 'reason' => 'not implemented yet'];
    }
}
