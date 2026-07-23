# Hinweise für die Arbeit an diesem Repository

## Verwandte Repositories

Teil desselben Modul-Verbunds, an mehreren wird teilweise **gleichzeitig in getrennten
Sitzungen** gearbeitet:

- **MigrationsHub** (dieses Repo): Migration von Bestandsgeräten/Verknüpfungen/Archivwerten —
  https://github.com/DG65/MigrationsHub
- **InverterHub**: Wechselrichter per Modbus TCP — https://github.com/DG65/InverterHub
- **MeterHub**: Energiezähler per Modbus TCP — https://github.com/DG65/MeterHub
- **ChargerHub**: Wallboxen per Modbus TCP — https://github.com/DG65/ChargerHub
- **EMS**: koordinierende Instanz — https://github.com/DG65/EMS

## Sprachregel (Verbund-Vertrag, gilt für alle Module)

Alles, was der Nutzer zu sehen bekommt, ist **deutsch**: Formularbeschriftungen, Hinweis- und
Fehlertexte, Statuswerte, Rückgabe-Texte wie `reason`, Log-Meldungen, Variablen- und
Profilnamen, Dokumentation. Keine englischen Sätze, keine vermeidbaren Anglizismen —
also *Probelauf* statt *Dry-Run*, *Verknüpfung* statt *Link*, *Ereignis* statt *Event*,
*Schaltfläche* statt *Button*, *Prüfliste* statt *Checkliste*.

Ausgenommen sind **Bezeichner im Code** (Klassen-, Methoden-, Variablen-, Property- und
Ident-Namen), **Formularelementtypen** (`'type' => 'Button'`) sowie feststehende Fach- und
Produktnamen (`WebFront`, `Modbus TCP`, `IPSView`, `IPSWorkflowEditor`, `SunSpec`, `MPPT`,
`SOC`) — deren Eindeutschung wäre schwerer verständlich, und Umbenennen bricht Verträge:
Idents sind API.

Nach einer Ersetzungsrunde den Diff durchsehen: ein Wortwechsel ändert oft das Genus und
bricht damit den Satz (*einen zuverlässigen Portcheck* → *eine zuverlässige Port-Prüfung*).
Reines Suchen-und-Ersetzen hinterlässt Grammatikfehler. (Erfahrung aus InverterHub 0.66.5-beta.1.)

## Besonderheit gegenüber den anderen Hub-Modulen

MigrationsHub ist **kein** Live-Datenanbieter wie InverterHub/MeterHub/ChargerHub, sondern ein
einmalig/gelegentlich genutztes Werkzeug. Es bietet daher keinen `*_GetFunctions`-Vertrag im
üblichen Sinn — es liest oder erzeugt keine laufenden Messwerte, sondern verknüpft vorhandene
Variablen und überträgt Archivhistorie.

## Kernfunktion: `AC_ChangeVariableID`

`AC_ChangeVariableID($ArchiveID, $OldVariableID, $NewVariableID)` ist eine interne, nicht
offiziell dokumentierte Funktion des Archiv-Moduls. Sie funktioniert nur, wenn
`$NewVariableID` noch nie geloggt wurde. Empirisch bestätigt bei der GoodweET-Migration
(39 von 73 Variablenpaaren erfolgreich übertragen, 33 waren nie geloggt, 0 Fehler bei bereits
belegten Zielen). Vor jedem `AC_ChangeVariableID`-Aufruf muss geprüft werden, ob das Ziel
bereits Logging-Historie hat — sonst schlägt der Aufruf fehl oder überschreibt ungewollt.

## Adoptions-Modus (bevorzugter Migrationsweg, in Konzeption)

Zweiter, verlustfreier Migrationsweg (Idee von Dietmar, via EMS freigegeben): statt Historie
per `AC_ChangeVariableID` umzuhängen, wird die **alte Variable selbst übernommen**. Historie
hängt an der VariableID (Objekt-ID), Referenzen speichern die Ziel-Objekt-ID — bleibt die
Objekt-ID erhalten, bleiben Historie UND alle Variablen-Referenzen automatisch intakt.

Ablauf: neue Instanz anlegen → deren frische Dubletten entfernen → Alt-Variablen per
`IPS_SetParent` unter die neue Instanz hängen und per `IPS_SetIdent` auf die erwarteten Idents
umstellen (ändert die Objekt-ID **nicht**) → `IPS_ApplyChanges` der neuen Instanz adoptiert sie,
weil `RegisterVariable`/`MaintainVariable` eine vorhandene Kind-Variable mit passendem Ident
wiederverwendet.

Bedingungen/Fallstricke: Ident muss setzbar **und** Variablentyp identisch sein (sonst legt das
Zielmodul neu an → stiller Fehlschlag; dann Rückfall auf `AC_ChangeVariableID`). Zielmodul muss
in `ApplyChanges` per Ident pflegen (Suite-Module ja, Fremdmodule nicht garantiert). Umgeht das
Cutover-/Überlappungsproblem und den `AC_AddLoggedValues`-Mengenhänger komplett.

**Startumfang laut Dietmar: nur eigene Suite-Module.** Für Fremdmodule bleibt
`AC_ChangeVariableID` der Default; Adoption dort höchstens experimentell mit Kompatibilitätstest
und Warnung. Vor dem Bau: End-to-End-Test an EINER Wegwerf-Variable gegen eine frische
InverterHub-GoodWe-Instanz (Objekt-ID erhalten? Modul bespielt sie? Aktion/Profil sitzt?
Historie sichtbar?).

**Scharfe Kante (aus InverterHub-Code bestätigt): Adoption schlägt DESTRUKTIV fehl.** Der
GoodWe-Treiber ruft in `ApplyChanges` zuerst `PruneForeignObjects()` auf — das **löscht** jede
Kind-Variable, deren Ident nicht im gültigen Set des aktuell gewählten Treibers steht (inaktive
optionale Gruppe, `MpptCount` zu klein, oder Ident dem Treiber unbekannt). Erst danach läuft die
Ident-Wiederverwendung. Konsequenz: hängt man die historienbehaftete Original-Variable mit
einem (noch) ungültigen Ident/Typ an, wird sie samt Historie unwiederbringlich gelöscht.
Gegensatz: `AC_ChangeVariableID` schlägt **sicher** fehl (verweigert, Altdaten bleiben).
Adoption braucht daher **mehr** Vorab-Sicherung, nicht weniger.

**Testergebnis (23.07.2026, an InverterHub-Wegwerf-Instanz #59108, Ident `riso`):** Adoption
bestätigt. Objekt-ID blieb erhalten; das Modul verwendete die umgehängte Variable wieder (setzte
Name + Kategorie, legte KEINE neue an), auch bei `Active=false`. Typ erhalten. Historie folgt
strukturell aus der erhaltenen Objekt-ID (Live-Nachweis an gewachsener Historie steht beim ersten
echten Lauf aus, weil `AC_AddLoggedValues` über den MCP-Kanal unzuverlässig ist). Aktionsgebundener
Ident noch nicht getestet.

**Befund Anzeigeprofil:** Das Zielmodul setzt beim Wiederverwenden KEIN Profil (respektiert die
Stable-Regel „Custom-Profile nicht in ApplyChanges überschreiben"). Eine echte Alt-Variable behält
ihr eigenes altes Profil (Objekt-ID erhalten), zeigt also das QUELL-Profil, nicht das des Ziels.
Die Angleichung ans Zielmodul ist daher **Aufgabe von MigrationsHub**: vor dem Entfernen der frisch
angelegten Modul-Variable deren beabsichtigtes Profil je Ident auslesen und nach der Adoption per
`IPS_SetVariableCustomProfile` auf die übernommene Variable setzen — als explizite Migrationsaktion
(nicht in einem Modul-`ApplyChanges`), die die Stable-Regel nicht verletzt.

Pflicht-Sicherung im Adoptions-Modus:
- **Preflight-Sonde je Ident**, bevor die echte Variable angefasst wird: Wegwerf-Variable mit
  exakt gleichem Ident und Typ anhängen → `IPS_ApplyChanges` → prüfen ob sie überlebt (Objekt-ID
  noch da). Nur dann die echte Variable umhängen. Sonde danach löschen.
- Nur Idents adoptieren, die aktuell im gültigen Set stehen; Zielgruppe vorher aktivieren,
  `MpptCount` passend (0 = alle), Typ exakt gleich.
- Idents, die das Zielmodul noch nicht kennt, sind für Adoption **gesperrt** — erst registrieren,
  dann adoptieren. Konkret GoodweET→InverterHub: seit InverterHub 0.71.0-beta.1 (Build 180) deckt
  der GoodWe-Treiber alle **138/138 Idents** ab, inkl. der 18 BMS-Zelldiagnostik-Idents
  (`bat{1,2}_pack_temp`, `cell_vmax/vmin`, `cell_tmax/tmin`, `idx_*`) — kein Sonderfall mehr.
  Typ-Falle dabei: `pack_temp`/`cell_tmax`/`cell_tmin` sind **Float**, `cell_vmax/vmin` und alle
  `idx_*` sind **Integer**; die Batterie-1/2-Gruppen müssen aktiviert sein.

## Stable-Checkliste (Store-Review, von Beginn an erfüllen)

Erkenntnisse aus bisherigen Store-Reviews im Verbund — bei jeder Änderung einhalten:

- **Schaltflächen im Formular** dürfen ihre eigene Instanz **nicht** per `IPS_SetProperty` +
  `IPS_ApplyChanges` selbst persistieren. Nur die offene Maske über `UpdateFormField` füllen;
  bestätigt wird vom Nutzer mit „Übernehmen". (Echte Aktionen wie `AC_ChangeVariableID`,
  `IPS_DeleteInstance` oder `IPS_ApplyChanges` auf einer **fremden** Instanz — z. B. dem Archiv —
  sind erlaubt, das ist keine Formular-Persistenz.)
- **Benutzerdefinierte Profile** sind Nutzer-Hoheit: nur bei Erstanlage über den Profil-Parameter
  von `RegisterVariable` setzen, **nie** wiederholt per `IPS_SetVariableCustomProfile` in
  `ApplyChanges` überschreiben. Bei der Adoption das Profil des **Ziel**moduls respektieren, nicht
  das der Quelle aufzwingen.
- `module.json`: `vendor` = `""` (reines Hilfsmodul ohne Fremdsystem).
- `library.json`: `url`-Feld ist Pflicht.
- **Keine Emojis** in Captions/Anzeigetexten (typografische Pfeile `→` sind ok).
- Klassenname = Modulname; Installation nur über die Modulverwaltung.
- Sprachregel (alles Nutzersichtbare deutsch) und Eigenständigkeit (`.tools/check-standalone.php`
  grün) einhalten.

## Eigenständigkeit prüfen: `.tools/check-standalone.php`

```
php .tools/check-standalone.php    # 0 = sauber, 1 = ungesicherter Fremdaufruf
```

Herkunft und Funktionsweise wie im MeterHub-Repo — bei Änderungen an der Prüflogik bitte in
allen Hub-Repos gleich halten.
