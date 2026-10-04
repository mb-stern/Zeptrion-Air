# Zeptrion Air

IP-Symcon-Modul für **Feller zeptrionAIR**.

Das Modul bindet zeptrionAIR-Geräte lokal über die HTTP-Schnittstelle in IP-Symcon ein. Die Kommunikation mit den zApps erfolgt direkt im lokalen Netzwerk.

## Funktionsumfang

- automatische Suche nach zeptrionAIR-Geräten im lokalen Netzwerk
- automatische Anlage der gefundenen Geräte in IP-Symcon
- Unterstützung von Licht, Dimmer, Rollo und Markise
- Schalten von Licht und Erfassen des Schaltzustands
- Dimmen und Verwaltung des bekannten Dimmwerts
- Steuerung von Rollo und Markise
- virtuelle Positionsberechnung für Rollo und Markise anhand der konfigurierten bzw. ermittelten Laufzeiten
- Lamellensteuerung bei Rollos
- ereignisbasierte Statusaktualisierung über `/zrap/chnotify`
- kein permanentes 5-Sekunden-Polling für den Kanalstatus
- automatische Wiederherstellung der Statusüberwachung nach Kommunikationsunterbrechungen
- Anzeige von Geräteinformationen und RSSI
- Unterstützung der zeptrionAIR-Speicher S1 bis S4
- Speichern und Löschen von S1 bis S4 direkt aus der Geräteinstanz
- Referenzwerte für gespeicherte Szenen:
  - Dimmer: Dimmwert in %
  - Rollo: Position und Lamellenposition
  - Markise: Position
- konfigurierbare Namen für Kanäle und Szenen

## Smart-Taster

Smart-Taster können direkt aus dem Modul konfiguriert werden.

Ein Smart-Taster kann sowohl zeptrionAIR-Ziele als auch IP-Symcon-Ziele enthalten.

### zeptrionAIR-Ziele

zeptrionAIR-Ziele werden direkt auf dem Smart-Taster gespeichert. Beim Tastendruck führt der Smart-Taster die hinterlegten HTTP-Aufrufe direkt im zeptrionAIR-Netzwerk aus.

Dadurch funktionieren die zeptrionAIR-Ziele auch dann, wenn IP-Symcon nicht erreichbar ist.

Für die auf dem Smart-Taster gespeicherten zeptrionAIR-Aufrufe werden die Zielgeräte mit ihrer IPv4-Adresse hinterlegt.

### IP-Symcon-Callback

Zusätzlich wird ein Callback zu IP-Symcon auf dem Smart-Taster hinterlegt.

Dieser Callback dient dazu,

- IP-Symcon über den Tastendruck zu informieren,
- gespeicherte Referenzwerte nach einem Szenenaufruf zu synchronisieren und
- reine IP-Symcon-Ziele auszuführen.

Direkte zeptrionAIR-Ziele werden durch den Callback nicht nochmals ausgeführt.

Mehrere HTTP-Aufrufe können entsprechend der zeptrionAIR-Smartbutton-API auf einem Smart-Taster gespeichert werden.

## Szenen S1–S4

Für jeden verwendeten Kanal können die zeptrionAIR-Speicher **S1 bis S4** verwaltet werden.

Beim Speichern wird neben der Szene automatisch ein Referenzwert in IP-Symcon hinterlegt. Dadurch kann IP-Symcon nach einem späteren Szenenaufruf den bekannten Zustand wieder korrekt synchronisieren.

Je nach Kanaltyp werden folgende Werte gespeichert:

- **Dimmer:** Dimmwert
- **Rollo:** Position und Lamellenposition
- **Markise:** Position

Die Referenzwerte werden auch bei der Konfiguration von Smart-Tastern verwendet.

## Statusaktualisierung

Die Kanalzustände werden über den zeptrionAIR-Long-Poll-Endpunkt `/zrap/chnotify` überwacht.

Nach einer Statusmeldung wird die Überwachung unmittelbar erneut gestartet. Ein zyklisches 5-Sekunden-`chscan`-Polling ist deshalb im normalen Betrieb nicht erforderlich.

Bei Kommunikationsproblemen wird die Verbindung automatisch überwacht und die Benachrichtigung wieder gestartet.

### Hinweis zu Dimmern

Bei den getesteten zeptrionAIR-Dimmern liefert `chnotify` beim Dimmen nur den Ein-/Aus-Zustand und keinen zuverlässigen tatsächlichen Dimmwert. Deshalb wird ein vorhandener bekannter Dimmwert nicht durch diese Meldung überschrieben.

Bei über IP-Symcon ausgeführten Dimmvorgängen ist der Zielwert bekannt und kann entsprechend geführt werden.

## Rollo und Markise

Rollo und Markise verwenden eine laufzeitbasierte Positionsberechnung.

- **Rollo:** Position und Lamellenposition
- **Markise:** Position ohne Lamellensteuerung

Für Zwischenpositionen werden zeitgesteuerte Fahrbefehle verwendet.

## Modulaufbau

Die Geräteinstanz kommuniziert direkt über einen IP-Symcon Client Socket mit dem jeweiligen zeptrionAIR-Gerät.

```text
Discovery
   ↓
ZeptrionAir Device
   ↓
Client Socket
   ↓
zeptrionAIR zApp
```

Es wird kein zusätzlicher Splitter benötigt.

## Discovery

Die Discovery-Instanz sucht nach zeptrionAIR-Geräten im Netzwerk und liest die benötigten Geräte- und Kanalinformationen aus.

Unterstützt werden die zeptrionAIR-mDNS-Dienste `_zapp._tcp` sowie für ältere Geräte `_http._tcp`.

Beim Erstellen eines gefundenen Geräts wird die benötigte Kette aus **ZeptrionAir Device → Client Socket** automatisch angelegt.

## Versionen

### Version 1.2
- Umbau auf CHNOTIFY Kommunikation um das aggressive Polling zu verhindern.

### Version 1.1
- Der Modulecode wurde überarbeitet und auf Store-Kompatibilität geprüft.

### Version 1.0
- Initiale Version

### Entwicklung

- Zentrale Kommunikation der Geräteinstanzen über den zeptrionAIR Splitter
- Smart-Taster-Konfiguration über zentralen WebHook
- Smart-Taster-Ziele für IP-Symcon Variablen, Skripte und eingebundene zeptrionAIR Geräte
- Koordinierte Gerätekommunikation bei Polling und Smart-Taster-Programmierung

## Branches

- **main** – stabil
- **beta** – Testversion
- **development** – laufende Entwicklung

## Voraussetzungen

- IP-Symcon ab Version 8.2
- Feller zeptrionAIR
- Netzwerkverbindung zwischen IP-Symcon und den verwendeten zeptrionAIR-Geräten
