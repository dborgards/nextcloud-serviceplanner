# ServicePlanner – Design

## 1. Ausgangslage und Entscheidung

Die bestehende Nextcloud-App `sonntagum5` ist eine spezialisierte Oberfläche für genau eine Nextcloud-Tables-Tabelle. Karten, Jahresraster, „Meine Dienste“, Dienstansichten und Selbsteintragung sind bereits vorhanden. Spaltennamen, Dienstgruppen, Icons, Badges und Spezialfilter sind jedoch fest im Code verankert, und die Konfiguration gilt global für einen einzigen Plan.

Die bestehende App bleibt unverändert. Für die Verallgemeinerung entsteht ein neues, später auf GitHub zu veröffentlichendes Repository mit einer eigenständigen App unter dem Arbeitstitel **ServicePlanner** und der vorläufigen App-ID `serviceplanner`. Vor einer Veröffentlichung sind GitHub-, App-Store- und Paketnamen auf Verfügbarkeit zu prüfen.

ServicePlanner wird keine allgemeine Alternative zu Tables und kein beliebiger View-Builder. Der fachliche Rahmen des ersten Release sind konfigurierbare Dienst- und Terminpläne für Gottesdienste, Teams, Veranstaltungen und vergleichbare Einsatzplanungen.

## 2. Ziele

- Mehrere unabhängig konfigurierte Pläne innerhalb derselben Nextcloud-Instanz verwalten.
- Bestehende Tables-Tabellen übernehmen oder neue Tabellen aus einer Dienstplan-Vorlage anlegen.
- Daten, Spalten, Zeilen und grundlegende Lese-/Schreibrechte weiterhin in Nextcloud Tables führen.
- Einen ausführlichen, später wiederaufrufbaren Setup-Assistenten für Datenbasis, Spalten, Dienste, Rechte, Ansichten und Flow anbieten.
- Anzeigenamen, Gruppierungen, Icons, Filter, Sortierung und Sichtbarkeit pro Plan konfigurierbar machen.
- Karten, Jahresraster, „Meine Dienste“ und Dienstansichten aus der Konfiguration erzeugen.
- Selbsteintragung und vollständige Bearbeitung getrennt und nachvollziehbar absichern.
- Nextcloud Flow sowohl mit Ereignissen als auch mit kontrollierten Aktionen integrieren.

## 3. Nicht-Ziele des ersten Release

- Kein eigenes fachliches Zeilen- oder Zellenmodell neben Tables.
- Kein eigenes, mit Tables konkurrierendes Grundrechtssystem.
- Keine Unterstützung beliebiger Tabellen- und Datenbankanwendungsfälle außerhalb von Dienst- und Terminplänen.
- Keine ServicePlanner-Ereignisse für Änderungen, die ausschließlich außerhalb der App direkt in Tables erfolgen.
- Keine Schichtplanung mit Stundenkonten, Verfügbarkeitsabgleich, Konfliktprüfung oder automatischer Zuteilung.
- Keine Migration oder Veränderung der bestehenden `sonntagum5`-App.

## 4. Architektur

### 4.1 Tables als fachliche Datenbasis

Jeder Plan referenziert genau eine Tables-Tabelle über deren stabile ID. Spalten werden über IDs referenziert, nicht über deren umbenennbare Titel. Tables bleibt führend für:

- Tabellen- und Spaltendefinitionen,
- Zeilen und Zellwerte,
- grundlegende Lese- und Schreibrechte,
- Gruppenfreigaben auf Tabellen.

Der Zugriff auf Tables wird hinter einem Adapter gekapselt. Die übrige Anwendung verwendet keine Tables-Services direkt. Beim Aufsetzen des Repositories ist zu prüfen, welche Tables-Schnittstellen für die unterstützten Versionen öffentlich und stabil sind; benötigte interne Schnittstellen werden auf diesen Adapter begrenzt und mit Integrationstests abgesichert.

### 4.2 Eigene Konfigurationsdaten

ServicePlanner speichert ausschließlich eigene Metadaten und Regeln, keine Kopien der Planinhalte. Dazu gehören:

- Plan-ID, Name, Beschreibung, Icon, Farbe und Tabellen-ID,
- Spaltenzuordnung, alternative Anzeigenamen und Reihenfolgen,
- Dienstgruppen und deren Spalten, Icons und Regeln,
- Planverwaltung, Sichtbarkeit und Selbsteintragungsregeln,
- Ansichten, Filter, Zeiträume und Sortierungen,
- für Flow freigegebene Aktionen und Zielspalten.

Alle Konfigurationen sind pro Plan getrennt. Es gibt keine globale Tabellen-ID oder globale Liste von Textspalten wie in der Ausgangsapp.

### 4.3 Backend-Grenzen

Das Backend wird nach Verantwortlichkeiten getrennt:

- **Planverwaltung:** Anlegen, Validieren, Bearbeiten und Entfernen von Konfigurationen.
- **Tables-Adapter:** Tabellen, Spalten, Zeilen, Freigaben und Tabellenvorlagen.
- **Berechtigungsdienst:** kombinierte Prüfung von Tables-Rechten und ServicePlanner-Regeln.
- **Plan-Lesedienst:** Auflösung der Konfiguration und Bereitstellung nur der für den jeweiligen Benutzer sichtbaren Pläne, Ansichten und Daten.
- **Schreibdienst:** vollständige Zelländerungen sowie Selbsteintragung und Austragung einschließlich Sperrung.
- **Flow-Integration:** Entities, Ereignisse und Aktionen unter Verwendung derselben Berechtigungs- und Schreibdienste.

Flow und normale Oberfläche verwenden ausdrücklich denselben Schreibweg. Es gibt keinen zweiten Mechanismus, der Tables-Rechte umgehen kann.

### 4.4 Frontend

Das Frontend ist konfigurationsgetrieben. Es enthält:

- Planübersicht und plangesteuerte Navigation,
- Setup-Assistent und Planeinstellungen,
- Karten-, Raster- und Dienstansichten,
- gemeinsame Komponenten für Spaltenwerte, Personen, Filter und Bearbeitungszustände.

Fachliche Namen wie „Prediger“, „Abendmahl“ oder „Kinderpredigt“ dürfen in Vorlagen und Beispieldaten vorkommen, nicht aber als feste Annahmen in allgemeinen Auswertungs- oder Schreibregeln.

## 5. Rollen und Berechtigungen

### 5.1 Grundrechte aus Tables

Lesen und vollständiges Bearbeiten von Zeilen folgen den Tables-Freigaben. Der Setup-Assistent zeigt die relevanten Freigaben an und bietet den Übergang zu deren Verwaltung in Tables. Soweit eine unterstützte Tables-Schnittstelle das direkte Einrichten erlaubt, kann der Assistent diesen Schritt auch direkt ausführen.

### 5.2 Zusätzliche ServicePlanner-Regeln

Pro Plan werden konfiguriert:

- welche Gruppen den Plan in ServicePlanner sehen,
- welche Gruppen die Plankonfiguration verwalten,
- welche Dienste beziehungsweise Spalten Selbsteintragung erlauben,
- ob das Austragen erlaubt ist,
- welche Ziele Flow-Aktionen verwenden dürfen.

Diese Regeln können Tables-Rechte nur einschränken, niemals erweitern. Die einzige kontrollierte Ausnahme ist die fachliche Selbsteintragung für lesende Benutzer: Sie schreibt ausschließlich den eigenen Anzeigenamen in ausdrücklich freigegebene Personenspalten und verwendet dafür denselben abgesicherten Stellvertretungsmechanismus wie die Ausgangsapp. Vollständige Zellbearbeitung erfordert weiterhin das Tables-Änderungsrecht.

Jeder Lese- und Schreibzugriff wird serverseitig geprüft. Das Ausblenden von Bedienelementen im Frontend ist nur eine Ergänzung, keine Sicherheitsgrenze.

## 6. Setup-Assistent

Der Assistent dient sowohl zum Anlegen als auch zum späteren Anpassen. Beim Bearbeiten wird ein Entwurf erzeugt; Änderungen werden erst nach erfolgreicher Gesamtvalidierung gemeinsam übernommen. Ein Fehler verwirft den Entwurf nicht.

### Schritt 1: Plan und Datenbasis

- Name, Beschreibung, Icon und Farbe festlegen.
- Vorhandene Tables-Tabelle auswählen oder eine neue Tabelle aus einer Dienstplan-Vorlage anlegen.
- Bei einer neuen Tabelle grundlegende Datum-, Titel-, Text- und Personenspalten vorbereiten.
- Bei einer bestehenden Tabelle Struktur und Zugriffsrechte prüfen.

### Schritt 2: Spalten und Dienste

- Genau eine Datumsspalte und eine optionale Titelspalte zuordnen.
- Personen-, Text- und weitere unterstützte Zusatzspalten bestimmen.
- Alternative Anzeigenamen und Reihenfolgen festlegen.
- Personenspalten zu benannten Diensten gruppieren und Icons zuweisen.
- Mehrere Personen in einer Zelle mit dem vorhandenen Trennzeichenmodell behandeln.

Nicht unterstützte Spaltentypen werden nicht als Personenspalten interpretiert. Die allgemeine Anzeige kann unterstützte Text- und Datumswerte darstellen; Spezialverhalten ist auf ausdrücklich zugeordnete Spalten beschränkt.

### Schritt 3: Rechte und Selbsteintragung

- Sichtbare Gruppen und Planverwaltung festlegen.
- Tables-Freigaben prüfen und verständlich darstellen.
- Selbsteintragung und Austragung pro Dienst beziehungsweise Spalte aktivieren.
- Kontrolle anzeigen, welche Benutzer dadurch welche Möglichkeiten erhalten.

### Schritt 4: Ansichten

Folgende Ansichten können aktiviert, benannt, sortiert und mit Icons versehen werden:

- kommende Termine als Karten,
- Jahresraster,
- „Meine Dienste“,
- einzelne oder zusammengefasste Dienstansichten.

Pro Ansicht werden konfiguriert:

- enthaltene Dienste und Spalten,
- Standardzeitraum beziehungsweise Jahr,
- Sortierung,
- Standardansicht des Plans,
- optionale Filter.

Der MVP unterstützt die Filter „enthält“, „ist gleich“, „ist leer“, „ist nicht leer“ und „nur offene Dienste“. Filter beziehen sich über Spalten-IDs auf definierte Werte. „Nur offene Dienste“ berücksichtigt leere Personenzellen; konfigurierte Nicht-besetzt-Platzhalter gelten nicht als offen.

### Schritt 5: Flow, Vorschau und Speichern

- Erlaubte Flow-Aktionen und deren Zielspalten festlegen.
- Konfiguration gegen die aktuelle Tabellenstruktur validieren.
- Eine Vorschau mit echten, für den aktuellen Benutzer sichtbaren Daten anzeigen.
- Eine Zusammenfassung der Datenbasis, Rechte, Ansichten und Flow-Freigaben anzeigen.
- Erst danach den Entwurf speichern.

Beim späteren Öffnen erkennt der Assistent entfernte oder typveränderte Spalten. Betroffene Zuordnungen müssen korrigiert werden, bevor die Konfiguration erneut gespeichert werden kann.

## 7. Laufzeitverhalten und Ansichten

- Die Navigation zeigt nur Pläne und Ansichten, für die der Benutzer berechtigt ist.
- Karten fassen kommende Termine und konfigurierte Dienstgruppen zusammen.
- Das Jahresraster zeigt Termine und Dienste in einer tabellarischen Übersicht.
- „Meine Dienste“ verwendet die konfigurierten Personenspalten und den aktuellen Nextcloud-Anzeigenamen.
- Dienstansichten verwenden die jeweils konfigurierten Spalten und Filter.
- Leere freigegebene Personenzellen bieten die Selbsteintragung an; vorhandene eigene Einträge bieten das Austragen an, sofern erlaubt.
- Vollständige Bearbeitung wird nur Benutzern mit entsprechendem Tables-Recht angeboten.
- Direktlinks enthalten stabile Plan- und Ansichts-IDs und bleiben beim Umbenennen von Anzeigenamen gültig.

## 8. Nextcloud Flow

### 8.1 Entities und Ereignisse

ServicePlanner registriert Pläne als Flow-Entities. Der MVP stellt folgende Ereignisse bereit:

- Person hat sich eingetragen,
- Person hat sich ausgetragen,
- Planverwaltung hat eine Zelle geändert.

Ereignisdaten umfassen Plan, Zeile beziehungsweise Datum, Dienst beziehungsweise Spalte, betroffene Person und auslösenden Benutzer. Zelländerungen enthalten zusätzlich alten und neuen Wert.

Die Flow-Entity prüft für jedes Ereignis, ob der jeweilige Benutzer den zugehörigen Plan sehen darf. Flow-Konfiguration und Ereignisdaten dürfen keine Informationen über nicht sichtbare Pläne preisgeben. Ereignisse werden erst nach erfolgreicher fachlicher Änderung ausgelöst; idempotente Vorgänge ohne tatsächliche Änderung lösen kein Änderungsereignis aus.

Änderungen, die ausschließlich direkt in Tables oder einem anderen externen Client erfolgen, lösen im MVP keine ServicePlanner-Ereignisse aus.

### 8.2 Aktionen

Der MVP stellt folgende Flow-Aktionen bereit:

- aktuelle oder angegebene Person eintragen,
- aktuelle oder angegebene Person austragen,
- eine freigegebene Zelle setzen.

Beim Einrichten und erneut beim Ausführen wird geprüft:

- Plan, Zeile und Spalte existieren und gehören zusammen,
- die Spalte hat die erforderliche Bedeutung,
- die konkrete Aktion und das Ziel sind in der Plankonfiguration freigegeben,
- die Tables-Rechte und App-Regeln erlauben den Vorgang,
- Werte und Personenangaben sind gültig.

Bereits eingetragene beziehungsweise ausgetragene Personen bleiben idempotent und werden nicht doppelt verarbeitet. Aktionen, die entgegen der Plankonfiguration beliebige Tabellen oder Spalten beschreiben könnten, werden nicht angeboten.

## 9. Fehlerbehandlung und Datenintegrität

- Gleichzeitige Schreibvorgänge auf derselben Zeile werden mit Nextcloud-Sperren serialisiert; kurze Konflikte erhalten eine begrenzte Wiederholung.
- Entfernte oder typveränderte Tabellen und Spalten erzeugen eine verständliche Konfigurationswarnung statt still fehlerhafter Ansichten.
- Fehlende Rechte werden als solche angezeigt, ohne fremde Plan- oder Zellinhalte preiszugeben.
- Ungültige Filter, unbekannte IDs und unerlaubte Flow-Ziele werden serverseitig abgelehnt.
- Fehler beim Speichern lassen den Setup-Entwurf erhalten.
- Beim Schreiben wird der tatsächlich gespeicherte Wert aus Tables zurückgegeben und in der Oberfläche übernommen.
- Fehlgeschlagene Flow-Aktionen verändern keine weiteren Zellen und liefern einen diagnostizierbaren Fehler ohne vertrauliche Zusatzdaten.

## 10. Qualitätssicherung

### Backend

- Unit-Tests für Berechtigungen, Filter, Spaltenzuordnung, Selbsteintragung, Austragung und Idempotenz.
- Tests dafür, dass App-Regeln keine Tables-Rechte erweitern und Plan-/Spalten-IDs nicht vertauschbar sind.
- Integrationstests mit den unterstützten Nextcloud-/Tables-Versionen, insbesondere für den Tables-Adapter und Freigaben.
- Flow-Tests für Ereignislegitimation, Konfigurationsvalidierung und erneute Prüfung bei der Ausführung.

### Frontend

- Komponententests für konfigurierte Karten, Raster, Dienstansichten und Personeninteraktionen.
- Tests für Filter, alternative Spaltennamen, Rechtezustände und Fehlerdarstellung.
- Tests des Setup-Entwurfs einschließlich Validierung und erneutem Bearbeiten.

### End-to-end und Release

Mindestens folgender Ablauf wird automatisiert oder auf einer echten Testinstanz nachvollzogen:

1. Plan und Tabelle anlegen,
2. Gruppenrechte und Selbsteintragung konfigurieren,
3. Ansichten und Filter prüfen,
4. Person selbst eintragen und austragen,
5. Flow-Ereignis auslösen und mit einer vorhandenen Nextcloud-Aktion verwenden,
6. ServicePlanner-Flow-Aktion ausführen und Berechtigungsgrenzen prüfen,
7. Planeinstellungen ändern und bestehende Direktlinks erneut prüfen.

Vor dem ersten Release wird das erzeugte App-Paket auf jeder als unterstützt deklarierten Nextcloud-/Tables-Kombination installiert und der zentrale Ablauf geprüft. Die genauen Versionsgrenzen werden bei der Repository-Einrichtung anhand der dann aktuellen Nextcloud- und Tables-Releases festgelegt.

## 11. Erster Lieferumfang

Der erste Release umfasst genau einen zusammenhängenden vertikalen Umfang:

- mehrere Pläne,
- Tables-Auswahl und Dienstplanvorlage,
- vollständiger Setup-Assistent,
- planbezogene Rechte und Selbsteintragung,
- konfigurierte Karten, Raster, „Meine Dienste“ und Dienstansichten,
- definierte Filter und Icons,
- drei Flow-Ereignisse und drei kontrollierte Flow-Aktionsarten,
- Tests, Paketbau und Installationsdokumentation.

Weitere Datentypen, allgemeine Tables-Funktionen, externe Änderungsereignisse und erweiterte Schichtplanung werden erst nach Auswertung dieses Release betrachtet.
