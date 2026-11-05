# Versionshinweise für Heista Adressprüfung

## v1.9.0

### Neu

- Geschäftskundenprüfung: Die zweite Plugin-Aktion ***Adresse via Heista SaaS prüfen, mit Geschäftskundenprüfung*** prüft zusätzlich, ob an der Lieferadresse die Art von Empfänger eingetragen ist, die Sie im Heista-Dashboard gewählt haben, etwa Kitas und Schulen. Bestätigt wird die Art des Empfängers, nicht die Firma. Die Prüfung wird je geprüfter Adresse zusätzlich berechnet und muss im Heista-Dashboard eingeschaltet werden. Welche Aufträge geprüft werden, legen Sie dort fest: nur Aufträge aus dieser Aktion, Bestellungen mit Firmenname oder alle Bestellungen.
- E-Mail-Prüfung: Wurde mit einer Adresse eines privaten E-Mail-Anbieters bestellt, etwa web.de oder gmx.de, landet der Auftrag zur Prüfung beim Menschen. Welche Anbieter zählen, stellen Sie im Heista-Dashboard ein. Ohne Aufpreis.
- Zwei neue Felder in der ***Auftragsstatus-Zuordnung***: ***Status bei Geschäftskundenprüfung: manuell prüfen*** und ***Status bei Geschäftskundenprüfung: Widerspruch***.
- ***Wartezeit, wenn Heista nicht erreichbar ist*** im Bereich ***Auftragsbehandlung***, von 1 bis 24 Stunden.
- Beim ersten Fehlversuch bekommt der Auftrag einen internen Kommentar: noch nicht geprüft, und bis wann das Plugin es weiter versucht.

### Geändert

- Die Wartezeit bei einem Ausfall beträgt jetzt standardmäßig 8 Stunden statt fest 24 Stunden. Danach geht der Auftrag in den Fehlerstatus. Wer 24 Stunden behalten will, stellt das in der neuen Einstellung ein.
- Antwortet statt Heista eine Fehlerseite, etwa „404 page not found“ während einer Störung, versucht das Plugin es weiter, statt den Auftrag sofort in den Fehlerstatus zu setzen.
- Ist Heista in einem Lauf des Hintergrundprozesses nicht erreichbar, sendet das Plugin in diesem Lauf nichts mehr. Ein Ausfall hält den Lauf nicht mehr minutenlang auf.

### Behoben

- Ging die Antwort auf die Bestätigung eines Auftrags verloren, legte der nächste Versuch einen zweiten Auftrag bei Heista an, der ebenfalls berechnet wurde. Jetzt bestätigt das Plugin denselben Auftrag noch einmal.
- Endete eine Prüfung bei Heista mit einem Fehler und kam das Ergebnis nur über die regelmäßige Abfrage an, blieb der Auftrag ohne Kommentar und Status. Jetzt bekommt er beides wie bei einer direkten Rückmeldung.
