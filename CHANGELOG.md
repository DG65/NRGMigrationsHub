# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [Unreleased]

### Changed
- **Verhaltensänderung `MIGHUB_RunAdoptions($confirmed, $migrations)`:** Diese Funktion lehnt
  jeden Lauf jetzt grundsätzlich ab und verweist auf `MIGHUB_RunAdoptionsEx($confirmed,
  $riskAcknowledged, $migrations)`. Grund: Symcons Kernel-Wrapper haben eine feste Arität —
  der neu eingeführte Risiko-Schalter (`$riskAcknowledged`, Einverständnis zur Übernahme bei
  Fremdmodulen) durfte deshalb nicht einfach als weiterer Parameter an die bestehende Funktion
  angehängt werden, ohne bestehende Aufrufer zu brechen. Die alte Funktion bleibt aus
  Kompatibilitätsgründen aufrufbar, führt aber bewusst keine Übernahme mehr blind ohne
  Risiko-Bestätigung aus — bitte auf `RunAdoptionsEx` umstellen.
- `MIGHUB_AddSourceVariablesToMigrations($sourceVariables, $migrations, $targetInstanceID)`
  bleibt aus demselben Grund in der alten 3-Parameter-Form bestehen; die neue, um
  `$sourceInstanceID` erweiterte Fassung heißt `AddSourceVariablesToMigrationsEx`. Hier ist der
  alte Aufruf weiterhin voll funktionsfähig (fällt nur auf reinen Ident-Abgleich zurück, ohne
  Fremdmodul-Ident-Übersetzung) — keine sicherheitsrelevante Einschränkung wie bei RunAdoptions.

## [0.1.0] - 2026-07-21

### Added
- Repo-Gerüst angelegt: Modul-Skelett (`MigrationsHub/module.php`, `module.json`),
  `library.json`, README, LICENSE, CLAUDE.md, `.tools/check-standalone.php`
- Noch keine Formular-/Massenverarbeitungslogik implementiert
