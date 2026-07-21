# MigrationsHub

IP-Symcon-Modul zur Migration von Bestandsgeräten: Verknüpfung alter Variablen mit neuen (z. B.
nach einem Gerätetausch oder beim Umstieg von einer Einzellösung auf ein Hub-Modul wie
[InverterHub](https://github.com/DG65/InverterHub) oder [MeterHub](https://github.com/DG65/MeterHub))
sowie Übernahme der historischen Archivwerte auf die neue Variable.

**Status: Gerüst.** Die eigentliche Zuordnungs- und Migrationslogik folgt als nächster Schritt.

## Wofür braucht man das?

Wird ein Bestandsgerät durch ein neues Hub-Modul ersetzt (z. B. ein selbstgebautes
Wechselrichter-Skript durch InverterHub), entstehen neue Variablen — die bisherige
Archiv-Historie hängt aber an den alten. Ohne Migration reißt die Historie an dieser Stelle ab.

## Technik der Archivübernahme

`AC_ChangeVariableID($ArchiveID, $OldVariableID, $NewVariableID)` überträgt die komplette
Logging-Historie einer Variable auf eine andere — funktioniert aber nur, wenn
`$NewVariableID` **noch nie geloggt wurde** (jungfräulicher Archiv-Zustand). Diese Funktion ist
nicht offiziell dokumentiert; sie wurde über `get_defined_functions()['internal']`
(gefiltert nach `ac_`-Präfix) gefunden und bei der GoodweET-Migration empirisch bestätigt.

## Installation

1. In der IP-Symcon-Konsole: **Modulverwaltung → Hinzufügen** und die URL dieses Repositories
   eintragen: `https://github.com/DG65/MigrationsHub`
2. Eine neue Instanz vom Typ **„MigrationsHub"** anlegen.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
