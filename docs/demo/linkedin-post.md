# LinkedIn post (ISSUE-27)

Draft text for a post with the screen recording `docs/demo/booking-flow.mp4`. Not posted; that is up to the author.

---

Twee klanten klikken op precies hetzelfde moment op "Afspraak boeken", voor dezelfde kapper, om 09:30. Wie krijgt de afspraak?

Precies één van de twee. Dat klinkt vanzelfsprekend, maar het was het lastigste deel van het boekingssysteem dat ik de afgelopen weken in Laravel heb gebouwd. In de video zie je het gebeuren: links Emma, rechts Lucas.

🔒 Gelijktijdige boekingen
De naïeve aanpak is "eerst kijken of het tijdslot vrij is, dan opslaan". Maar tussen dat kijken en opslaan kan een ander request precies hetzelfde doen, en dan heb je twee boekingen op één stoel. MySQL kan overlappende tijdvakken niet met een constraint verbieden, dus ik los het op in de applicatie:
• Eén transactie, en de allereerste query is een SELECT … FOR UPDATE op de medewerker (daarna op de klant).
• Pas binnen die lock controleer ik opnieuw of het tijdslot nog vrij is, inclusief de buffertijd na de afspraak, en sla ik op.
• Een tweede request wacht op de lock, ziet daarna de nieuwe boeking en krijgt een nette 409: "Dit tijdstip is helaas net bezet". De pagina laadt direct de tijden die nog wél vrij zijn.
• Vaste lock-volgorde (medewerker → klant), dus geen deadlocks. En alleen die ene medewerker wordt even vastgehouden, de rest van de salon boekt gewoon door.
Dit test ik met echte gelijktijdige requests tegen MySQL 8 in CI. SQLite negeert row locks, dus daar zou zo'n test altijd groen zijn.

🕒 Tijdzones
"Noor werkt op dinsdag van 09:00 tot 17:30" moet ook na de overgang naar wintertijd 09:00 blijven. Daarom:
• Boekingen staan in UTC.
• Werkroosters en uitzonderingen staan in lokale tijd (Europe/Amsterdam).
• Eén klasse rekent tussen de twee om, nergens anders.
• Het formulier stuurt een tijd mét offset (2026-12-04T10:30:00+01:00). In de nacht dat de klok teruggaat bestaat 02:30 twee keer, en zo is nooit onduidelijk welke van de twee je bedoelt. In de nacht dat de klok vooruitgaat wordt het overgeslagen uur gewoon niet aangeboden.

Wat ik ervan heb geleerd: de happy flow (dienst → medewerker → datum → tijd) was zo gebouwd. De echte tijd zat in de randgevallen die een gebruiker nooit ziet, totdat het een keer misgaat.

Gebouwd met Laravel 12, MySQL, Blade + Tailwind, getest met Pest.

#Laravel #PHP #MySQL #SoftwareDevelopment #WebDevelopment
