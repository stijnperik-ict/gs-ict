# GS ICT

GS ICT is een uitbreidbare WordPress-plugin voor beveiligings- en beheerfuncties.

## Functies

- TOTP-2FA per administratoraccount.
- Een administrator kan 2FA verplichten voor andere administrators.
- Setup via QR-code of handmatige TOTP-sleutel.
- Herstelcodes en beveiliging tegen hergebruik/brute-force pogingen.
- Automatische WordPress-updates centraal uitschakelen, terwijl handmatige updates beschikbaar blijven.
- Een geplande onderhoudsdatum voor handmatige updates.
- Updates voor GS ICT via GitHub Releases en de normale WordPress pluginlijst.

## Releases

Een push naar `main` maakt via GitHub Actions automatisch een release voor de versie in `gs-ict.php`, mits die release nog niet bestaat. De workflow bouwt `gs-ict.zip`, zodat WordPress de plugin veilig in dezelfde map kan bijwerken.

## Updatebeleid

De instelling om automatische updates uit te schakelen verhindert automatische installatie, maar niet het controleren op nieuwe versies. Een beheerder kan een beschikbare GS ICT-update dus handmatig starten via **Plugins → Nu bijwerken**.

## Licentie

GS ICT: GPLv2 of later.
