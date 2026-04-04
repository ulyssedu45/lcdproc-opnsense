# LCDproc Plugin for OPNsense

Port complet du plugin LCDproc de pfSense vers OPNsense, avec 100% des options disponibles dans pfSense : support de 34+ drivers LCD, affichage CARP, monitoring des gateways, statistiques de trafic, et écrans d'informations système complets.

## Table des matières

- [Fonctionnalités](#fonctionnalités)
- [Prérequis](#prérequis)
- [Structure du projet](#structure-du-projet)
- [Compilation et Installation](#compilation-et-installation)
  - [Méthode 1 : Installation manuelle (développement)](#méthode-1--installation-manuelle-développement)
  - [Méthode 2 : Compilation comme plugin OPNsense officiel](#méthode-2--compilation-comme-plugin-opnsense-officiel)
  - [Méthode 3 : Création d'un package FreeBSD](#méthode-3--création-dun-package-freebsd)
- [Configuration](#configuration)
- [Drivers LCD supportés](#drivers-lcd-supportés)
- [Écrans disponibles](#écrans-disponibles)
- [Dépannage](#dépannage)

## Fonctionnalités

### Toutes les options pfSense portées

- **34+ drivers LCD** : CrystalFontz, Matrix Orbital, HD44780, SDEC LCD, Noritake VFD, picoLCD, etc.
- **Affichage CARP** : Statut MASTER/BACKUP/INIT avec LEDs de statut sur les écrans CFontzPacket
- **Monitoring des gateways** : Statut, latence (delay), perte de paquets par gateway
- **Trafic réseau** : Statistiques par interface, top interfaces par bps/bytes, trafic par adresse
- **Statut IPsec** : Tunnels établis et en cours de connexion
- **Monitoring UPS** : Support APC UPS (apcupsd) et NUT UPS
- **Statut NTP** : Synchronisation et satellites GPS
- **Informations système** : Version, uptime, hostname, CPU, mémoire, disque, load, température
- **Matrix Orbital RGB** : Support complet du rétroéclairage couleur RGB (12 couleurs)
- **HD44780 connexions** : 19 types de connexions (4-bit, 8-bit, USB, I2C, FTDI, etc.)
- **Interface web OPNsense** : Administration complète via l'interface MVC OPNsense
- **API REST** : Configuration et contrôle du service via API

## Prérequis

- **OPNsense** 23.x ou supérieur
- **Package lcdproc** installé sur le système (`pkg install lcdproc`)
- Accès SSH root à votre machine OPNsense (pour l'installation manuelle)

### Installation du package lcdproc de base

```bash
# Sur votre machine OPNsense
pkg install lcdproc
```

## Structure du projet

```
lcdproc-opnsense/
├── Makefile                          # Configuration de build du plugin
├── pkg-descr                         # Description du package
├── README.md                         # Ce fichier
└── src/
    ├── etc/
    │   └── rc.conf.d/
    │       └── lcdproc               # Configuration rc.conf
    └── opnsense/
        ├── mvc/app/
        │   ├── controllers/OPNsense/LCDproc/
        │   │   ├── Api/
        │   │   │   ├── GeneralController.php    # API REST (get/set config)
        │   │   │   └── ServiceController.php    # API service (start/stop/reconfigure)
        │   │   ├── GeneralController.php        # Page paramètres généraux
        │   │   ├── ScreensController.php        # Page configuration écrans
        │   │   └── forms/
        │   │       ├── general.xml              # Formulaire paramètres (driver, port, etc.)
        │   │       └── screens.xml              # Formulaire écrans (30+ écrans)
        │   ├── models/OPNsense/LCDproc/
        │   │   ├── ACL/
        │   │   │   └── ACL.xml                  # Permissions (view/edit)
        │   │   ├── Menu/
        │   │   │   └── Menu.xml                 # Menu OPNsense (Services > LCDproc)
        │   │   ├── LCDproc.php                  # Classe modèle
        │   │   └── LCDproc.xml                  # Schéma de données (toutes les options)
        │   └── views/OPNsense/LCDproc/
        │       ├── general.volt                 # Vue paramètres avec toggles dynamiques
        │       └── screens.volt                 # Vue écrans avec options conditionnelles
        ├── scripts/lcdproc/
        │   ├── setup.sh                         # Script service (start/stop/restart)
        │   └── lcdproc_client.php               # Daemon client LCD (30+ écrans)
        └── service/
            ├── conf/actions.d/
            │   └── actions_lcdproc.conf         # Actions configd
            └── templates/OPNsense/LCDproc/
                ├── +TARGETS                     # Cibles de génération
                └── LCDd.conf                    # Template Jinja2 pour LCDd.conf
```

## Compilation et Installation

### Méthode 1 : Installation manuelle (développement)

C'est la méthode la plus simple pour tester et développer. Elle copie les fichiers directement sur votre machine OPNsense.

#### Étape 1 : Installer le package lcdproc de base

```bash
ssh root@votre-opnsense
pkg install lcdproc
```

#### Étape 2 : Copier les fichiers du plugin

Depuis votre machine de développement (ou directement sur OPNsense) :

```bash
# Cloner le dépôt
git clone https://github.com/ulyssedu45/lcdproc-opnsense.git
cd lcdproc-opnsense

# Copier vers OPNsense (remplacez l'IP)
OPNSENSE_IP="192.168.1.1"

# Copier les modèles
scp -r src/opnsense/mvc/app/models/OPNsense/LCDproc root@${OPNSENSE_IP}:/usr/local/opnsense/mvc/app/models/OPNsense/

# Copier les contrôleurs
scp -r src/opnsense/mvc/app/controllers/OPNsense/LCDproc root@${OPNSENSE_IP}:/usr/local/opnsense/mvc/app/controllers/OPNsense/

# Copier les vues
scp -r src/opnsense/mvc/app/views/OPNsense/LCDproc root@${OPNSENSE_IP}:/usr/local/opnsense/mvc/app/views/OPNsense/

# Copier les scripts
scp -r src/opnsense/scripts/lcdproc root@${OPNSENSE_IP}:/usr/local/opnsense/scripts/

# Copier les templates de service
scp -r src/opnsense/service/templates/OPNsense/LCDproc root@${OPNSENSE_IP}:/usr/local/opnsense/service/templates/OPNsense/

# Copier les actions configd
scp src/opnsense/service/conf/actions.d/actions_lcdproc.conf root@${OPNSENSE_IP}:/usr/local/opnsense/service/conf/actions.d/

# Copier la config rc.conf.d
scp src/etc/rc.conf.d/lcdproc root@${OPNSENSE_IP}:/etc/rc.conf.d/
```

#### Étape 3 : Configurer les permissions et redémarrer

```bash
ssh root@votre-opnsense

# Rendre les scripts exécutables
chmod +x /usr/local/opnsense/scripts/lcdproc/setup.sh
chmod +x /usr/local/opnsense/scripts/lcdproc/lcdproc_client.php

# Redémarrer le service configd pour charger les nouvelles actions
service configd restart

# Régénérer les caches du framework MVC
configctl firmware flush

# Redémarrer l'interface web pour charger le menu
service php-fpm restart
```

#### Étape 4 : Vérification

1. Ouvrez votre interface OPNsense : `https://votre-opnsense`
2. Naviguez vers **Services > LCDproc > General**
3. Configurez votre driver LCD et port série
4. Allez dans **Services > LCDproc > Screens** pour activer les écrans
5. Cliquez **Apply** pour démarrer le service

### Méthode 2 : Compilation comme plugin OPNsense officiel

Cette méthode utilise le système de build officiel d'OPNsense pour créer un vrai package plugin.

#### Prérequis

- Une machine FreeBSD ou un environnement de build OPNsense
- Le dépôt `opnsense/plugins` cloné

#### Étapes

```bash
# 1. Cloner le dépôt plugins officiel OPNsense
git clone https://github.com/opnsense/plugins.git
cd plugins

# 2. Copier ce plugin dans le dépôt
cp -r /chemin/vers/lcdproc-opnsense/ sysutils/lcdproc

# 3. Compiler le plugin
cd sysutils/lcdproc
make package

# 4. Le package .pkg sera généré dans le répertoire work/
# Copiez-le sur votre OPNsense et installez-le :
pkg install ./os-lcdproc-1.0.pkg
```

### Méthode 3 : Création d'un package FreeBSD

Pour une distribution plus formelle, vous pouvez créer un port FreeBSD.

#### Étapes

```bash
# 1. Sur votre machine de build OPNsense/FreeBSD
# Cloner ce dépôt dans la structure de ports
mkdir -p /usr/ports/sysutils/os-lcdproc
cp -r /chemin/vers/lcdproc-opnsense/* /usr/ports/sysutils/os-lcdproc/

# 2. Construire le port
cd /usr/ports/sysutils/os-lcdproc
make package

# 3. Installer le package résultant
pkg add work/pkg/os-lcdproc-1.0.pkg
```

### Script d'installation rapide (tout-en-un)

Pour une installation rapide sur OPNsense, vous pouvez utiliser ce script :

```bash
#!/bin/sh
# install.sh - Installation rapide du plugin LCDproc sur OPNsense
# Usage: sh install.sh

set -e

echo "=== Installation du plugin LCDproc pour OPNsense ==="

# Vérifier qu'on est sur OPNsense
if [ ! -f /usr/local/opnsense/version/core ]; then
    echo "Erreur: Ce script doit être exécuté sur une machine OPNsense"
    exit 1
fi

# Installer le package lcdproc s'il manque
if ! pkg info lcdproc > /dev/null 2>&1; then
    echo "Installation du package lcdproc..."
    pkg install -y lcdproc
fi

BASEDIR="$(cd "$(dirname "$0")" && pwd)"

echo "Copie des fichiers du plugin..."

# Modèles
cp -r "${BASEDIR}/src/opnsense/mvc/app/models/OPNsense/LCDproc" \
    /usr/local/opnsense/mvc/app/models/OPNsense/

# Contrôleurs
cp -r "${BASEDIR}/src/opnsense/mvc/app/controllers/OPNsense/LCDproc" \
    /usr/local/opnsense/mvc/app/controllers/OPNsense/

# Vues
cp -r "${BASEDIR}/src/opnsense/mvc/app/views/OPNsense/LCDproc" \
    /usr/local/opnsense/mvc/app/views/OPNsense/

# Scripts
cp -r "${BASEDIR}/src/opnsense/scripts/lcdproc" \
    /usr/local/opnsense/scripts/

# Templates
mkdir -p /usr/local/opnsense/service/templates/OPNsense/LCDproc
cp -r "${BASEDIR}/src/opnsense/service/templates/OPNsense/LCDproc/"* \
    /usr/local/opnsense/service/templates/OPNsense/LCDproc/

# Actions configd
cp "${BASEDIR}/src/opnsense/service/conf/actions.d/actions_lcdproc.conf" \
    /usr/local/opnsense/service/conf/actions.d/

# rc.conf.d
mkdir -p /etc/rc.conf.d
cp "${BASEDIR}/src/etc/rc.conf.d/lcdproc" /etc/rc.conf.d/

# Permissions
chmod +x /usr/local/opnsense/scripts/lcdproc/setup.sh
chmod +x /usr/local/opnsense/scripts/lcdproc/lcdproc_client.php

echo "Redémarrage des services..."
service configd restart
service php-fpm restart

echo ""
echo "=== Installation terminée ==="
echo "Accédez à votre interface OPNsense :"
echo "  Services > LCDproc > General  (paramètres du driver)"
echo "  Services > LCDproc > Screens  (choix des écrans)"
echo ""
```

## Configuration

### Paramètres généraux (Services > LCDproc > General)

| Option | Description |
|--------|-------------|
| **Enable LCDproc** | Active/désactive le service |
| **Log Level** | Niveau de log (-1 à 5) |
| **COM Port** | Port série/USB/parallèle (COM1-2, USB0-3, LPT1) |
| **Display Size** | Dimensions de l'écran (12x1 à 40x2) |
| **LCD Driver** | Driver matériel (34+ options) |
| **Connection Type** | Type de connexion HD44780 (19 options) |
| **Refresh Frequency** | Fréquence de rafraîchissement (1-15 secondes) |
| **Port Speed** | Vitesse du port série (1200-115200 bps) |
| **Brightness** | Luminosité (0-100%) |
| **Off Brightness** | Luminosité écran éteint (0-100%) |
| **Contrast** | Contraste (0-100%) |
| **Backlight** | Rétroéclairage (Default/On/Off) |
| **Output LEDs** | LEDs de statut (CFontzPacket uniquement) |
| **Control Menu** | Menu de navigation intégré |
| **Matrix Orbital Type** | Type MtxOrb (LCD/LKD/VFD/VKD) |
| **MtxOrb Adjustable Backlight** | Rétroéclairage ajustable |
| **MtxOrb Backlight Color** | Couleur RGB (12 couleurs) |

### Écrans disponibles (Services > LCDproc > Screens)

| Écran | Description |
|-------|-------------|
| **Version** | Version OPNsense et kernel |
| **Time** | Heure et date système |
| **Uptime** | Durée de fonctionnement |
| **Hostname** | Nom de la machine |
| **System** | CPU, mémoire, load average |
| **Disk Usage** | Utilisation du disque |
| **Load Averages** | Moyennes de charge CPU |
| **States Table** | Table d'états pf |
| **CARP Status** | Statut MASTER/BACKUP/INIT |
| **IPsec Status** | Tunnels IPsec |
| **Interfaces** | Résumé des interfaces |
| **Interface Link** | Détail par interface (IP, MAC, link) |
| **Gateway Summary** | Résumé des gateways |
| **Gateway Status** | Détail par gateway (statut, delay, loss) |
| **Memory Buffers** | Utilisation des mbufs |
| **Packages** | Packages installés/à mettre à jour |
| **CPU Frequency** | Fréquence CPU actuelle/max |
| **CPU Temperature** | Température CPU (°C/°F) |
| **NTP Status** | Synchronisation NTP |
| **Traffic** | Trafic sur une interface |
| **Top IF by bps** | Top interfaces par débit |
| **Top IF Total** | Top interfaces par total |
| **Top IF Today** | Top interfaces aujourd'hui |
| **Traffic by Address** | Trafic par adresse IP |
| **APC UPS** | Statut onduleur APC |
| **NUT UPS** | Statut onduleur NUT |

## Drivers LCD supportés

### Serial/USB
- **bayrad** - BayRAD
- **CFontz** - CrystalFontz
- **CFontzPacket** - CrystalFontz Packet (avec LEDs de statut)
- **CwLnx** - CwLinux
- **EyeboxOne** - Eyebox One
- **MD8800** - MD8800
- **MtxOrb** - Matrix Orbital (LCD/LKD/VFD/VKD + RGB backlight)
- **NoritakeVFD** - Noritake Vacuum Fluorescent Display
- **picolcd** - picoLCD USB
- **pyramid** - Pyramid
- **rawserial** - Raw Serial
- **serialPOS** - Serial Point of Sale
- **serialVFD** - Serial VFD
- **shuttleVFD** - Shuttle USB VFD
- **SureElec** - Sure Electronics
- **tyan** - Tyan

### Parallel Port
- **hd44780** - HD44780 et compatibles (19 types de connexion)
- **lb216** - LB216
- **lcdm001** - LCDM001
- **sed1330** - SED1330
- **sed1520** - SED1520
- **stv5730** - STV5730
- **t6963** - T6963

### Special
- **curses** - Terminal (pour tests)
- **ea65** - VLSystem EA65
- **glcd** - GLCD
- **glk** - GLK (MatrixOrbital GLK)
- **icp_a106** - ICP A106
- **IOWarrior** - IOWarrior USB
- **lcterm** - LCTerm
- **ms6931** - MS6931
- **mtc_s16209x** - MTC S16209x
- **sdeclcd** - SDEC LCD (Watchguard FireBox)
- **sli** - SLI
- **text** - Text Mode
- **vlsys_m428** - VLSys M428

### HD44780 - Types de connexion
4bit, 8bit, winamp, serialLpt, picanlcd, lcdserializer, los-panel, vdr-lcd, vdr-wakeup, pertelian, bwctusb, lcd2usb, usbtiny, lis2, mplay, ftdi, usblcd, i2c, ezio

## Dépannage

### Le menu LCDproc n'apparaît pas

```bash
# Redémarrer les services web
service configd restart
service php-fpm restart

# Vérifier que les fichiers sont en place
ls -la /usr/local/opnsense/mvc/app/models/OPNsense/LCDproc/
ls -la /usr/local/opnsense/mvc/app/controllers/OPNsense/LCDproc/
```

### LCDd ne démarre pas

```bash
# Vérifier la configuration générée
cat /usr/local/etc/LCDd.conf

# Régénérer la configuration
configctl template reload OPNsense/LCDproc

# Tester LCDd manuellement
/usr/local/sbin/LCDd -c /usr/local/etc/LCDd.conf -f

# Vérifier les logs
tail -f /var/log/messages | grep lcdproc
```

### Le client ne se connecte pas

```bash
# Vérifier que LCDd écoute
sockstat -l | grep 13666

# Tester la connexion manuellement
echo "hello" | nc 127.0.0.1 13666

# Redémarrer le service complet
configctl lcdproc restart
```

### Problème de permission

```bash
chmod +x /usr/local/opnsense/scripts/lcdproc/setup.sh
chmod +x /usr/local/opnsense/scripts/lcdproc/lcdproc_client.php
```

### Logs utiles

```bash
# Logs du système
tail -100 /var/log/messages | grep -i lcd

# Logs configd
tail -100 /var/log/configd.log | grep -i lcd

# État du service
configctl lcdproc status
```

## API REST

Le plugin expose une API REST complète :

```bash
# Récupérer la configuration
curl -k -u user:key https://opnsense/api/lcdproc/general/get

# Mettre à jour la configuration
curl -k -u user:key -X POST https://opnsense/api/lcdproc/general/set \
  -d '{"lcdproc":{"general":{"enabled":"1","driver":"hd44780"}}}'

# Redémarrer le service
curl -k -u user:key -X POST https://opnsense/api/lcdproc/service/reconfigure

# Statut du service
curl -k -u user:key https://opnsense/api/lcdproc/service/status
```

## Licence

Ce projet est distribué sous licence BSD-2-Clause.

Basé sur le travail original du package pfSense-pkg-LCDproc par l'équipe pfSense.
Adapté pour OPNsense en suivant l'architecture MVC du framework OPNsense.