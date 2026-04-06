# LCDproc Plugin for OPNsense

An OPNsense plugin that integrates [LCDproc](http://lcdproc.omnipotent.net/) for LCD/VFD display management. This is a full port of the pfSense LCDproc package, bringing complete LCD hardware support to OPNsense with a native MVC-based web interface.

## Features

- **34+ LCD/VFD drivers supported** — CrystalFontz, HD44780, Matrix Orbital, Noritake VFD, picoLCD, SureElectronics, Shuttle VFD, and many more
- **25+ information screens** — selectively display the data you need
- **Native OPNsense integration** — MVC architecture, Configd service management, Phalcon-based UI
- **Full pfSense feature parity** — faithfully ported from the pfSense LCDproc package

### Available Screens

| Category | Screens |
|---|---|
| **System** | Version, Hostname, Uptime, System info, CPU load averages, CPU frequency, CPU temperature (°C/°F) |
| **Network** | Interface summary, Interface link status (IPv4/IPv6/MAC), Traffic statistics, Top interfaces by bps / total bytes / bytes today |
| **Firewall** | State table usage, CARP failover status, IPsec tunnel status |
| **Gateways** | Gateway summary, Per-gateway status (loss, delay) |
| **Storage** | Disk usage, Memory buffers (mbuf) |
| **Monitoring** | NTP synchronization & GPS info, Traffic by address (with sorting/filtering), APC UPS status (apcupsd), NUT UPS status |
| **Misc** | Installed packages summary |

## Requirements

- OPNsense 24.1 or later
- The `lcdproc` FreeBSD package (installed automatically as a dependency)
- A supported LCD/VFD display connected via serial, USB, or parallel port

## Installation

### From source (development)

1. Clone this repository on your OPNsense machine (or copy the files):
   ```bash
   git clone https://github.com/your-repo/lcdproc-opnsense.git
   cd lcdproc-opnsense
   ```

2. Install the plugin:
   ```bash
   make install
   ```

3. Install the LCDproc dependency if not already present:
   ```bash
   pkg install lcdproc
   ```

### From OPNsense package repository

Once published to a repository:
```bash
pkg install os-lcdproc
```

## Configuration

After installation, navigate to **Services → LCDproc** in the OPNsense web interface.

### General Settings

| Setting | Description |
|---|---|
| **Enable LCDproc** | Enable or disable the service |
| **COM Port** | Serial/USB/parallel port where the display is connected |
| **LCD Driver** | Driver matching your display hardware |
| **Display Size** | Screen dimensions (e.g. 20x4, 16x2) |
| **Refresh Frequency** | How often screen content is updated (1–15 seconds) |
| **Port Speed** | Serial baud rate (default auto-detected) |
| **Backlight** | Backlight control (on/off/default) |

**Advanced options:**

- **Connection Type** — HD44780 wiring variant (4-bit, 8-bit, I2C, USB, etc.)
- **Brightness / Off Brightness / Contrast** — Display tuning (0–100%)
- **Output LEDs** — Status LEDs for CrystalFontz Packet displays (CARP & gateway indicators)
- **Control Menu** — Enable the built-in LCDd server menu accessible via display buttons
- **Matrix Orbital settings** — Display type (LCD/LKD/VFD/VKD), adjustable backlight, backlight color

### Screen Selection

On the **Screens** tab, toggle individual information screens on or off. Some screens have additional options:

- **CPU Temperature** — Choose Celsius or Fahrenheit
- **Traffic** — Select the monitored interface
- **Traffic by Address** — Configure interface, sort order (incoming/outgoing), address filter (local/remote/all), and host display format (description/IP)

## Architecture

This plugin follows the standard OPNsense MVC plugin structure:

```
src/
├── etc/
│   ├── rc.conf.d/lcdproc              # RC configuration
│   └── rc.syshook.d/start/50-lcdproc  # Auto-start hook
├── opnsense/
│   ├── mvc/app/
│   │   ├── controllers/OPNsense/LCDproc/
│   │   │   ├── GeneralController.php       # General settings UI controller
│   │   │   ├── ScreensController.php       # Screens settings UI controller
│   │   │   ├── Api/
│   │   │   │   ├── GeneralController.php   # General settings API
│   │   │   │   └── ServiceController.php   # Service start/stop/status API
│   │   │   └── forms/
│   │   │       ├── general.xml             # General settings form definition
│   │   │       └── screens.xml             # Screens form definition
│   │   ├── models/OPNsense/LCDproc/
│   │   │   ├── LCDproc.php                 # Model class
│   │   │   ├── LCDproc.xml                 # Model definition (all fields & validation)
│   │   │   ├── ACL/ACL.xml                 # Access control rules
│   │   │   └── Menu/Menu.xml               # Navigation menu entry
│   │   └── views/OPNsense/LCDproc/
│   │       ├── general.volt                # General settings view template
│   │       └── screens.volt                # Screens settings view template
│   ├── scripts/lcdproc/
│   │   ├── lcdproc_client.php              # LCD client daemon (2300+ lines)
│   │   └── setup.sh                        # Service lifecycle management
│   └── service/
│       ├── conf/actions.d/actions_lcdproc.conf  # Configd action definitions
│       └── templates/OPNsense/LCDproc/
│           ├── +TARGETS                    # Template output mapping
│           └── LCDd.conf                   # Jinja2 template for LCDd configuration
```

### How It Works

1. **Web UI** — The user configures settings via the OPNsense web interface (General + Screens tabs)
2. **Model** — Settings are validated and stored in `/conf/config.xml` under `OPNsense.lcdproc`
3. **Template Engine** — On service start, the Jinja2 template generates `/usr/local/etc/LCDd.conf` from the saved configuration
4. **LCDd Daemon** — The LCDproc server (`LCDd`) starts with the generated configuration and manages hardware communication
5. **Client Daemon** — `lcdproc_client.php` connects to LCDd on `127.0.0.1:13666`, collects system data, and pushes screen updates at the configured refresh interval

## Supported Drivers

BayRAD, CrystalFontz (CFontz), CrystalFontz Packet (CFontzPacket), Curses (Terminal), CwLinux, VLSystem EA65, EyeboxOne, GLCD, GLK (MatrixOrbital GLK), HD44780 and compatibles, ICP A106, IOWarrior USB, LB216, LCDM001, LCTerm, MD8800, MS6931, MTC S16209x, Matrix Orbital (MtxOrb), Noritake VFD, picoLCD USB, Pyramid, Raw Serial, SDEC LCD (Watchguard), SED1330, SED1520, Serial POS, Serial VFD, Shuttle USB VFD, SLI, STV5730, Sure Electronics, T6963, Text Mode, Tyan, VLSys M428

### HD44780 Connection Types

4-bit parallel, 8-bit parallel, Winamp wiring, Serial LPT, PIC-an-LCD, LCD Serializer, LOS Panel, VDR LCD, VDR Wakeup, Pertelian X2040, BWCT USB, LCD2USB, USBtiny, LIS2, MPlay, FTDI 2232D, USB LCD (Adams IT), I2C (PCF8574/PCA9554), EZIO (Portwell)

## API Endpoints

| Endpoint | Method | Description |
|---|---|---|
| `/api/lcdproc/general/get` | GET | Retrieve general settings |
| `/api/lcdproc/general/set` | POST | Save general settings |
| `/api/lcdproc/service/start` | POST | Start the LCDproc service |
| `/api/lcdproc/service/stop` | POST | Stop the LCDproc service |
| `/api/lcdproc/service/restart` | POST | Restart the LCDproc service |
| `/api/lcdproc/service/status` | GET | Get service running status |
| `/api/lcdproc/service/reconfigure` | POST | Reconfigure and restart |

## Troubleshooting

- **Service won't start** — Ensure a COM port other than "None" is selected and the correct driver is chosen for your hardware.
- **Blank display** — Verify the display size matches your hardware. Try adjusting contrast and brightness. Check that the serial port speed matches your device.
- **Permission errors** — The LCD device node (e.g. `/dev/cuaU0`) must be accessible. Check `ls -la /dev/cuaU*` for permissions.
- **Check logs** — Increase the log level in General settings and review `syslog` output:
  ```bash
  clog /var/log/system.log | grep lcdproc
  ```
- **Manual LCDd test** — Run the daemon in foreground for debugging:
  ```bash
  /usr/local/sbin/LCDd -c /usr/local/etc/LCDd.conf -f
  ```

## License

BSD 2-Clause License. See source files for full copyright information.

Based on the pfSense LCDproc package — original work by Scott Ullrich, Bill Marquette, and Seth Mos.

## Author

Ulysse Ballesteros
