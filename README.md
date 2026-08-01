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
ab, Zielprofil und Aggregationstyp werden nachgezogen. Funktioniert mit jedem Zielmodul, da die
Preflight-Sonde generisch prüft, ob die Übernahme sicher möglich ist — bei fremden (Nicht-NRG-
Stack-)Modulen muss der Nutzer im Formular ausdrücklich ein Risiko bestätigen, weil ein
Zielmodul einen unerkannten Datenpunkt beim Speichern ersatzlos löschen kann (getestetes
Verhalten nur bei den eigenen Suite-Modulen garantiert).

**Nach vollständiger Übernahme:** Ist eine Alt-Instanz danach ohne verbliebene Datenpunkte,
schlägt MigrationsHub automatisch vor, sie über „Instanz analysieren & löschen" zu prüfen und
zu entfernen.

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
   eintragen: `https://github.com/DG65/NRGMigrationsHub`
2. Eine neue Instanz vom Typ **„MigrationsHub"** anlegen.

## Ablauf Schritt für Schritt

Die kurze Version steht auch im Formular selbst unter „📖 Dokumentation & Hilfe" — hier die
ausführliche Fassung, falls du sie vorab lesen möchtest.

**0. Zielmodul zuerst.** Bevor du MigrationsHub startest: die neue Hub-Instanz (z. B.
InverterHub, MeterHub) installieren und konfigurieren. Dabei entstehen die neuen Datenpunkte
bereits — aber **„Kommunikation aktiv" zunächst ausgeschaltet lassen**. Grund: die neuen
Datenpunkte sollen noch keine eigenen Werte geloggt haben, wenn die alte Historie übertragen
wird — sonst müsste MigrationsHub zwei sich überlappende Datenreihen zusammenführen, was es
bewusst nicht automatisch tut.

**1. MigrationsHub-Instanz anlegen** und im Formular Schritt 1 die Alt-Instanz (deine bisherige
Lösung) und die eben vorbereitete Neu-Instanz auswählen.

**2. Datenpunkte wählen.** „Alt-Datenpunkte laden" → gewünschte Zeilen ankreuzen (oder „Alle
auswählen") → „in Migrationsliste übernehmen". Für Idents, die in beiden Instanzen identisch
heißen, wird das Ziel automatisch vorgeschlagen.

**3. Zuordnung prüfen.** Fehlende Ziele über das Suchfeld in der Migrationsliste ergänzen. Die
Spalte „Referenzen" zeigt, ob ein Datenpunkt archiviert wird oder Verknüpfungen hat — je mehr,
desto wichtiger eine sorgfältige Zuordnung. Mit „Referenzen suchen" zusätzlich prüfen, ob
Skripte oder Ereignisse die alte Variablen-ID fest referenzieren, und die Fundliste abarbeiten.

**4. Simulieren, dann ausführen.** Bestätigungsschalter setzen, zuerst „Übernahme simulieren
(Probelauf)" — der schreibt nichts, zeigt aber, was passieren würde. Ergebnis durchsehen, dann
„Übernahme (Adoption) jetzt ausführen". Wo die Übernahme technisch nicht möglich ist (z. B.
unterschiedlicher Datentyp), fällt MigrationsHub automatisch auf den zweiten Weg (Verknüpfen)
zurück — das steht dann so im Ergebnis.

**5. Nachbereiten.** An der neuen Instanz „Kommunikation aktiv" einschalten. Verbliebene
Skript-/Ereignis-Funde aus der Prüfliste abarbeiten. Ist eine Alt-Instanz nach der Übernahme
vollständig leer, schlägt MigrationsHub das Löschen automatisch vor; sonst die alte Instanz
manuell über „Instanz analysieren & löschen" prüfen — das Löschen wird automatisch blockiert,
solange noch andere
Instanzen als Verbindung (Transport) daran hängen.

## Lizenz

[PolyForm Noncommercial 1.0.0](LICENSE) — private und nicht-kommerzielle Nutzung frei,
gewerbliche Nutzung lizenzpflichtig (Kontakt: [DG65](https://github.com/DG65)).
