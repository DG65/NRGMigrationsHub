# MigrationsHub

IP-Symcon-Modul zur Migration von Bestandsgeräten: Übernahme alter Variablen auf neue Instanzen
(z. B. nach einem Gerätetausch oder beim Umstieg von einer Einzellösung auf ein Hub-Modul wie
[InverterHub](https://github.com/DG65/InverterHub) oder [MeterHub](https://github.com/DG65/MeterHub))
— **mitsamt Archivhistorie und bestehenden Verknüpfungen**.

Teil des **NRG-Stack** — welche Modulstände zusammenpassen, steht im Manifest
[EMS/SUITE.md](https://github.com/DG65/EMS/blob/main/SUITE.md).

## Wofür braucht man das?

Wird ein Bestandsgerät durch ein neues Hub-Modul ersetzt (z. B. ein selbstgebautes
Wechselrichter-Skript durch InverterHub), entstehen neue Variablen — die bisherige
Archiv-Historie und alle Referenzen (Verknüpfungen, Skripte, Ereignisse) hängen aber an den
alten. Ohne Migration reißt die Historie an dieser Stelle ab. MigrationsHub überträgt sie.

## Zwei Migrationswege

**1. Übernahme (Adoption) — bevorzugt, verlustfrei.** Die alte Variable wird selbst übernommen:
sie behält ihre Objekt-ID, damit bleiben Historie **und** alle Referenzen ohne Umbau intakt. Die
alte Variable wird unter die neue Instanz gehängt und auf den erwarteten Ident umgestellt; das
Zielmodul verwendet sie beim `ApplyChanges` wieder. Eine Preflight-Sonde sichert jeden Ident vorab
ab, Zielprofil und Aggregationstyp werden nachgezogen. Nur für die eigenen Suite-Module.

**2. Verknüpfen (`AC_ChangeVariableID`) — Rückfall.** Überträgt die Logging-Historie auf die neue
Variable und hängt Verknüpfungen um. `AC_ChangeVariableID($ArchiveID, $OldVariableID,
$NewVariableID)` funktioniert nur, wenn `$NewVariableID` **noch nie geloggt wurde** (jungfräulicher
Archiv-Zustand). Diese Funktion ist nicht offiziell dokumentiert; sie wurde über
`get_defined_functions()['internal']` (gefiltert nach `ac_`-Präfix) gefunden und bei der
GoodweET-Migration empirisch bestätigt. MigrationsHub prüft vor jeder Übertragung, ob das Ziel
bereits Historie hat, und lehnt sonst ab.

## Weitere Funktionen

- **Geführtes Formular:** Alt-Instanz und Datenpunkte wählen, Ziel je Paar (Ident-Vorschlag),
  Probelauf-Simulation, doppelte Bestätigung, Plausibilitätsprüfung alt/neu.
- **Referenz-Suche:** findet Skripte und Ereignisse, die eine Variablen-ID fest referenzieren, als
  abhakbare Prüfliste mit Direkt-Öffnen (Skripte/Workflows/IPSView werden **nicht** automatisch
  umgeschrieben — nur aufgelistet).
- **Wh/kWh-Zwillingserkennung:** markiert die Wh-Variante und empfiehlt die kWh-Variante.
- **Instanz-Analyse & geführtes Löschen:** Abhängigkeits-Report vor dem Entfernen einer
  Alt-Instanz; das Löschen wird hart blockiert, solange Transport-/Verbindungskinder daran hängen.

## Installation

1. In der IP-Symcon-Konsole: **Modulverwaltung → Hinzufügen** und die URL dieses Repositories
   eintragen: `https://github.com/DG65/MigrationsHub`
2. Eine neue Instanz vom Typ **„MigrationsHub"** anlegen.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
