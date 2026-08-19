# Hinweise für die Arbeit an diesem Repository

## Verwandte Repositories

Teil desselben Modul-Verbunds, an mehreren wird teilweise **gleichzeitig in getrennten
Sitzungen** gearbeitet:

- **MigrationsHub** (dieses Repo): Migration von Bestandsgeräten/Verknüpfungen/Archivwerten —
  https://github.com/DG65/NRGMigrationsHub
- **InverterHub**: Wechselrichter per Modbus TCP — https://github.com/DG65/NRGInverterHub
- **MeterHub**: Energiezähler per Modbus TCP — https://github.com/DG65/NRGMeterHub
- **ChargerHub**: Wallboxen per Modbus TCP — https://github.com/DG65/NRGChargerHub
- **EMS**: koordinierende Instanz — https://github.com/DG65/NRGEMS

## Discovery-Integration (API für andere Module)

Damit Migration Teil des normalen Geräte-Scans werden kann statt ein separates, erst zu
findendes Werkzeug: `MIGHUB_FindLegacyCandidates($id, $host, $port=0, $unitId=0,
$excludeInstanceID=0): array` und `MIGHUB_PrefillMigration($id, $oldInstanceID,
$newInstanceID): void`. Matching **immer über Host/Port/Unit-ID, nie über den Namen** (zwei
reale Namens-Fallen bereits erlebt: ModBus-Gateway namens "Goodwe Wechselrichter", fünf
gleichnamige aber unterschiedliche "Siemens PAC2200"-Instanzen). `$excludeInstanceID`: die
eigene, gerade frisch angelegte Zielinstanz übergeben, damit sie sich nicht selbst als Alt-
Instanz findet (echter Fall mit ChargerHub, 03.08.2026: neue Instanz und Alt-Instanz zufällig
kurzzeitig gleiche IP, ohne Ausschluss wäre "migriere von dir selbst" möglich gewesen).
Aufrufendes Modul: eigene MigrationsHub-Instanz bei Bedarf selbst per
`IPS_CreateInstance('{330717BB-E309-41A2-90A8-FDA3179ED948}')` anlegen (kein API-Bedarf dafür),
nach Bestätigung `PrefillMigration` aufrufen, dann per `OpenObjectButton` (Instanz-ID) im eigenen
Formular dorthin navigieren. Abgestimmt mit InverterHub (29.07.2026), Ausschluss-Parameter mit
ChargerHub (03.08.2026).

## Ident-Auskunft von Zielmodulen (`<PREFIX>_GetIdentMapping`, Verbund-Vertrag 03.08.2026)

Gegenrichtung zur Discovery-Integration: Hub-Module (InverterHub/MeterHub/ChargerHub) können
MigrationsHub optional mitteilen, wie sie die Idents eines bekannten Fremdmoduls auf ihre eigenen
abbilden — statt dass wir das extern raten oder mühsam aus fremdem Quellcode nachbauen (Lehre aus
der go-e-Charger-Migration, wo eine hartkodierte, teils fehlerhafte Tabelle nötig war, siehe
`GetKnownIdentTranslation()`).

Vertrag: `<PREFIX>_GetIdentMapping($id, string $foreignModuleGUID, array $foreignIdents): array`
— `$id` = die MigrationsHub anfragende Zielinstanz (kennt ihr eigenes aktuell gültiges Ident-Set,
das ist bei manchen Modulen instanzabhängig, z. B. je nach Zählertyp/Messmodus bei MeterHub);
`$foreignIdents` = die tatsächlich an der Alt-Instanz vorhandenen Idents (nötig, weil manche
Vorgängermodule Felder je nach Firmware unterschiedlich benennen, z. B. `power1` vs.
`phase1Power` bei MeterHubs Inexogy-Fall). Rückgabe: `['altIdent' => ['ident' => 'neuIdent',
'type' => int (IPS `VARIABLETYPE_*`: 0=Bool,1=Int,2=Float,3=String)], ...]`, nur erkannte
Treffer, leeres Array = "kenne diese Fremdmodul-GUID nicht" (kein Fehler).

**Bewusst NUR eine Auskunftsfunktion, keine zweite Funktion für die eigentliche Übernahme.**
Ein ursprünglicher Gegenvorschlag (Zielmodul reparentet selbst per eigener
`AdoptFromLegacyInstance()`-Funktion) wurde verworfen: MigrationsHub reparentet Alt-Variablen
selbst per `IPS_SetParent`/`IPS_SetIdent`, BEVOR `IPS_ApplyChanges($targetInstanceID)` aufgerufen
wird — die im Zielmodul ohnehin vorhandene Prune-vor-Register-Sequenz (bestätigt im eigenen Code
von InverterHub UND MeterHub: erst gültiges Ident-Set berechnen, dann `PruneForeignObjects()`,
dann `RegisterVar()`/Äquivalent) greift dann automatisch korrekt, weil die Variablen zu diesem
Zeitpunkt schon umbenannt sind — kein Rateaufwand mehr über die bisherige Preflight-Sonde nötig,
sobald das Zielmodul `GetIdentMapping` anbietet. Reparenting/Pruning/Simulation/Ausführung
bleiben dadurch vollständig zentral bei MigrationsHub (Single Point of Truth, wie von ChargerHub
betont) — das Zielmodul fasst dabei keine IPS-Objekte an, liefert nur Auskunft.

Aufruf bei uns über `GetForeignIdentMapping()`: Prefix des Zielmoduls dynamisch via
`IPS_GetModule($moduleID)['Prefix']` ermittelt (kein hartkodiertes Mapping nötig), Aufruf
`function_exists()`-abgesichert wie jeder Fremdaufruf. Module ohne diese Funktion (oder mit
leerem Ergebnis für die konkrete Fremdmodul-GUID) fallen automatisch auf die bisherige
Preflight-Sonde plus die hartkodierte `GetKnownIdentTranslation()`-Tabelle zurück — kein
Breaking Change für Module, die (noch) nicht mitziehen. Umsetzungsstand 03.08.2026:
InverterHub befüllt zunächst nur GoodweET (1:1-Portierung), MeterHub den Discovergy-Fall,
ChargerHub den go-e-Charger-Fall (löst die bisherige externe Tabelle bei uns ab, sobald live).

**Plattform-Falle (Verbund-relevant, per 03.08.2026 bestätigt): PHP-Default-Werte optionaler
Parameter gelten NICHT für den `MIGHUB_*`-RPC-Wrapper.** Als `FindLegacyCandidates()` einen 5.
Parameter mit PHP-Default `= 0` bekam, brach bei ChargerHub (Aufruf mit den bisherigen 4
Parametern) live `ArgumentCountError: Too few arguments … 4 passed, exactly 5 expected` — obwohl
die PHP-Signatur den Parameter klar optional deklariert. IP-Symcons Kernel generiert die
öffentliche Präfix-Funktion offenbar mit fester Arität (exakt so viele Parameter wie deklariert,
Default-Werte werden dabei nicht übernommen) — das gilt vermutlich für **jedes** Modul im Verbund,
nicht nur für uns. Konsequenz: einer öffentlichen `*_`-Präfix-Funktion nachträglich einen weiteren
Parameter zu geben ist **immer ein Breaking Change** für externe Aufrufer, ganz gleich ob mit
PHP-Default versehen oder nicht — braucht denselben Koordinationsaufwand wie eine
`contractVersion`-Major-Änderung: alle bekannten Aufrufer vorher informieren und synchron
umstellen (wie hier mit ChargerHub geschehen), sonst bricht es live und unangekündigt.

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

**Startumfang laut Dietmar: zunächst nur eigene Suite-Module gebaut/getestet.** Vor dem Bau:
End-to-End-Test an EINER Wegwerf-Variable gegen eine frische InverterHub-GoodWe-Instanz
(Objekt-ID erhalten? Modul bespielt sie? Aktion/Profil sitzt? Historie sichtbar?) — erledigt,
siehe Testergebnis unten.

**Erweiterung (Dietmar, 29.07.2026): Übernahme auch für Fremdmodule, mit explizitem
Einverständnis.** Die Suite-only-Beschränkung war nie im Code erzwungen — `AdoptVariable()`
leitet Ident/Typ/Profil/Instanz generisch aus der Zielvariable ab, die Preflight-Sonde sichert
jedes Zielmodul unabhängig von dessen Herkunft ab. Statt einer unzuverlässigen "ist das ein
Suite-Modul"-Erkennung gilt jetzt: ein eigener Risiko-Schalter (`RiskAcknowledged`, getrennt vom
allgemeinen Bestätigungsschalter) muss für JEDEN Übernahme-Lauf gesetzt sein, mit explizitem
Hinweis auf die destruktive Prune-Kante — bei eigenen Suite-Modulen ist das Verhalten getestet,
bei Fremdmodulen nicht garantiert. Nach einem Lauf, der eine Alt-Instanz vollständig entleert,
schlägt das Formular automatisch vor, sie über "Instanz analysieren & löschen" zu prüfen
(`AnalyzeInstanceID` wird vorbelegt, Report direkt mitgeliefert).

**Scharfe Kante (aus InverterHub-Code bestätigt): Adoption schlägt DESTRUKTIV fehl.** Der
GoodWe-Treiber ruft in `ApplyChanges` zuerst `PruneForeignObjects()` auf — das **löscht** jede
Kind-Variable, deren Ident nicht im gültigen Set des aktuell gewählten Treibers steht (inaktive
optionale Gruppe, `MpptCount` zu klein, oder Ident dem Treiber unbekannt). Erst danach läuft die
Ident-Wiederverwendung. Konsequenz: hängt man die historienbehaftete Original-Variable mit
einem (noch) ungültigen Ident/Typ an, wird sie samt Historie unwiederbringlich gelöscht.
Gegensatz: `AC_ChangeVariableID` schlägt bei bereits belegtem Ziel **sicher** fehl (verweigert,
Altdaten bleiben) — bei Typ-Mismatch dagegen NICHT: dort wirft es live einen Fatal Error
("Variablen müssen vom selben Typ sein") statt `false` zurückzugeben (live bestätigt über
ChargerHub, 03.08.2026, Rückfall-Pfad bei Integer/Boolean-Mismatch). MigrationsHub prüft den
Typ deshalb selbst vorab und meldet einen sauberen Fehlschlag, statt den Aufruf crashen zu
lassen. Adoption braucht trotzdem **mehr** Vorab-Sicherung als Verknüpfen, nicht weniger.

**Testergebnis (23.07.2026, an InverterHub-Wegwerf-Instanz #59108):** Adoption an drei Idents
bestätigt — `riso` (Float, Profil), `pv_total` (Float, Basisgruppe, ohne Gruppenaktivierung),
`ctl_ems_power` (Integer, Control-Gruppe). In allen Fällen: Objekt-ID erhalten, Modul verwendete
die umgehängte Variable wieder (setzte Name + richtige Kategorie, legte KEINE neue an), auch bei
`Active=false`, Typ erhalten (Float und Integer). Historie folgt strukturell aus der erhaltenen
Objekt-ID (Live-Nachweis an gewachsener Historie steht beim ersten echten Lauf aus, weil
`AC_AddLoggedValues` über den MCP-Kanal unzuverlässig ist).

**Aktionsbindung:** nicht gesetzt — aber auch auf der nativen Modul-Variable nicht. Präzise
Vorbedingung (von InverterHub empirisch geklärt): IPS bindet Custom-Aktionen nur an Instanzen mit
**Status 102 = aktiv UND gültiger, erreichbarer Host**. `Active=true` allein genügt NICHT — eine
hostlose Instanz wie die Wegwerf-#59108 bleibt auf Status 104, der `EnableActionsTimer` feuert nie,
`IPS_SetVariableCustomAction` bleibt wirkungslos (nativ wie adoptiert). Das Fehlen ist also der
Status-102-Gate, kein Adoptions-Defekt; `EnableActions` bindet per `FindVarByIdent` identbasiert,
wirkt auf adoptierte und native Variablen identisch. Am echten Migrationsziel (Status 102) kommt
die Bindung automatisch — Nachweis daher erst beim ersten echten Lauf (Testinstanz ohne Host
kann ihn prinzipiell nicht erbringen).

**Zweiter, unabhängiger Gate (Fund InverterHub, Live-Debugging, Commit 2d8228f):** Liegt die
Steuervariable NICHT direkt unter der Instanz, sondern in einer per `IPS_SetParent()`
organisierten Unterkategorie, bindet `$this->EnableAction($Ident)` sie nicht korrekt — auch bei
Status 102. Symptom: `IPS_RequestAction()`/WebFront-Klick läuft fehlerfrei, aber wirkungslos,
`VariableAction` bleibt `0`. Betrifft uns direkt: Adoption hängt Variablen GENAU in solche
Unterkategorien um (z. B. `ctl_ems_power` in die Control-Kategorie) — exakt das auslösende Muster.
Ob ein Zielmodul das schon nach InverterHubs Fix-Vorbild behandelt (Variable kurz zur Instanz
zurückhängen → `EnableAction()` → zurück in die Kategorie), ist ZIELMODUL-Sache, keine Adoptions-
Aufgabe von uns — aber es relativiert die bisherige Aussage „am echten Ziel kommt die Bindung
automatisch": das gilt nur, wenn das Zielmodul diesen Stolperstein selbst umschifft. Bei
InverterHub GoodWe seit 2d8228f behoben. Live-Nachweis am ersten echten Lauf bleibt daher umso
wichtiger.

**Befund Anzeigeprofil:** Das Zielmodul setzt beim Wiederverwenden KEIN Profil (respektiert die
Stable-Regel „Custom-Profile nicht in ApplyChanges überschreiben"). Eine echte Alt-Variable behält
ihr eigenes altes Profil (Objekt-ID erhalten), zeigt also das QUELL-Profil, nicht das des Ziels.
Die Angleichung ans Zielmodul ist daher **Aufgabe von MigrationsHub**: vor dem Entfernen der frisch
angelegten Modul-Variable deren beabsichtigtes Profil je Ident auslesen und nach der Adoption per
`IPS_SetVariableCustomProfile` auf die übernommene Variable setzen — als explizite Migrationsaktion
(nicht in einem Modul-`ApplyChanges`), die die Stable-Regel nicht verletzt.

Der Profil-Nachzug liest das Zielprofil **dynamisch** aus der Modul-Variable (`VariableCustomProfile`),
nicht hartkodiert — der Wechsel der Suite-Module auf gemeinsame `NRG.*`-Profile (Beschluss
24.07.2026: `NRG.Watt/kWh/Ampere/Volt/Percent/Celsius`, siehe EMS/SUITE.md) braucht deshalb **keine
Codeänderung** bei uns; was auch immer ein Zielmodul deklariert (alt modulspezifisch oder neu
`NRG.*`), wird automatisch korrekt übernommen.

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
- **Emojis sind erwünscht, wo sie Nutzen stiften:** (1) als **Panel-Icon** — ein Zeichen am
  Anfang einer `ExpansionPanel`-Überschrift (📖🔌📊), Ersatz fürs fehlende `icon`-Feld; (2) als
  **Status-/Aufmerksamkeitssymbol** (✅❌⚠️💡ℹ️) dort, wo etwas beim Lesen Aufmerksamkeit erfordert
  oder herausgestellt werden soll (Status, Warnungen, wichtige Hinweise) — sie bringen Fokus und
  Auflockerung. Faktenlage: kein Symcon-Store-Review hat Emojis je beanstandet (Entscheidung
  Dietmar, 23.07.2026; die frühere „keine Emojis"-Regel war präventiv und ist aufgehoben).
  **Beobachtungsklausel:** sollte je ein Stable-Review Emojis bemängeln, entscheidet der Verbund
  neu (Rückfall: gemeinsam emoji-frei).
- Klassenname = Modulname; Installation nur über die Modulverwaltung.
- Sprachregel (alles Nutzersichtbare deutsch) und Eigenständigkeit (`.tools/check-standalone.php`
  grün) einhalten.
- **Dachmarke: NRG-Stack; Hersteller/Org: DG65** ([github.com/DG65](https://github.com/DG65)).
- **Lizenz: PolyForm Noncommercial 1.0.0** (privat/nicht-kommerziell frei, gewerblich
  lizenzpflichtig — Kontakt DG65). Kanonischer `LICENSE`-Text im EMS-Repo, 1:1 übernehmen; wirkt
  nur nach vorn (MIT-Altversionen bleiben MIT).
- **Versionierung (Verbund-Konvention, Manifest [EMS/SUITE.md](https://github.com/DG65/NRGEMS/blob/main/SUITE.md)):**
  Modul-Version bleibt SemVer je Modul. Jeder Datenvertrag (`*_GetFunctions` u. ä.) liefert
  `contractVersion => 'Major.Minor'` — Major nur bei Bruch (Kompatibilität nur innerhalb derselben
  Major, blue'Log-Prinzip), Minor additiv, fehlend = `'1.0'`. Ist die Major eines Partnermoduls zu
  alt: standalone weiterlaufen, Kopplung deaktivieren, sichtbar melden
  (`⚠️ Partnermodul X benötigt eine Aktualisierung …`). Suite-Release als CalVer im Manifest.
  MigrationsHub hat selbst **keinen** Datenvertrag; maßgebliche Kompatibilitätsgröße beim Migrieren
  sind die **Idents** (API) der Suite-Zielmodule, nicht deren Modulversionen.
- **Zugangsdaten (Verbund-Konvention, für Module mit Cloud-/API-Zugang):** Handshake-/Token-
  Verfahren bevorzugen (Passwort nur für den einmaligen Handshake, danach nicht speichern — nur
  Token/Secret bleibt liegen); Passwörter nur dauerhaft speichern, wenn wirklich wiederholt
  gebraucht. Speicherort `RegisterAttributeString` (nicht Property, nicht im Formular sichtbar);
  IP-Symcon verschlüsselt nicht at rest — „sicher" heißt „nicht sichtbar", nicht „verschlüsselt".
  Formulareingabe über `PasswordTextBox`, Wert nach Handshake sofort leeren. MigrationsHub
  verwaltet selbst keine Zugangsdaten; relevant nur, falls die Adoption je ein Zugangsdaten-
  Attribut umhängt — Attribute bleiben beim Umhängen unverändert erhalten, keine Sonderbehandlung
  nötig.
- **Einheitliche Formular-Optik (Verbund-Konvention, Referenz InverterHub, Details in
  EMS/SUITE.md):** von oben (1) „🆕 Neu in Version X.Y" aufgeklappt, pro Version dismissible
  (Attribut speichert bestätigte Version), keine Versionsnummer hier; (2) „📖 Dokumentation &
  Hilfe" eingeklappt, dort die Versionsnummer; (3) Fachpanels, neue/wichtige Felder mit
  `🆕`-Präfix im Label; (4) Symcon-Forum-Hinweis nach den Haupteinstellungen, einmalig
  dismissible. Kein Sofort-Umbau nötig, bei Gelegenheit nachziehen. **Pflege ist Pflicht bei
  jedem Fix/Update** (nicht nur großen Releases) — bei jeder Änderung prüfen "gehört da was ins
  Neu-Panel?", Ergebnis darf "nein" sein. Layout allgemein: logische Gruppierung, Step-by-Step
  ohne Scroll-Zickzack, Feldkanten auf einer Linie.
- **Grundregel: keine eigene Anlage als Norm annehmen** (Verbund-Konvention, ausgelöst durch einen
  echten Usability-Mangel bei EMS — ein Panel stellte GoodWe als Pflicht dar, obwohl SMA/Fronius/
  etc. genauso unterstützt werden). Vor jedem `beta`→`main`-Wechsel **"Neuinstallations-
  Simulation"** abarbeiten (Punkt 12 der Symcon-Store-Review-Checkliste, Details EMS/SUITE.md):
  Formular als hardware-/regionsfremder Nutzer durchgehen; Fabrikate/Beispiele nur als "z. B.";
  Discovery-vs-manuell an jeder Stelle klarstellen; Default-Werte dürfen nie eigene
  Sonderkonfiguration widerspiegeln; Volltextsuche nach eigenen IDs/PLZ/Kampagnennamen; wo
  möglich zweite Session gegenprüfen lassen. Für MigrationsHub bereits geprüft (23.07.2026):
  generisches Werkzeug ohne hartkodierten Hersteller, README-Beispiele bereits "z. B."-gerahmt;
  zwei Code-Kommentare mit realen Instanz-IDs aus einer Live-Analyse (nicht laufzeitrelevant, aber
  gegen den Geist der Regel) auf generische Formulierung umgestellt.

## Eigenständigkeit prüfen: `.tools/check-standalone.php`

```
php .tools/check-standalone.php    # 0 = sauber, 1 = ungesicherter Fremdaufruf
```

Herkunft und Funktionsweise wie im MeterHub-Repo — bei Änderungen an der Prüflogik bitte in
allen Hub-Repos gleich halten.


## Verbund-Manifest SUITE.md — Bezugsquelle (19.08.2026)

Primärquelle für alle Verbund-Konventionen ist `SUITE.md` im EMS-Repo
(https://github.com/DG65/NRGEMS — während der EMS-Integrationsphase ist der
Branch `ems-integration` der aktuellste Stand, nicht `main`). In diesem Repo
liegt eine automatisch synchronisierte READ-ONLY-Kopie als `SUITE.md` im
Repo-Root — dort lokal grep'en/lesen. NIEMALS die Kopie hier editieren:
Änderungen gehören ins EMS-Repo; der Sync (GitHub Action `sync-suite` im
EMS-Repo) überschreibt lokale Änderungen kommentarlos.

Fallback, falls die Kopie (noch) fehlt oder veraltet wirkt:
https://raw.githubusercontent.com/DG65/NRGEMS/ems-integration/SUITE.md
