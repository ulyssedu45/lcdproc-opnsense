lcdproc_setup="/usr/local/opnsense/scripts/lcdproc/setup.sh"
lcdproc_enable="{% if helpers.exists('OPNsense.lcdproc.general.enabled') and OPNsense.lcdproc.general.enabled == '1' %}YES{% else %}NO{% endif %}"
