# Changelog

## 0.5.0
- GS ICT-beheer opgesplitst in Overzicht, 2FA, Updates en Beveiligingslogboek.
- Nieuwe consistente admin-opmaak met responsive statuskaarten, panelen en badges.
- Security status toont voortaan automatisch de eerstvolgende vaste onderhoudsdag.
- Handmatig onderhoud staat vast op de eerste maandag van iedere maand.
- De handmatige onderhoudsdatum en datumkiezer zijn verwijderd.
- Updates-pagina bevat alleen het automatische-updatebeleid plus de volgende onderhoudsdag.
- 2FA-beleid, herstelcodes en administratoraccounts zijn samengebracht op de 2FA-pagina.

## 0.4.0
- Security-dashboard toegevoegd met WordPress-, PHP-, update- en 2FA-status.
- Beveiligingslogboek toegevoegd met 90 dagen bewaartermijn voor belangrijke beheer- en beveiligingsgebeurtenissen.
- Globale optie toegevoegd om 2FA voor alle administrators te verplichten, inclusief nieuwe administratoraccounts.
- Nieuwe herstelcodes kunnen door de ingelogde administrator worden gegenereerd na verificatie met de huidige TOTP-code.
- Oude herstelcodes worden bij regeneratie direct ongeldig.
- 2FA-beleid voorkomt dat globale verplichting per account ongemerkt wordt omzeild.

## 0.3.0
- GitHub Releases-updater voor update-meldingen in de normale WordPress pluginlijst.
- Handmatig bijwerken via WordPress zonder telkens een ZIP te uploaden.
- Release-build via GitHub Actions met vaste pluginmap `gs-ict/`.
- Bestaande 2FA- en update-instellingen blijven behouden.

## 0.2.0
- Administrators kunnen 2FA verplichten voor andere administratoraccounts.
- Verplichte gebruikers krijgen na login een eigen 2FA-installatiescherm.
- QR-code plus handmatige TOTP-sleutel tijdens setup.

## 0.1.0
- Eerste versie met TOTP-2FA en beheer van automatische updates.
