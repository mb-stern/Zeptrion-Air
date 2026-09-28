# zeptrionAIR für IP-Symcon

Dieses Modul bindet Feller zeptrionAIR Geräte in IP-Symcon ein.

Vorhandene zeptrionAIR Geräte werden automatisch im Netzwerk erkannt und können als Geräteinstanzen angelegt werden. Die Kommunikation der Geräte läuft zentral über einen zeptrionAIR Splitter. Zusätzlich können zeptrionAIR Smart-Taster mit IP-Symcon Objekten und anderen eingebundenen zeptrionAIR Geräten verknüpft werden.

## Funktionsumfang

- Automatische Erkennung von zeptrionAIR Geräten im lokalen Netzwerk
- Anzeige der erkannten Geräte und Kanäle im Discovery
- Automatische Erkennung der vorhandenen Kanal- und Gerätetypen
- Anlegen der erkannten Geräte als IP-Symcon Instanzen
- Zentrale Gerätekommunikation über den zeptrionAIR Splitter
- Steuerung der unterstützten zeptrionAIR Funktionen aus IP-Symcon
- Unterstützung von Licht, Dimmer, Rollo und Markise entsprechend der erkannten Gerätekonfiguration
- Unterstützung von zeptrionAIR Smart-Tastern
- Zentrale Smart-Taster-Konfiguration über ein Webinterface
- Auswahl von IP-Symcon Variablen und Skripten über den Objektbaum
- Auswahl eingebundener zeptrionAIR Geräte als Smart-Taster-Ziel
- Automatische Übernahme möglicher Werte aus Variablenprofilen
- Mehrere Aktionen innerhalb einer Smart-Taster-Szene
- Anzeige programmierter Smart-Taster in der zugehörigen Geräteinstanz
- Zentraler WebHook für alle über IP-Symcon programmierten Smart-Taster

## Voraussetzungen

- IP-Symcon
- Eine bereits eingerichtete zeptrionAIR Anlage
- zeptrionAIR Geräte im gleichen bzw. erreichbaren lokalen Netzwerk
- Netzwerkzugriff von IP-Symcon auf die zeptrionAIR Geräte
- Originale zeptrionAIR App für die grundlegende Einrichtung der Anlage

## Einrichtung der zeptrionAIR Anlage

Die grundlegende Einrichtung der zeptrionAIR Anlage erfolgt weiterhin über die originale zeptrionAIR App. Dazu gehören insbesondere Erstinbetriebnahme, Einrichtung der zApps, Konfiguration der angeschlossenen Verbraucher sowie die grundlegende Kanal- und Gerätekonfiguration.

Das IP-Symcon Modul ersetzt die originale zeptrionAIR App nicht. Die Anlage wird zuerst mit der originalen App eingerichtet und getestet. Anschließend übernimmt das Modul die vorhandenen Geräte und bindet sie in IP-Symcon ein.

## Installation und Discovery

Nach der Installation steht die zeptrionAIR Discovery zur Verfügung. Sie sucht nach erreichbaren zeptrionAIR Geräten im lokalen Netzwerk und zeigt je nach Gerät unter anderem folgende Informationen an:

- zApp
- IP-Adresse
- Gerätetyp
- Seriennummer
- RSSI
- erkannte Kanäle
- konfigurierte Verbraucher

Bereits vorhandene Geräteinstanzen werden berücksichtigt. Neue Geräte können direkt aus der Discovery als zeptrionAIR Geräteinstanz angelegt werden.

Der benötigte zeptrionAIR Splitter wird zentral verwendet. Die Geräteinstanzen kommunizieren nicht unabhängig voneinander direkt mit den Geräten, sondern senden ihre Anforderungen über den Splitter. Dadurch wird die Kommunikation zu einem zeptrionAIR Gerät zentral koordiniert.

## zeptrionAIR Geräteinstanz

Für jedes eingebundene zeptrionAIR Gerät wird eine eigene Geräteinstanz angelegt. Die verfügbaren Kanäle und Funktionen richten sich nach der Konfiguration des jeweiligen Gerätes.

Die erkannten Kanaltypen werden automatisch übernommen und in IP-Symcon entsprechend dargestellt. Die Geräteinstanz dient anschließend zur Steuerung und Statusanzeige der jeweiligen zeptrionAIR Funktionen.

Die normale Statusabfrage erfolgt über die regulären Modul-Timer. Motoraktoren werden nicht unnötig über den normalen Kanal-Scan dauergepollt.

## Smart-Taster

Das Modul unterstützt die Programmierung von zeptrionAIR Smart-Tastern für Aktionen, die über IP-Symcon ausgeführt werden.

Eine Smart-Taster-Szene kann folgende Ziele enthalten:

- IP-Symcon Variable
- IP-Symcon Skript
- eingebundenes zeptrionAIR Gerät

Mehrere Ziele können innerhalb einer Szene kombiniert werden.

### IP-Symcon Variablen

Über den IP-Symcon Objektbaum kann eine Variable ausgewählt und der gewünschte Wert festgelegt werden. Besitzt die Variable ein Variablenprofil, werden die dort hinterlegten Werte automatisch zur Auswahl angeboten.

Damit können beispielsweise Ein/Aus, Öffnen/Stoppen/Schließen, Betriebsarten, Stufen oder Sollwerte verwendet werden, sofern das jeweilige Variablenprofil diese Werte bereitstellt.

### IP-Symcon Skripte

Neben Variablen können IP-Symcon Skripte ausgewählt werden. Beim Drücken des programmierten Smart-Tasters wird das entsprechende Skript ausgeführt.

### zeptrionAIR Ziele

Als Ziel einer Smart-Taster-Szene kann auch ein durch das Modul eingebundenes zeptrionAIR Gerät ausgewählt werden. Damit kann ein Smart-Taster beispielsweise eine Funktion eines anderen zeptrionAIR Gerätes über IP-Symcon auslösen.

Diese über das Modul konfigurierte Verknüpfung läuft über IP-Symcon und den zeptrionAIR Splitter. Sie ist deshalb nicht mit einer rein internen, von IP-Symcon unabhängigen zeptrionAIR Verknüpfung gleichzusetzen.

Direkte interne zeptrionAIR Verknüpfungen, die auch ohne IP-Symcon funktionieren sollen, werden weiterhin mit der originalen zeptrionAIR App eingerichtet.

## Smart-Taster Webinterface

Die Smart-Taster-Konfiguration erfolgt zentral über den zeptrionAIR Splitter. Das Webinterface ist über den zentralen WebHook erreichbar:

`/hook/zeptrionair`

Die einzelnen Geräteinstanzen benötigen dafür keine eigenen WebHooks.

## Smart-Taster programmieren

1. Neue Szene anlegen.
2. Einen Namen für die Szene vergeben.
3. Ein Ziel hinzufügen.
4. IP-Symcon Objekt oder zeptrionAIR Ziel auswählen.
5. Bei einer Variable bzw. einem unterstützten Ziel den gewünschten Wert oder Befehl auswählen.
6. Bei Bedarf weitere Ziele hinzufügen.
7. Auf **Smart-Taste programmieren** klicken.
8. Die zeptrionAIR Smart-Taster beginnen zu blinken.
9. Die gewünschte blinkende Smart-Taste am Schalter drücken.

Der Splitter erkennt das zugehörige zApp und speichert die Zuordnung der Smart-Taste zur angelegten Szene.

## Anzeige in der Geräteinstanz

Ein programmierter Smart-Taster wird zusätzlich in der zugehörigen zeptrionAIR Geräteinstanz angezeigt.

Da zeptrionAIR bei der Erkennung keine eindeutige Zuordnung des Smart-Tasters zu einem bestimmten Kanal liefert, wird die Smart-Taster-Funktion an einem ansonsten nicht verwendeten Kanal der Geräteinstanz dargestellt. Dort werden die gespeicherte Szene und die zugeordneten Ziele angezeigt.

Es können maximal zwei Smart-Taster pro entsprechendem Gerät dargestellt werden.

## Smart-Taster löschen

Über **Smart-Taster löschen** kann eine vorhandene Programmierung ausgewählt werden. Nach dem Start des Löschvorgangs beginnen die Smart-Taster zu blinken. Anschließend wird die gewünschte blinkende Smart-Taste am Schalter gedrückt, damit der Splitter das zugehörige zApp erkennt und die Programmierung löscht.

## Aus Splitter entfernen

Eine gespeicherte Szene kann über **Aus Splitter entfernen** aus der Konfiguration des zeptrionAIR Splitters entfernt werden. Dabei wird der entsprechende Eintrag aus der in IP-Symcon gespeicherten Smart-Taster-Konfiguration entfernt.

## Zentraler WebHook

Für die über IP-Symcon programmierten Smart-Taster wird ausschließlich der zentrale WebHook des Splitters verwendet:

`/hook/zeptrionair`

Beim Drücken eines entsprechend programmierten Smart-Tasters ruft das zeptrionAIR Gerät diesen WebHook auf. Der Splitter ermittelt die gespeicherte Szene und führt die hinterlegten Aktionen aus.

## Verhalten ohne IP-Symcon

Über dieses Modul programmierte Smart-Taster-Aktionen benötigen eine erreichbare IP-Symcon Installation. Das gilt auch für ein zeptrionAIR Ziel, wenn die Zuordnung über dieses Modul erstellt wurde.

Direkte zeptrionAIR Funktionen, die unabhängig von IP-Symcon arbeiten sollen, müssen weiterhin innerhalb des zeptrionAIR Systems mit der originalen App eingerichtet werden.

## Hinweise

Die zeptrionAIR Anlage sollte vor der Einbindung in IP-Symcon vollständig mit der originalen zeptrionAIR App eingerichtet und getestet werden.

Änderungen an der grundlegenden zeptrionAIR Konfiguration sollten weiterhin über die originale App vorgenommen werden. Das IP-Symcon Modul dient anschließend zur Integration, Steuerung und Erweiterung der bestehenden Anlage.

## Versionen

### Entwicklung

- Zentrale Kommunikation der Geräteinstanzen über den zeptrionAIR Splitter
- Smart-Taster-Konfiguration über zentralen WebHook
- Smart-Taster-Ziele für IP-Symcon Variablen, Skripte und eingebundene zeptrionAIR Geräte
- Koordinierte Gerätekommunikation bei Polling und Smart-Taster-Programmierung

### Version 1.0

- Initiale Version

## Lizenz

Siehe `LICENSE`.