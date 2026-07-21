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

## Eigenständigkeit prüfen: `.tools/check-standalone.php`

```
php .tools/check-standalone.php    # 0 = sauber, 1 = ungesicherter Fremdaufruf
```

Herkunft und Funktionsweise wie im MeterHub-Repo — bei Änderungen an der Prüflogik bitte in
allen Hub-Repos gleich halten.
