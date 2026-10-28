# Heista Adressprüfung

Das Plugin schickt die Lieferadresse eines Auftrags an die Heista-Adressprüfung und schreibt das Ergebnis zurück: die korrigierte Adresse, einen internen Kommentar am Auftrag und den Auftragsstatus, den Sie für das Ergebnis festlegen.

## Grundeinrichtung

1. Installieren Sie das Plugin im Pluginset und stellen Sie das Pluginset bereit.
2. Öffnen Sie unter ***Plugins → Pluginset-Übersicht*** das Plugin und tragen Sie im Bereich ***SaaS-Verbindung*** den API-Schlüssel aus dem Heista-Dashboard ein.
3. Tragen Sie im Bereich ***Auftragsstatus-Zuordnung*** für jedes Ergebnis den Status ein, in den der Auftrag wechseln soll. Ein leeres Feld lässt den Status unverändert. Unter ***Auftragskommentar-Autor*** tragen Sie die Benutzer-ID ein, unter der das Ergebnis als interner Kommentar am Auftrag erscheint.
4. Legen Sie unter ***Automatisierung → Flow Studio*** einen Flow an, der beim Anlegen eines Auftrags startet und die Plugin-Aktion ***SubmitAddressCheck → Adresse via Heista SaaS prüfen*** ausführt. Mit den Filtern des Flows legen Sie fest, welche Aufträge geprüft werden.

Arbeiten Sie noch mit klassischen Ereignisaktionen (***Einrichtung → Aufträge → Ereignisaktionen***), finden Sie dort dieselben zwei Prozeduren unter denselben Namen.

## Geschäftskundenprüfung (ab Version 1.9.0)

Für Shops, die an Unternehmen, Schulen, Kitas, Behörden und andere Einrichtungen liefern. Zusätzlich zur Adressprüfung gibt es zwei Prüfungen, die Sie einzeln einschalten:

- **Empfänger an der Adresse prüfen:** Gesucht wird mit dem Firmennamen und der Lieferadresse, wie sie nach der Adressprüfung im Auftrag steht. Wurde die Adresse korrigiert, ist das die korrigierte. Bestätigt wird, dass an dieser Adresse ein Empfänger der Art eingetragen ist, die Sie unter „An wen Sie liefern“ gewählt haben, als ganze Bereiche oder als einzelne Arten. Geprüft wird die Art des Empfängers, nicht der Firmenname selbst. Ohne Firmennamen in der Lieferadresse ist die Prüfung nicht möglich, der Auftrag geht dann in die manuelle Prüfung. Verschiebt unsere Adresskorrektur den Namen einer Einrichtung aus einem Namensfeld ins Firmenfeld, wird mit diesem Namen geprüft. Die Adresskorrektur läuft in Modell L für jede Adresse, in Modell M nur für Adressen, die DHL ablehnt. Namen von Personen werden nie verwendet. Preis: 0,025 € je geprüfter Adresse, zusätzlich zum gebuchten Modell.
- **Private E-Mail-Adressen markieren:** Wurde mit der Adresse eines privaten Anbieters bestellt, etwa web.de, GMX oder Gmail, geht der Auftrag in die manuelle Prüfung, auch wenn der Empfänger bestätigt ist. So sehen Bestellungen aus, die bei der Zustellung auf eine andere Adresse umgeleitet werden sollen. Ohne Aufpreis.

### Die zwei Plugin-Aktionen

Das Plugin bringt zwei Aktionen mit. Die Flows, die sie ausführen, legen Sie selbst an.

| Aktion im Flow Studio | Was sie tut |
|---|---|
| ***SubmitAddressCheck → Adresse via Heista SaaS prüfen*** | Die Adressprüfung wie bisher. Steht im Heista-Dashboard „Bestellungen mit Firmenname“ oder „Alle Bestellungen“, läuft die Geschäftskundenprüfung hier mit. |
| ***SubmitAddressCheckB2b → Adresse via Heista SaaS prüfen, mit Geschäftskundenprüfung*** | Adressprüfung plus Geschäftskundenprüfung für jeden Auftrag, der diese Aktion durchläuft. Dafür steht im Heista-Dashboard „Nur Aufträge aus der Plugin-Aktion mit Geschäftskundenprüfung“. |

### Rezept A: Sie liefern nur an Geschäftskunden

1. Wählen Sie im Heista-Dashboard „Bestellungen mit Firmenname“ oder „Alle Bestellungen“.
2. Ein Flow beim Anlegen eines Auftrags mit der Aktion ***SubmitAddressCheck → Adresse via Heista SaaS prüfen*** genügt.

### Rezept B: Sie liefern an Privat- und Geschäftskunden

1. Wählen Sie im Heista-Dashboard „Nur Aufträge aus der Plugin-Aktion mit Geschäftskundenprüfung“.
2. Flow 1 beim Anlegen eines Auftrags, mit einem Filter auf Ihre Geschäftskundenaufträge, etwa eine Zahlungsart wie Rechnung, eine Kundenklasse oder ein Tag. Aktion: ***SubmitAddressCheckB2b → Adresse via Heista SaaS prüfen, mit Geschäftskundenprüfung***.
3. Flow 2 mit demselben Auslöser und dem umgekehrten Filter: alle anderen Zahlungsarten, alle anderen Kundenklassen oder ohne den Tag. Aktion: ***SubmitAddressCheck → Adresse via Heista SaaS prüfen***.

Die Filter der beiden Flows müssen sich gegenseitig ausschließen, jeder Auftrag soll genau einen der beiden durchlaufen. Passt ein Auftrag doch auf beide, übermittelt das Plugin ihn trotzdem nur einmal:

- Kam die Aktion mit Geschäftskundenprüfung zuerst, wird die zweite Übermittlung verworfen. Der Auftrag bekommt die vollständige Prüfung.
- Kam die normale Aktion zuerst und ist schon bei Heista angekommen, startet keine Geschäftskundenprüfung. Der Auftrag bekommt einen internen Kommentar: „Die Filter Ihrer beiden Automatisierungen überschneiden sich. Bitte so einstellen, dass jeder Auftrag nur eine davon durchläuft.“ Korrigieren Sie dann die Filter. Für diesen Auftrag können Sie die Aktion frühestens zehn Minuten später erneut ausführen.

Dieselbe Aktion zweimal auszuführen verhält sich wie bisher.

### Status und Kommentar

Im Bereich ***Auftragsstatus-Zuordnung*** gibt es zwei neue Felder:

- ***Status bei Geschäftskundenprüfung: manuell prüfen***: Der Empfänger wurde nicht bestätigt oder nicht gefunden, die Prüfung war nicht möglich (zum Beispiel kein Firmenname), oder es wurde mit einer privaten E-Mail-Adresse bestellt. Das Ergebnis der E-Mail-Prüfung nutzt immer diesen Status.
- ***Status bei Geschäftskundenprüfung: Widerspruch***: An der Adresse ist etwas anderes eingetragen als die gewählten Empfängerarten. Bleibt das Feld leer, gilt der Status für die manuelle Prüfung.

Beide Felder greifen nur bei Aufträgen, deren Adresse sonst versandbereit wäre. Ist die Adresse nicht zustellbar, gilt der Status dafür. Eine Telefonnummer, die DHL ablehnen würde, geht vor und setzt den Fehlerstatus.

Das Ergebnis steht im internen Auftragskommentar, in den Zeilen „Geschäftskundenprüfung“ und „E-Mail-Prüfung“.

### Einstellungen im Heista-Dashboard

Was geprüft wird, stellen Sie nicht im Plugin ein, sondern im Heista-Dashboard unter ***Services → Adressprüfung → Tab „Einrichtung“ → Schritt „Modell, DHL-Zugang und Geschäftskundenprüfung“ → Abschnitt „Geschäftskundenprüfung“***:

- welche Aufträge geprüft werden: „Nur Aufträge aus der Plugin-Aktion mit Geschäftskundenprüfung“, „Bestellungen mit Firmenname“ oder „Alle Bestellungen“,
- „Empfänger an der Adresse prüfen“, mit der Auswahl unter „An wen Sie liefern“,
- „Private E-Mail-Adressen markieren“: alle privaten Anbieter der Liste oder nur ausgewählte, etwa nur web.de, dazu eigene Domains.

Die Empfängerprüfung kostet 0,025 € je geprüfter Adresse zusätzlich zum gebuchten Modell. Die E-Mail-Prüfung ist ohne Aufpreis.

Ältere Versionen des Plugins arbeiten weiter. Steht im Dashboard „Bestellungen mit Firmenname“ oder „Alle Bestellungen“, laufen die Prüfungen dort trotzdem und die Empfängerprüfung wird berechnet. Am Auftrag erscheint ihr Ergebnis aber erst ab Version 1.9.0. Schalten Sie die Geschäftskundenprüfung deshalb erst nach dem Update ein.
