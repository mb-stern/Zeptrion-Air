# zeptrionAIR für IP-Symcon

Dieses Modul bindet Feller zeptrionAIR Geräte in IP-Symcon ein.

Vorhandene zeptrionAIR Geräte werden automatisch im Netzwerk erkannt und können als Geräteinstanzen in IP-Symcon angelegt werden. Zusätzlich können zeptrionAIR Smart-Taster mit Funktionen in IP-Symcon verbunden werden.

## Funktionsumfang

- Automatische Erkennung von zeptrionAIR Geräten im lokalen Netzwerk
- Anzeige der erkannten Geräte und Kanäle im Discovery
- Automatische Erkennung der vorhandenen Kanal- und Gerätetypen
- Anlegen der erkannten Geräte als IP-Symcon Instanzen
- Zentrale Kommunikation über den zeptrionAIR Splitter
- Steuerung der unterstützten zeptrionAIR Funktionen aus IP-Symcon
- Unterstützung von zeptrionAIR Smart-Tastern
- Zentrale Konfiguration der Smart-Taster
- Auswahl von Variablen und Skripten über den IP-Symcon Objektbaum
- Automatische Übernahme der möglichen Werte aus dem Variablenprofil
- Ausführung mehrerer IP-Symcon Aktionen über einen Smart-Taster
- Anzeige der programmierten Smart-Taster in der zugehörigen Geräteinstanz
- Zentraler WebHook für alle Smart-Taster

## Voraussetzungen

- IP-Symcon
- Eine bereits eingerichtete zeptrionAIR Anlage
- zeptrionAIR Geräte im gleichen bzw. erreichbaren lokalen Netzwerk
- Netzwerkzugriff von IP-Symcon auf die zeptrionAIR Geräte
- Originale zeptrionAIR App für die grundlegende Einrichtung der Anlage

## Einrichtung der zeptrionAIR Anlage

Die grundlegende Einrichtung der zeptrionAIR Anlage erfolgt weiterhin über die originale zeptrionAIR App.

Dazu gehören insbesondere:

- Erstinbetriebnahme der zeptrionAIR Anlage
- Einrichten und Konfigurieren der zApps
- Konfiguration der angeschlossenen Verbraucher
- Zuordnung der Funktionen und Kanäle
- Interne Verknüpfungen zwischen zeptrionAIR Geräten
- Programmierung von Smart-Tastern für direkte zeptrionAIR Funktionen

Das IP-Symcon Modul ersetzt die originale zeptrionAIR App nicht.

Die Anlage wird zuerst mit der originalen App eingerichtet. Anschließend übernimmt das IP-Symcon Modul die bereits vorhandenen Geräte und bindet sie in IP-Symcon ein.

## Installation in IP-Symcon

Das Modul in IP-Symcon installieren.

Anschließend steht die zeptrionAIR Discovery zur Verfügung.

Über die Discovery können die bereits mit der originalen zeptrionAIR App eingerichteten Geräte im Netzwerk gesucht und als IP-Symcon Instanzen angelegt werden.

## Discovery

Die Discovery sucht nach erreichbaren zeptrionAIR Geräten im lokalen Netzwerk.

Die gefundenen Geräte werden mit den verfügbaren Geräte- und Kanalinformationen angezeigt.

Dazu gehören je nach Gerät unter anderem:

- zApp
- IP-Adresse
- Gerätetyp
- Seriennummer
- RSSI
- erkannte Kanäle
- konfigurierte Verbraucher

Bereits vorhandene Geräteinstanzen werden entsprechend berücksichtigt.

Über die Discovery können neue Geräte direkt als zeptrionAIR Geräteinstanz in IP-Symcon angelegt werden.

Der benötigte zeptrionAIR Splitter wird zentral verwendet.

## zeptrionAIR Geräteinstanz

Für jedes eingebundene zeptrionAIR Gerät wird eine eigene Geräteinstanz angelegt.

Die verfügbaren Kanäle und Funktionen richten sich nach der Konfiguration des jeweiligen zeptrionAIR Gerätes.

Die vom Gerät erkannten Kanaltypen werden automatisch übernommen und in IP-Symcon entsprechend dargestellt.

Die Geräteinstanz dient anschließend zur Steuerung und Statusanzeige der jeweiligen zeptrionAIR Funktionen.

## Smart-Taster

Das Modul unterstützt zeptrionAIR Smart-Taster.

Dabei muss zwischen zwei grundsätzlich unterschiedlichen Anwendungen unterschieden werden.

### Direkte zeptrionAIR Funktionen

Soll ein Smart-Taster direkt ein zeptrionAIR Gerät bzw. eine interne zeptrionAIR Funktion steuern, muss diese Verbindung mit der originalen zeptrionAIR App programmiert werden.

Beispielsweise:

- Licht über einen anderen zeptrionAIR Aktor schalten
- Rollo direkt über zeptrionAIR steuern
- interne zeptrionAIR Szenen bzw. Funktionen verwenden

Diese Verbindungen werden innerhalb des zeptrionAIR Systems eingerichtet.

Sie funktionieren anschließend unabhängig von IP-Symcon.

Das IP-Symcon Modul übernimmt die Programmierung solcher internen zeptrionAIR Verbindungen nicht.

### IP-Symcon Funktionen

Zusätzlich können Smart-Taster über dieses Modul mit IP-Symcon Funktionen verbunden werden.

Hierfür können über den IP-Symcon Objektbaum Variablen oder Skripte ausgewählt werden.

Bei einer Variable kann zusätzlich der gewünschte Wert festgelegt werden.

Besitzt die Variable ein Variablenprofil, werden die möglichen Werte automatisch aus diesem Profil übernommen und zur Auswahl angeboten.

Bei einem Skript wird das ausgewählte IP-Symcon Skript beim Drücken des Smart-Tasters ausgeführt.

Für diese Funktionen muss IP-Symcon erreichbar sein.

## Smart-Taster Webinterface

Die Konfiguration der IP-Symcon Smart-Taster erfolgt zentral über den zeptrionAIR Splitter.

Das Webinterface ist über den zentralen WebHook erreichbar:

`/hook/zeptrionair`

Es wird nur dieser zentrale WebHook verwendet.

Die einzelnen zeptrionAIR Geräteinstanzen benötigen keine eigenen WebHooks für die Smart-Taster.

## Smart-Taster programmieren

Im zentralen Smart-Taster Webinterface kann eine neue Szene angelegt werden.

### Vorgehen

1. Neue Szene anlegen.
2. Einen Namen für die Szene vergeben.
3. Über **Objekt hinzufügen** ein IP-Symcon Objekt auswählen.
4. Im Objektbaum die gewünschte Variable oder das gewünschte Skript auswählen.
5. Bei einer Variable den gewünschten Wert auswählen.
6. Bei Bedarf weitere Objekte hinzufügen.
7. Auf **Smart-Taste programmieren** klicken.
8. Die zeptrionAIR Smart-Taster beginnen zu blinken.
9. Die gewünschte blinkende Smart-Taste am Schalter drücken.

Der Splitter erkennt automatisch, an welchem zApp die Smart-Taste gedrückt wurde.

Anschließend wird die Smart-Taste mit der angelegten IP-Symcon Funktion programmiert.

## Variablen

Wird im Objektbaum eine IP-Symcon Variable ausgewählt, kann der Wert festgelegt werden, der beim Drücken des Smart-Tasters gesetzt werden soll.

Bei Variablen mit einem Variablenprofil verwendet das Modul automatisch die dort hinterlegten Werte.

Dadurch können beispielsweise direkt Werte wie:

- Ein / Aus
- Öffnen / Stoppen / Schließen
- Betriebsarten
- Stufen
- Sollwerte

ausgewählt werden, sofern diese im verwendeten Variablenprofil vorhanden sind.

## Skripte

Neben Variablen können auch IP-Symcon Skripte über den Objektbaum ausgewählt werden.

Beim Drücken des programmierten Smart-Tasters wird das entsprechende Skript ausgeführt.

Damit können über einen zeptrionAIR Smart-Taster auch komplexere Abläufe und Automationen in IP-Symcon gestartet werden.

## Mehrere Aktionen

Einer Smart-Taster Szene können mehrere IP-Symcon Objekte zugeordnet werden.

Dadurch können mit einem Tastendruck mehrere Aktionen ausgeführt werden.

Beispielsweise können gleichzeitig:

- mehrere Variablen gesetzt
- mehrere Geräte gesteuert
- Variablen gesetzt und zusätzlich Skripte gestartet

werden.

## Anzeige in der Geräteinstanz

Ein programmierter Smart-Taster wird zusätzlich in der zugehörigen zeptrionAIR Geräteinstanz angezeigt.

Da zeptrionAIR bei der Erkennung keine eindeutige Zuordnung des Smart-Tasters zu einem bestimmten Kanal liefert, wird die Smart-Taster Funktion an einem ansonsten nicht verwendeten Kanal der Geräteinstanz dargestellt.

Dort werden die gespeicherte Szene und die zugeordneten IP-Symcon Funktionen angezeigt.

Es können maximal zwei Smart-Taster pro entsprechendem Gerät dargestellt werden.

## Smart-Taster löschen

Über **Smart-Taster löschen** kann eine vorhandene Smart-Taster Programmierung ausgewählt werden.

Nach dem Start des Löschvorgangs beginnen die Smart-Taster zu blinken.

Anschließend muss die gewünschte blinkende Smart-Taste am Schalter gedrückt werden.

Der Splitter erkennt dadurch automatisch das zugehörige zApp.

## Aus Splitter entfernen

Eine gespeicherte Szene kann über **Aus Splitter entfernen** aus der Konfiguration des zeptrionAIR Splitters entfernt werden.

Dabei wird der entsprechende Eintrag aus der in IP-Symcon gespeicherten Smart-Taster Konfiguration entfernt.

## Zentraler WebHook

Für die über IP-Symcon programmierten Smart-Taster wird ausschließlich der zentrale WebHook des zeptrionAIR Splitters verwendet:

`/hook/zeptrionair`

Beim Drücken eines entsprechend programmierten Smart-Tasters ruft das zeptrionAIR Gerät diesen WebHook auf.

Der Splitter ermittelt die gespeicherte Szene und führt die hinterlegten Aktionen in IP-Symcon aus.

## Verhalten ohne IP-Symcon

Über dieses Modul programmierte IP-Symcon Funktionen benötigen eine erreichbare IP-Symcon Installation.

Ist IP-Symcon nicht erreichbar, können diese Aktionen nicht ausgeführt werden.

Direkte zeptrionAIR Funktionen, die auch ohne IP-Symcon funktionieren sollen, müssen deshalb mit der originalen zeptrionAIR App programmiert werden.

Diese internen Verbindungen werden direkt innerhalb des zeptrionAIR Systems ausgeführt und sind nicht vom IP-Symcon Modul abhängig.

## Zusammenfassung Smart-Taster

**Originale zeptrionAIR App**

Für direkte Verbindungen innerhalb von zeptrionAIR:

`Smart-Taster → zeptrionAIR Funktion`

Diese Funktionen arbeiten unabhängig von IP-Symcon.

**IP-Symcon Modul**

Für Funktionen innerhalb von IP-Symcon:

`Smart-Taster → zeptrionAIR Splitter → IP-Symcon Variable / Skript`

Diese Funktionen benötigen IP-Symcon.

## Hinweise

Die zeptrionAIR Anlage sollte vor der Einbindung in IP-Symcon vollständig mit der originalen zeptrionAIR App eingerichtet und getestet werden.

Änderungen an der grundlegenden zeptrionAIR Konfiguration sollten weiterhin über die originale App vorgenommen werden.

Das IP-Symcon Modul dient anschließend zur Integration, Steuerung und Erweiterung der bestehenden zeptrionAIR Anlage.

## HVersionen

Version 1.0
- Initiale Version

## Lizenz

Siehe `LICENSE`.