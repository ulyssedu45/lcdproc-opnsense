#!/usr/local/bin/php
<?php

/**
 *    LCDproc Client Daemon for OPNsense
 *    Faithfully ported from pfSense LCDproc package (lcdproc_client.php)
 *
 *    Copyright (C) 2024 OPNsense LCDproc Plugin
 *    Original Copyright (C) 2007 Scott Ullrich
 *    Original Copyright (C) 2009 Bill Marquette
 *    Original Copyright (C) 2009 Seth Mos
 *
 *    All rights reserved.
 *
 *    Redistribution and use in source and binary forms, with or without
 *    modification, are permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *
 *    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 *    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 *    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 */

/* ======================== CONSTANTS & GLOBALS ======================== */

define('LCDPROC_HOST', '127.0.0.1');
define('LCDPROC_PORT', 13666);
define('PRODUCT_NAME', 'OPNsense');

$lcdpanel_width = 20;
$lcdpanel_height = 4;
$refresh_frequency = 5;
$lcdproc_config = [];
$lcdproc_screen_config = [];

/* Traffic rate tracking (for bps calculations) */
$traffic_prev = [];
$traffic_prev_time = 0;

/* ======================== LOGGING ======================== */

function lcdproc_notice($msg) {
    syslog(LOG_NOTICE, "lcdproc: {$msg}");
}

function lcdproc_warn($msg) {
    syslog(LOG_WARNING, "lcdproc: {$msg}");
}

/* ======================== CONFIGURATION ======================== */

function lcdproc_read_config() {
    global $lcdproc_config, $lcdproc_screen_config;
    global $lcdpanel_width, $lcdpanel_height, $refresh_frequency;

    $xmlConfig = simplexml_load_file('/conf/config.xml');
    if ($xmlConfig === false) {
        lcdproc_warn("Could not load config.xml");
        return;
    }

    if (!isset($xmlConfig->OPNsense->lcdproc)) {
        return;
    }

    $lcd_cfg = $xmlConfig->OPNsense->lcdproc;

    /* General settings */
    if (isset($lcd_cfg->general)) {
        $gen = $lcd_cfg->general;
        $lcdproc_config['enabled'] = (string)($gen->enabled ?? '0');
        $lcdproc_config['driver'] = (string)($gen->driver ?? 'curses');
        $lcdproc_config['comport'] = (string)($gen->comport ?? 'none');
        $lcdproc_config['size'] = (string)($gen->size ?? 's20x4');
        $lcdproc_config['refresh_frequency'] = (string)($gen->refresh_frequency ?? 'r5');
        $lcdproc_config['outputleds'] = (string)($gen->outputleds ?? '0');
        $lcdproc_config['controlmenu'] = (string)($gen->controlmenu ?? '0');

        /* Parse display size (strip 's' prefix) */
        $size_str = preg_replace('/^[^0-9]+/', '', $lcdproc_config['size']);
        $parts = explode('x', $size_str);
        if (count($parts) == 2) {
            $lcdpanel_width = max(1, (int)$parts[0]);
            $lcdpanel_height = max(1, (int)$parts[1]);
        }

        /* Parse refresh frequency (strip 'r' prefix) */
        $freq_str = preg_replace('/^[^0-9]+/', '', $lcdproc_config['refresh_frequency']);
        $refresh_frequency = max(1, (int)$freq_str);
    }

    /* Screen settings */
    if (isset($lcd_cfg->screens)) {
        foreach ($lcd_cfg->screens->children() as $key => $value) {
            $lcdproc_screen_config[(string)$key] = (string)$value;
        }
    }
}

function screen_enabled($name) {
    global $lcdproc_screen_config;
    return (($lcdproc_screen_config[$name] ?? '0') === '1');
}

function get_screen_config($name) {
    global $lcdproc_screen_config;
    return ($lcdproc_screen_config[$name] ?? '');
}

/* ======================== LCDPROC COMMUNICATION ======================== */

function get_single_sysctl($name) {
    $val = @shell_exec("sysctl -n " . escapeshellarg($name) . " 2>/dev/null");
    return ($val !== null) ? trim($val) : '';
}

function send_lcd_commands($lcd, $commands) {
    if (!is_array($commands)) {
        $commands = [$commands];
    }
    foreach ($commands as $cmd) {
        @fputs($lcd, $cmd . "\n");
        @fgets($lcd, 4096);
    }
}

function lcdproc_connect() {
    $fp = @fsockopen(LCDPROC_HOST, LCDPROC_PORT, $errno, $errstr, 10);
    if (!$fp) {
        lcdproc_warn("Cannot connect to LCDd: {$errstr} ({$errno})");
        return false;
    }
    stream_set_timeout($fp, 5);

    /* LCDproc protocol: client sends hello first */
    fputs($fp, "hello\n");

    $hello = '';
    for ($i = 0; $i < 10; $i++) {
        $line = fgets($fp, 4096);
        if ($line !== false && strlen(trim($line)) > 0) {
            $hello = trim($line);
            break;
        }
        usleep(200000);
    }

    if (strpos($hello, 'connect') === false) {
        lcdproc_warn("Unexpected LCDd response: {$hello}");
        fclose($fp);
        return false;
    }

    lcdproc_notice("LCDd handshake: {$hello}");

    /* Set client name */
    fputs($fp, "client_set -name " . PRODUCT_NAME . "\n");
    fgets($fp, 4096);

    return $fp;
}

/* ======================== SYSTEM DATA FUNCTIONS ======================== */

/**
 * Get CPU usage percentage (0-100).
 * Uses kern.cp_time differential between calls.
 */
function lcdproc_cpu_usage() {
    static $prev_idle = 0;
    static $prev_total = 0;

    $raw = get_single_sysctl('kern.cp_time');
    if (empty($raw)) {
        return 0;
    }
    $parts = preg_split('/\s+/', $raw);
    if (count($parts) < 5) {
        return 0;
    }

    $idle = (int)$parts[4];
    $total = array_sum(array_map('intval', $parts));

    if ($prev_total === 0) {
        $prev_idle = $idle;
        $prev_total = $total;
        usleep(250000);
        return lcdproc_cpu_usage();
    }

    $diff_idle = $idle - $prev_idle;
    $diff_total = $total - $prev_total;
    $prev_idle = $idle;
    $prev_total = $total;

    if ($diff_total === 0) {
        return 0;
    }

    return (int)round((1.0 - ($diff_idle / $diff_total)) * 100);
}

/**
 * Get memory usage as a percentage (integer).
 * Matches pfSense mem_usage() calculation.
 */
function mem_usage() {
    $total_pages = (int)get_single_sysctl('vm.stats.vm.v_page_count');
    if ($total_pages <= 0) {
        return 0;
    }
    $inactive = (int)get_single_sysctl('vm.stats.vm.v_inactive_count');
    $cache = (int)get_single_sysctl('vm.stats.vm.v_cache_count');
    $free = (int)get_single_sysctl('vm.stats.vm.v_free_count');

    $used = $total_pages - $inactive - $cache - $free;
    return (int)round(($used * 100) / $total_pages);
}

/**
 * Get disk usage percentage for /.
 */
function disk_usage() {
    $output = @shell_exec("df -h / 2>/dev/null | tail -1");
    if (preg_match('/(\d+)%/', $output, $m)) {
        return (int)$m[1];
    }
    return 0;
}

/**
 * Get system uptime formatted like pfSense: "X days, H:MM" or "H:MM".
 */
function get_uptime_str() {
    $bt = get_single_sysctl('kern.boottime');
    if (preg_match('/sec = (\d+)/', $bt, $m)) {
        $up = time() - (int)$m[1];
        $days = floor($up / 86400);
        $hours = floor(($up % 86400) / 3600);
        $mins = floor(($up % 3600) / 60);
        if ($days > 1) {
            return sprintf("%d days, %d:%02d", $days, $hours, $mins);
        } elseif ($days == 1) {
            return sprintf("1 day, %d:%02d", $hours, $mins);
        } else {
            return sprintf("%d:%02d", $hours, $mins);
        }
    }
    return "Unknown";
}

/**
 * Get load averages as string.
 */
function get_loadavg_str() {
    $la = get_single_sysctl('vm.loadavg');
    if (preg_match('/\{\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\}/', $la, $m)) {
        return sprintf("%.2f %.2f %.2f", (float)$m[1], (float)$m[2], (float)$m[3]);
    }
    return "0.00 0.00 0.00";
}

/**
 * Get pf state table [current_entries, max_limit].
 */
function get_pfstate() {
    $current = 0;
    $limit = 0;

    $si = @shell_exec("pfctl -si 2>/dev/null");
    if ($si !== null && preg_match('/current entries\s+(\d+)/i', $si, $m)) {
        $current = (int)$m[1];
    }

    $lim = get_single_sysctl('net.pf.states_limit');
    if (!empty($lim)) {
        $limit = (int)$lim;
    } else {
        $sm = @shell_exec("pfctl -sm 2>/dev/null");
        if ($sm !== null && preg_match('/states\s+hard limit\s+(\d+)/i', $sm, $m)) {
            $limit = (int)$m[1];
        }
    }

    return [$current, $limit];
}

/**
 * Get mbuf usage string and percentage.
 */
function get_mbuf(&$mbufs, &$mbufpercent) {
    $mbufs = "0/0";
    $mbufpercent = 0;

    $output = @shell_exec("netstat -m 2>/dev/null | head -1");
    if ($output !== null && preg_match('/([\d]+)\/([\d]+)\s+mbufs/i', $output, $m)) {
        $cur = (int)$m[1];
        $max = (int)$m[2];
        $mbufs = "{$cur}/{$max}";
        if ($max > 0) {
            $mbufpercent = (int)round(($cur / $max) * 100);
        }
    }
}

/**
 * Get CPU temperature in Celsius as float; returns empty string on failure.
 */
function get_temp() {
    $raw = get_single_sysctl('dev.cpu.0.temperature');
    if (preg_match('/([\d.]+)/', $raw, $m)) {
        return (float)$m[1];
    }
    return '';
}

/**
 * Get CPU frequency [current_mhz, max_mhz].
 */
function get_cpu_freq() {
    $cur = get_single_sysctl('dev.cpu.0.freq');
    $levels = get_single_sysctl('dev.cpu.0.freq_levels');

    $curfreq = !empty($cur) ? (int)$cur : 0;
    $maxfreq = 0;
    if (preg_match('/^(\d+)\//', $levels, $m)) {
        $maxfreq = (int)$m[1];
    }

    return [$curfreq, $maxfreq];
}

/**
 * Get configured interfaces with descriptions.
 * Returns associative array: ['wan' => 'WAN', 'lan' => 'LAN', ...]
 */
function get_configured_interface_with_descr() {
    static $cache = null;
    static $cache_time = 0;

    if ($cache !== null && (time() - $cache_time) < 30) {
        return $cache;
    }

    $interfaces = [];
    $xml = @simplexml_load_file('/conf/config.xml');
    if ($xml === false || !isset($xml->interfaces)) {
        return $interfaces;
    }

    foreach ($xml->interfaces->children() as $ifname => $ifcfg) {
        $ifname = (string)$ifname;
        $enabled = (string)($ifcfg->enable ?? '');
        if ($enabled !== '1' && $ifname !== 'wan' && $ifname !== 'lan') {
            continue;
        }
        $descr = (string)($ifcfg->descr ?? strtoupper($ifname));
        $interfaces[$ifname] = $descr;
    }

    $cache = $interfaces;
    $cache_time = time();
    return $interfaces;
}

/**
 * Get the real (physical) interface name from a config interface name.
 */
function get_real_interface($ifname) {
    $xml = @simplexml_load_file('/conf/config.xml');
    if ($xml !== false && isset($xml->interfaces->{$ifname})) {
        return (string)($xml->interfaces->{$ifname}->{'if'} ?? '');
    }
    return $ifname;
}

function convert_friendly_interface_to_real_interface_name($friendly) {
    return get_real_interface($friendly);
}

/**
 * Find IPv4 address of a physical interface.
 */
function find_interface_ip($realif) {
    $output = @shell_exec("ifconfig " . escapeshellarg($realif) . " 2>/dev/null");
    if ($output !== null && preg_match('/inet\s+([\d.]+)/', $output, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Find IPv6 address of a physical interface (first global address).
 */
function find_interface_ipv6($realif) {
    $output = @shell_exec("ifconfig " . escapeshellarg($realif) . " 2>/dev/null");
    if ($output !== null) {
        $all_lines = explode("\n", $output);
        foreach ($all_lines as $line) {
            if (preg_match('/inet6\s+([0-9a-f:]+)/i', $line, $m)) {
                if (strpos($m[1], 'fe80') !== 0) {
                    return $m[1];
                }
            }
        }
    }
    return '';
}

/**
 * Get MAC address of a physical interface.
 */
function get_interface_mac($realif) {
    $output = @shell_exec("ifconfig " . escapeshellarg($realif) . " 2>/dev/null");
    if ($output !== null && preg_match('/ether\s+([0-9a-f:]+)/i', $output, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Get interface link status and info.
 * Returns array: ['status' => 'up'|'down', 'ipaddr' => '...', 'macaddr' => '...', ...]
 */
function get_interface_info($ifdescr) {
    $realif = get_real_interface($ifdescr);
    $info = [
        'status' => 'down',
        'ipaddr' => '',
        'ipv6addr' => '',
        'macaddr' => '',
        'media' => '',
        'if' => $realif,
    ];

    if (empty($realif)) {
        return $info;
    }

    $output = @shell_exec("ifconfig " . escapeshellarg($realif) . " 2>/dev/null");
    if ($output === null) {
        return $info;
    }

    /* Link status */
    if (preg_match('/status:\s*(\S+)/', $output, $m)) {
        $info['status'] = ($m[1] === 'active') ? 'up' : 'down';
    } elseif (strpos($output, 'UP') !== false) {
        $info['status'] = 'up';
    }

    /* IPv4 */
    if (preg_match('/inet\s+([\d.]+)/', $output, $m)) {
        $info['ipaddr'] = $m[1];
    }

    /* IPv6 */
    $lines = explode("\n", $output);
    foreach ($lines as $line) {
        if (preg_match('/inet6\s+([0-9a-f:]+)/i', $line, $m)) {
            if (strpos($m[1], 'fe80') !== 0) {
                $info['ipv6addr'] = $m[1];
                break;
            }
        }
    }

    /* MAC */
    if (preg_match('/ether\s+([0-9a-f:]+)/i', $output, $m)) {
        $info['macaddr'] = $m[1];
    }

    /* Media */
    if (preg_match('/media:\s*(.+)/i', $output, $m)) {
        $info['media'] = trim($m[1]);
    }

    return $info;
}

/**
 * Check if CARP is enabled.
 * Returns true if net.inet.carp.allow is 1.
 */
function carp_enabled() {
    $allow = get_single_sysctl('net.inet.carp.allow');
    return ($allow === '1');
}

/**
 * Get CARP VIP status counts.
 * Returns [master_count, backup_count, init_count] or false if disabled.
 */
function get_carp_status() {
    if (!carp_enabled()) {
        return false;
    }

    $output = @shell_exec("ifconfig -a 2>/dev/null");
    if (empty($output)) {
        return [0, 0, 0];
    }

    $master = 0;
    $backup = 0;
    $init = 0;

    if (preg_match_all('/carp:\s+(\S+)\s+vhid/i', $output, $matches)) {
        foreach ($matches[1] as $state) {
            $state = strtoupper($state);
            if ($state === 'MASTER') {
                $master++;
            } elseif ($state === 'BACKUP') {
                $backup++;
            } else {
                $init++;
            }
        }
    }

    return [$master, $backup, $init];
}

/**
 * Get gateway status array.
 * Returns array of gateways with keys: name, status, delay, loss, substatus.
 */
function return_gateways_status($byname = false) {
    $gateways = [];

    /* Try dpinger status files first */
    $dpinger_files = glob("/var/run/dpinger_*.sock");
    if (!empty($dpinger_files)) {
        foreach ($dpinger_files as $sock) {
            $name = basename($sock, '.sock');
            $name = str_replace('dpinger_', '', $name);

            $status_file = "/var/run/dpinger_{$name}.status";
            if (!file_exists($status_file)) {
                continue;
            }

            $data = trim(@file_get_contents($status_file));
            if (empty($data)) {
                continue;
            }

            /* Format: gateway_ip delay_avg loss_count rtt_avg (microseconds) */
            $parts = preg_split('/\s+/', $data);
            $delay_us = isset($parts[1]) ? (int)$parts[1] : 0;
            $loss_pct = isset($parts[2]) ? (int)$parts[2] : 0;
            $delay_ms = round($delay_us / 1000, 1);

            $status = 'none';
            if ($loss_pct >= 100) {
                $status = 'down';
                $substatus = 'down';
            } elseif ($loss_pct > 0) {
                $status = 'warning';
                $substatus = 'loss';
            } else {
                $status = 'none';
                $substatus = 'none';
            }

            $gw = [
                'name' => $name,
                'status' => $status,
                'substatus' => $substatus,
                'delay' => sprintf("%.1f ms", $delay_ms),
                'loss' => sprintf("%d%%", $loss_pct),
            ];

            if ($byname) {
                $gateways[$name] = $gw;
            } else {
                $gateways[] = $gw;
            }
        }
    }

    /* Fallback: check default routes */
    if (empty($gateways)) {
        $routes = @shell_exec("netstat -rn -f inet 2>/dev/null | grep '^default'");
        if (!empty($routes)) {
            foreach (explode("\n", trim($routes)) as $route) {
                if (preg_match('/default\s+(\S+)\s+\S+\s+(\S+)/', $route, $m)) {
                    $gw = [
                        'name' => $m[2],
                        'status' => 'none',
                        'substatus' => 'none',
                        'delay' => '0.0 ms',
                        'loss' => '0%',
                    ];
                    if ($byname) {
                        $gateways[$m[2]] = $gw;
                    } else {
                        $gateways[] = $gw;
                    }
                }
            }
        }
    }

    return $gateways;
}

/**
 * Check if IPsec is enabled.
 */
function ipsec_enabled() {
    /* Check if strongswan/ipsec is running */
    $output = @shell_exec("ipsec status 2>/dev/null");
    return ($output !== null && !empty(trim($output)));
}

/**
 * List IPsec SAs (Security Associations).
 * Returns array of SAs with status.
 */
function ipsec_list_sa() {
    $sas = [];
    $output = @shell_exec("ipsec statusall 2>/dev/null");
    if (empty($output)) {
        return $sas;
    }

    $active = 0;
    $inactive = 0;
    foreach (explode("\n", $output) as $line) {
        if (preg_match('/ESTABLISHED/', $line)) {
            $active++;
        } elseif (preg_match('/CONNECTING|CREATED/', $line)) {
            $inactive++;
        }
    }

    return ['active' => $active, 'inactive' => $inactive];
}

/**
 * Get interface traffic stats (byte counters from netstat).
 * Returns [inbytes, outbytes, inpkts, outpkts].
 */
function get_interface_stats($realif) {
    $stats = ['inbytes' => 0, 'outbytes' => 0, 'inpkts' => 0, 'outpkts' => 0];
    $output = @shell_exec("netstat -I " . escapeshellarg($realif) . " -b -n 2>/dev/null | tail -1");
    if ($output !== null) {
        $fields = preg_split('/\s+/', trim($output));
        /* netstat -I -b format: Name Mtu Network Address Ipkts Ierrs Ibytes Opkts Oerrs Obytes Coll */
        if (count($fields) >= 10) {
            $stats['inpkts'] = (int)$fields[4];
            $stats['inbytes'] = (int)$fields[6];
            $stats['outpkts'] = (int)$fields[7];
            $stats['outbytes'] = (int)$fields[9];
        }
    }
    return $stats;
}

/**
 * Calculate traffic rates in bits per second for an interface.
 */
function get_traffic_bps($ifdescr) {
    global $traffic_prev, $traffic_prev_time;

    $realif = get_real_interface($ifdescr);
    if (empty($realif)) {
        return ['in_bps' => 0, 'out_bps' => 0];
    }

    $now = microtime(true);
    $stats = get_interface_stats($realif);

    $in_bps = 0;
    $out_bps = 0;

    if (isset($traffic_prev[$realif]) && $traffic_prev_time > 0) {
        $elapsed = $now - $traffic_prev_time;
        if ($elapsed > 0) {
            $in_diff = $stats['inbytes'] - $traffic_prev[$realif]['inbytes'];
            $out_diff = $stats['outbytes'] - $traffic_prev[$realif]['outbytes'];
            /* Handle counter rollover */
            if ($in_diff < 0) {
                $in_diff = 0;
            }
            if ($out_diff < 0) {
                $out_diff = 0;
            }
            $in_bps = ($in_diff * 8) / $elapsed;
            $out_bps = ($out_diff * 8) / $elapsed;
        }
    }

    $traffic_prev[$realif] = $stats;
    $traffic_prev_time = $now;

    return ['in_bps' => $in_bps, 'out_bps' => $out_bps];
}

/**
 * Format bits per second for display.
 */
function format_bps($bps) {
    if ($bps >= 1000000000) {
        return sprintf("%.1f Gbps", $bps / 1000000000);
    } elseif ($bps >= 1000000) {
        return sprintf("%.1f Mbps", $bps / 1000000);
    } elseif ($bps >= 1000) {
        return sprintf("%.1f kbps", $bps / 1000);
    }
    return sprintf("%.1f bps", $bps);
}

/**
 * Format bytes for compact display.
 */
function format_bytes_short($bytes) {
    if ($bytes >= 1073741824) {
        return sprintf("%.1fG", $bytes / 1073741824);
    } elseif ($bytes >= 1048576) {
        return sprintf("%.1fM", $bytes / 1048576);
    } elseif ($bytes >= 1024) {
        return sprintf("%.1fK", $bytes / 1024);
    }
    return sprintf("%dB", $bytes);
}

/**
 * Get NTP status (detailed, for 4-line NTP screen).
 * Returns array with 4 lines of text.
 */
function lcdproc_get_ntp_status() {
    $result = [
        'line1' => 'NTP not available',
        'line2' => '',
        'line3' => '',
        'line4' => '',
    ];

    $output = @shell_exec("ntpq -pn 2>/dev/null");
    if (empty($output)) {
        return $result;
    }

    $lines = explode("\n", trim($output));
    $peer_line = '';

    /* Find the current sync peer (line starting with *) */
    foreach ($lines as $line) {
        if (isset($line[0]) && $line[0] === '*') {
            $peer_line = $line;
            break;
        }
    }

    /* If no sync peer, try candidate (+) */
    if (empty($peer_line)) {
        foreach ($lines as $line) {
            if (isset($line[0]) && $line[0] === '+') {
                $peer_line = $line;
                break;
            }
        }
    }

    if (empty($peer_line)) {
        $result['line1'] = 'No NTP Peer';
        return $result;
    }

    /* Parse ntpq fields: tally remote refid st t when poll reach delay offset jitter */
    $fields = preg_split('/\s+/', trim(substr($peer_line, 1)));
    if (count($fields) >= 9) {
        $remote = $fields[0];
        $refid = $fields[1];
        $stratum = $fields[2];
        $when = $fields[4];
        $poll = $fields[5];
        $reach = $fields[6];
        $delay = $fields[7];
        $offset = $fields[8];
        $jitter = isset($fields[9]) ? $fields[9] : '0.000';

        $result['line1'] = "Server: {$remote}";
        $result['line2'] = "Ref: {$refid} St: {$stratum}";
        $result['line3'] = "P: {$poll} W: {$when} Rc: {$reach}";
        $result['line4'] = "d:{$delay}ms o:{$offset}ms j:{$jitter}ms";
    }

    /* Check for GPS satellite count */
    if (file_exists('/dev/gps0')) {
        $gps = @shell_exec("cat /dev/gps0 2>/dev/null | head -1");
        if ($gps !== null && preg_match('/\$GPGSV,\d+,\d+,(\d+)/', $gps, $m)) {
            $result['line1'] .= " Sats: {$m[1]}";
        }
    }

    return $result;
}

/**
 * Get package information.
 * Returns: ['total' => X, 'updates' => Y]
 */
function get_package_info() {
    $total = 0;
    $updates = 0;

    $output = @shell_exec("pkg info 2>/dev/null | wc -l");
    if ($output !== null) {
        $total = (int)trim($output);
    }

    $output = @shell_exec("pkg version -vRL= 2>/dev/null | wc -l");
    if ($output !== null) {
        $updates = (int)trim($output);
    }

    return ['total' => $total, 'updates' => $updates];
}

/**
 * Get traffic by address using pfctl state table.
 * Returns array of [host, in_bytes, out_bytes] entries.
 */
function get_traffic_by_address() {
    $hosts = [];
    $output = @shell_exec("pfctl -ss 2>/dev/null | head -100");
    if (empty($output)) {
        return $hosts;
    }

    $host_data = [];
    foreach (explode("\n", trim($output)) as $line) {
        /* Match state lines with IP addresses and byte counts */
        if (preg_match('/(\d+\.\d+\.\d+\.\d+)[:\s].*\[(\d+):(\d+)\]/', $line, $m)) {
            $ip = $m[1];
            $in = (int)$m[2];
            $out = (int)$m[3];
            if (!isset($host_data[$ip])) {
                $host_data[$ip] = ['in' => 0, 'out' => 0];
            }
            $host_data[$ip]['in'] += $in;
            $host_data[$ip]['out'] += $out;
        }
    }

    /* Sort by total traffic, descending */
    uasort($host_data, function($a, $b) {
        return ($b['in'] + $b['out']) - ($a['in'] + $a['out']);
    });

    $count = 0;
    foreach ($host_data as $ip => $data) {
        $hosts[] = ['host' => $ip, 'in' => $data['in'], 'out' => $data['out']];
        $count++;
        if ($count >= 10) {
            break;
        }
    }

    return $hosts;
}

/**
 * Get APC UPS status via apcaccess.
 */
function get_apcupsd_stats() {
    $output = @shell_exec("apcaccess 2>/dev/null");
    if (empty($output)) {
        return null;
    }

    $stats = [];
    foreach (explode("\n", $output) as $line) {
        if (preg_match('/^(\w+)\s*:\s*(.+)$/', trim($line), $m)) {
            $stats[trim($m[1])] = trim($m[2]);
        }
    }

    if (empty($stats)) {
        return null;
    }

    /* Parse numeric values */
    $linev = preg_replace('/[^0-9.]/', '', $stats['LINEV'] ?? '0');
    $bcharge = preg_replace('/[^0-9.]/', '', $stats['BCHARGE'] ?? '0');
    $timeleft = preg_replace('/[^0-9.]/', '', $stats['TIMELEFT'] ?? '0');
    $loadpct = preg_replace('/[^0-9.]/', '', $stats['LOADPCT'] ?? '0');

    return [
        'upsname' => $stats['UPSNAME'] ?? 'APC UPS',
        'status' => $stats['STATUS'] ?? 'N/A',
        'linev' => (float)$linev,
        'bcharge' => (float)$bcharge,
        'timeleft' => (float)$timeleft,
        'loadpct' => (float)$loadpct,
    ];
}

/**
 * Get NUT UPS status via upsc.
 */
function get_nutups_stats() {
    $output = @shell_exec("upsc ups@localhost 2>/dev/null");
    if (empty($output)) {
        return null;
    }

    $stats = [];
    foreach (explode("\n", $output) as $line) {
        if (preg_match('/^([\w.]+):\s*(.+)$/', trim($line), $m)) {
            $stats[trim($m[1])] = trim($m[2]);
        }
    }

    if (empty($stats)) {
        return null;
    }

    $runtime_secs = (int)($stats['battery.runtime'] ?? 0);
    $runtime_mins = round($runtime_secs / 60, 1);

    return [
        'upsname' => $stats['ups.mfr'] ?? 'NUT UPS',
        'status' => $stats['ups.status'] ?? 'N/A',
        'input_voltage' => (float)($stats['input.voltage'] ?? 0),
        'battery_charge' => (float)($stats['battery.charge'] ?? 0),
        'battery_runtime_mins' => $runtime_mins,
        'ups_load' => (float)($stats['ups.load'] ?? 0),
    ];
}

/* ======================== CFontzPacket LED OUTPUT ======================== */

/**
 * LED 1: Interface status (green if all Up, red if any Down).
 * On = bit 0 (green) = 1, Red = bit 6 = 64.
 */
function outputled_interface($lcd) {
    global $lcdproc_config;
    if (($lcdproc_config['driver'] ?? '') !== 'CFontzPacket') return 0;
    if (($lcdproc_config['outputleds'] ?? '0') !== '1') return 0;

    $ifdescrs = get_configured_interface_with_descr();
    $all_up = true;
    foreach ($ifdescrs as $ifdescr => $ifname) {
        $info = get_interface_info($ifdescr);
        if ($info['status'] !== 'up') {
            $all_up = false;
            break;
        }
    }

    return $all_up ? 1 : 64;
}

/**
 * LED 2: CARP status (green=Master, yellow=Backup, off=Disabled).
 * Green = bit 2 = 4, Yellow = bit 5 = 32.
 */
function outputled_carp() {
    global $lcdproc_config;
    if (($lcdproc_config['driver'] ?? '') !== 'CFontzPacket') return 0;
    if (($lcdproc_config['outputleds'] ?? '0') !== '1') return 0;

    $carp = get_carp_status();
    if ($carp === false) {
        return 0; /* CARP disabled, LED off */
    }

    list($master, $backup, $init) = $carp;
    if ($master > 0 && $backup == 0) {
        return 4; /* Green - all master */
    } elseif ($backup > 0) {
        return 32; /* Yellow - at least one backup */
    }
    return 0;
}

/**
 * LED 3: CPU usage (green if <=50%, yellow if >50%).
 * Green = bit 1 = 2, Yellow = bit 4 = 16.
 */
function outputled_cpu($cpu_usage) {
    global $lcdproc_config;
    if (($lcdproc_config['driver'] ?? '') !== 'CFontzPacket') return 0;
    if (($lcdproc_config['outputleds'] ?? '0') !== '1') return 0;

    return ($cpu_usage <= 50) ? 2 : 16;
}

/**
 * LED 4: Gateway status (green=all up, red=any down, off=none).
 * Green = bit 3 = 8, Red = bit 7 = 128.
 */
function outputled_gateway() {
    global $lcdproc_config;
    if (($lcdproc_config['driver'] ?? '') !== 'CFontzPacket') return 0;
    if (($lcdproc_config['outputleds'] ?? '0') !== '1') return 0;

    $gateways = return_gateways_status();
    if (empty($gateways)) {
        return 0; /* No gateways, LED off */
    }

    foreach ($gateways as $gw) {
        if ($gw['status'] === 'down') {
            return 128; /* Red - gateway down */
        }
    }
    return 8; /* Green - all up */
}


/* ======================== BUILD INTERFACE ======================== */

/**
 * Build all LCD screens and widgets.
 * Called once at startup and on reconnect.
 * Matches pfSense build_interface($lcd) exactly.
 */
function build_interface($lcd) {
    global $lcdpanel_width, $lcdpanel_height, $refresh_frequency;
    global $lcdproc_config;

    $cols = $lcdpanel_width;
    $rows = $lcdpanel_height;
    $duration = $refresh_frequency * 8; /* LCDproc duration in 1/8th seconds */

    /* Summary bar title (lines 3-4 on standard screens) */
    if ($cols > 16) {
        $summary_title = "CPU MEM STATES FREQ";
    } else {
        $summary_title = "CPU MEM STATES";
    }

    /* Helper: add a standard screen with title, text scroller, and summary bar */
    $add_standard_screen = function($name, $title) use ($lcd, $cols, $rows, $duration, $summary_title) {
        send_lcd_commands($lcd, [
            "screen_add {$name}",
            "screen_set {$name} heartbeat off",
            "screen_set {$name} name {$name}",
            "screen_set {$name} duration {$duration}",
            "widget_add {$name} title_wdgt string",
            "widget_set {$name} title_wdgt 1 1 \"{$title}\"",
            "widget_add {$name} text_wdgt scroller",
            "widget_set {$name} text_wdgt 1 2 {$cols} 2 h 2 \"\"",
        ]);
        if ($rows >= 4) {
            send_lcd_commands($lcd, [
                "widget_add {$name} title_summary string",
                "widget_set {$name} title_summary 1 3 \"{$summary_title}\"",
                "widget_add {$name} text_summary string",
                "widget_set {$name} text_summary 1 4 \"\"",
            ]);
        }
    };

    /* --- Control menu (when enabled) --- */
    if (($lcdproc_config['controlmenu'] ?? '0') === '1') {
        send_lcd_commands($lcd, [
            "menu_add_item \"\" maintenance menu \"CARP Maintenance\"",
            "menu_add_item maintenance enter_maint action \"Enter Maint. Mode\"",
            "menu_add_item maintenance leave_maint action \"Leave Maint. Mode\"",
            "menu_add_item \"\" restart_webgui menu \"Restart PHP+GUI\"",
            "menu_add_item restart_webgui restart_webgui_yes action \"Yes\"",
            "menu_add_item restart_webgui restart_webgui_no action \"No\"",
            "menu_add_item \"\" reboot_system menu \"Reboot\"",
            "menu_add_item reboot_system reboot_yes action \"Yes\"",
            "menu_add_item reboot_system reboot_no action \"No\"",
            "menu_add_item \"\" halt_system menu \"Shutdown\"",
            "menu_add_item halt_system halt_yes action \"Yes\"",
            "menu_add_item halt_system halt_no action \"No\"",
        ]);
    }

    /* --- scr_version --- */
    if (screen_enabled('scr_version')) {
        $add_standard_screen('scr_version', 'Welcome to');
    }

    /* --- scr_time --- */
    if (screen_enabled('scr_time')) {
        $add_standard_screen('scr_time', 'System Time');
    }

    /* --- scr_uptime --- */
    if (screen_enabled('scr_uptime')) {
        $add_standard_screen('scr_uptime', 'System Uptime');
    }

    /* --- scr_hostname --- */
    if (screen_enabled('scr_hostname')) {
        $add_standard_screen('scr_hostname', 'System Name');
    }

    /* --- scr_system --- */
    if (screen_enabled('scr_system')) {
        $add_standard_screen('scr_system', 'System Stats');
    }

    /* --- scr_disk --- */
    if (screen_enabled('scr_disk')) {
        $add_standard_screen('scr_disk', 'Disk Use');
    }

    /* --- scr_load --- */
    if (screen_enabled('scr_load')) {
        $add_standard_screen('scr_load', 'Load Averages');
    }

    /* --- scr_states --- */
    if (screen_enabled('scr_states')) {
        $add_standard_screen('scr_states', 'Traffic States');
    }

    /* --- scr_carp --- */
    if (screen_enabled('scr_carp')) {
        $add_standard_screen('scr_carp', 'CARP State');
    }

    /* --- scr_ipsec --- */
    if (screen_enabled('scr_ipsec')) {
        $add_standard_screen('scr_ipsec', 'IPsec Tunnels');
    }

    /* --- scr_interfaces --- */
    if (screen_enabled('scr_interfaces')) {
        $add_standard_screen('scr_interfaces', 'Interfaces');
    }

    /* --- scr_gwsum --- */
    if (screen_enabled('scr_gwsum')) {
        $add_standard_screen('scr_gwsum', 'GW Summary');
    }

    /* --- scr_mbuf --- */
    if (screen_enabled('scr_mbuf')) {
        $add_standard_screen('scr_mbuf', 'MBuf Usage');
    }

    /* --- scr_packages --- */
    if (screen_enabled('scr_packages')) {
        /* Dynamic title set in output_status */
        $pkg_title = ($cols >= 20) ? "Installed Packages" : "Packages";
        $add_standard_screen('scr_packages', $pkg_title);
    }

    /* --- scr_cpufrequency --- */
    if (screen_enabled('scr_cpufrequency')) {
        $add_standard_screen('scr_cpufrequency', 'CPU Frequency');
    }

    /* --- scr_cputemperature --- */
    if (screen_enabled('scr_cputemperature')) {
        /* Dynamic title set in output_status */
        $temp_title = ($cols >= 20) ? "CPU Temperature" : "CPU Temp";
        $add_standard_screen('scr_cputemperature', $temp_title);
    }

    /* --- scr_ntp (special: NO title_wdgt, 4 scrollers) --- */
    if (screen_enabled('scr_ntp')) {
        send_lcd_commands($lcd, [
            "screen_add scr_ntp",
            "screen_set scr_ntp heartbeat off",
            "screen_set scr_ntp name scr_ntp",
            "screen_set scr_ntp duration {$duration}",
            "widget_add scr_ntp time_st_wdgt scroller",
            "widget_set scr_ntp time_st_wdgt 1 1 {$cols} 1 h 2 \"\"",
            "widget_add scr_ntp ref_wdgt scroller",
            "widget_set scr_ntp ref_wdgt 1 2 {$cols} 2 h 2 \"\"",
        ]);
        if ($rows >= 3) {
            send_lcd_commands($lcd, [
                "widget_add scr_ntp text_wdgt scroller",
                "widget_set scr_ntp text_wdgt 1 3 {$cols} 3 h 2 \"\"",
            ]);
        }
        if ($rows >= 4) {
            send_lcd_commands($lcd, [
                "widget_add scr_ntp stats_wdgt scroller",
                "widget_set scr_ntp stats_wdgt 1 4 {$cols} 4 h 2 \"\"",
            ]);
        }
    }

    /* --- scr_traffic (special: title_wdgt string + text_wdgt string) --- */
    if (screen_enabled('scr_traffic')) {
        send_lcd_commands($lcd, [
            "screen_add scr_traffic",
            "screen_set scr_traffic heartbeat off",
            "screen_set scr_traffic name scr_traffic",
            "screen_set scr_traffic duration {$duration}",
            "widget_add scr_traffic title_wdgt string",
            "widget_set scr_traffic title_wdgt 1 1 \"\"",
            "widget_add scr_traffic text_wdgt string",
            "widget_set scr_traffic text_wdgt 1 2 \"\"",
        ]);
        /* pfSense: traffic screen gets standard summary bar on 4-line displays */
        if ($rows >= 4) {
            send_lcd_commands($lcd, [
                "widget_add scr_traffic title_summary string",
                "widget_set scr_traffic title_summary 1 3 \"{$summary_title}\"",
                "widget_add scr_traffic text_summary string",
                "widget_set scr_traffic text_summary 1 4 \"\"",
            ]);
        }
    }

    /* --- scr_top_interfaces_by_bps --- */
    if (screen_enabled('scr_top_interfaces_by_bps')) {
        send_lcd_commands($lcd, [
            "screen_add scr_top_interfaces_by_bps",
            "screen_set scr_top_interfaces_by_bps heartbeat off",
            "screen_set scr_top_interfaces_by_bps name scr_top_interfaces_by_bps",
            "screen_set scr_top_interfaces_by_bps duration {$duration}",
            "widget_add scr_top_interfaces_by_bps title_wdgt string",
            "widget_set scr_top_interfaces_by_bps title_wdgt 1 1 \"\"",
        ]);
        for ($i = 0; $i < ($rows - 1); $i++) {
            $y = $i + 2;
            send_lcd_commands($lcd, [
                "widget_add scr_top_interfaces_by_bps text_wdgt{$i} string",
                "widget_set scr_top_interfaces_by_bps text_wdgt{$i} 1 {$y} \"\"",
            ]);
        }
    }

    /* --- scr_top_interfaces_by_bytes_today --- */
    if (screen_enabled('scr_top_interfaces_by_bytes_today')) {
        send_lcd_commands($lcd, [
            "screen_add scr_top_interfaces_by_bytes_today",
            "screen_set scr_top_interfaces_by_bytes_today heartbeat off",
            "screen_set scr_top_interfaces_by_bytes_today name scr_top_interfaces_by_bytes_today",
            "screen_set scr_top_interfaces_by_bytes_today duration {$duration}",
            "widget_add scr_top_interfaces_by_bytes_today title_wdgt string",
            "widget_set scr_top_interfaces_by_bytes_today title_wdgt 1 1 \"\"",
        ]);
        for ($i = 0; $i < ($rows - 1); $i++) {
            $y = $i + 2;
            send_lcd_commands($lcd, [
                "widget_add scr_top_interfaces_by_bytes_today text_wdgt{$i} string",
                "widget_set scr_top_interfaces_by_bytes_today text_wdgt{$i} 1 {$y} \"\"",
            ]);
        }
    }

    /* --- scr_top_interfaces_by_total_bytes --- */
    if (screen_enabled('scr_top_interfaces_by_total_bytes')) {
        send_lcd_commands($lcd, [
            "screen_add scr_top_interfaces_by_total_bytes",
            "screen_set scr_top_interfaces_by_total_bytes heartbeat off",
            "screen_set scr_top_interfaces_by_total_bytes name scr_top_interfaces_by_total_bytes",
            "screen_set scr_top_interfaces_by_total_bytes duration {$duration}",
            "widget_add scr_top_interfaces_by_total_bytes title_wdgt string",
            "widget_set scr_top_interfaces_by_total_bytes title_wdgt 1 1 \"\"",
        ]);
        for ($i = 0; $i < ($rows - 1); $i++) {
            $y = $i + 2;
            send_lcd_commands($lcd, [
                "widget_add scr_top_interfaces_by_total_bytes text_wdgt{$i} string",
                "widget_set scr_top_interfaces_by_total_bytes text_wdgt{$i} 1 {$y} \"\"",
            ]);
        }
    }

    /* --- scr_gwstatus (per-gateway sub-screens) --- */
    if (screen_enabled('scr_gwstatus')) {
        $gateways = return_gateways_status();
        $gw_num = 0;
        foreach ($gateways as $gw) {
            $scr = "scr_gwstatus_{$gw_num}";
            send_lcd_commands($lcd, [
                "screen_add {$scr}",
                "screen_set {$scr} heartbeat off",
                "screen_set {$scr} name {$scr}",
                "screen_set {$scr} duration {$duration}",
                "widget_add {$scr} gwname_wdgt string",
                "widget_set {$scr} gwname_wdgt 1 1 \"{$gw['name']}\"",
                "widget_add {$scr} stata_wdgt scroller",
                "widget_set {$scr} stata_wdgt 1 2 {$cols} 2 h 2 \"\"",
            ]);
            if ($rows >= 3) {
                send_lcd_commands($lcd, [
                    "widget_add {$scr} lossl_wdgt string",
                    "widget_set {$scr} lossl_wdgt 1 3 \"Loss:\"",
                    "widget_add {$scr} lossa_wdgt scroller",
                    "widget_set {$scr} lossa_wdgt 6 3 {$cols} 3 h 2 \"\"",
                ]);
            }
            if ($rows >= 4) {
                send_lcd_commands($lcd, [
                    "widget_add {$scr} rttl_wdgt string",
                    "widget_set {$scr} rttl_wdgt 1 4 \"Delay:\"",
                    "widget_add {$scr} rtta_wdgt scroller",
                    "widget_set {$scr} rtta_wdgt 7 4 {$cols} 4 h 2 \"\"",
                ]);
            }
            $gw_num++;
        }
    }

    /* --- scr_interfaces_link (per-interface sub-screens) --- */
    if (screen_enabled('scr_interfaces_link')) {
        $ifdescrs = get_configured_interface_with_descr();
        $if_num = 0;
        foreach ($ifdescrs as $ifdescr => $ifname) {
            $scr = "scr_interfaces_link_{$if_num}";
            send_lcd_commands($lcd, [
                "screen_add {$scr}",
                "screen_set {$scr} heartbeat off",
                "screen_set {$scr} name {$scr}",
                "screen_set {$scr} duration {$duration}",
                "widget_add {$scr} ifname_wdgt string",
                "widget_set {$scr} ifname_wdgt 1 1 \"{$ifname}:\"",
                "widget_add {$scr} link_wdgt scroller",
            ]);
            /* link_wdgt starts after the name label */
            $label_len = strlen($ifname) + 2;
            send_lcd_commands($lcd, [
                "widget_set {$scr} link_wdgt {$label_len} 1 {$cols} 1 h 2 \"\"",
            ]);
            if ($rows >= 2) {
                send_lcd_commands($lcd, [
                    "widget_add {$scr} v4l_wdgt string",
                    "widget_set {$scr} v4l_wdgt 1 2 \"v4:\"",
                    "widget_add {$scr} v4a_wdgt scroller",
                    "widget_set {$scr} v4a_wdgt 4 2 {$cols} 2 h 2 \"\"",
                ]);
            }
            if ($rows >= 3) {
                send_lcd_commands($lcd, [
                    "widget_add {$scr} v6l_wdgt string",
                    "widget_set {$scr} v6l_wdgt 1 3 \"v6:\"",
                    "widget_add {$scr} v6a_wdgt scroller",
                    "widget_set {$scr} v6a_wdgt 4 3 {$cols} 3 h 2 \"\"",
                ]);
            }
            if ($rows >= 4) {
                send_lcd_commands($lcd, [
                    "widget_add {$scr} macl_wdgt string",
                    "widget_set {$scr} macl_wdgt 1 4 \"m:\"",
                    "widget_add {$scr} maca_wdgt scroller",
                    "widget_set {$scr} maca_wdgt 3 4 {$cols} 4 h 2 \"\"",
                ]);
            }
            $if_num++;
        }
    }

    /* --- scr_traffic_by_address --- */
    if (screen_enabled('scr_traffic_by_address')) {
        send_lcd_commands($lcd, [
            "screen_add scr_traffic_by_address",
            "screen_set scr_traffic_by_address heartbeat off",
            "screen_set scr_traffic_by_address name scr_traffic_by_address",
            "screen_set scr_traffic_by_address duration {$duration}",
            "widget_add scr_traffic_by_address title_wdgt string",
            "widget_set scr_traffic_by_address title_wdgt 1 1 \"Host       IN / OUT\"",
            "widget_add scr_traffic_by_address heart_wdgt icon",
            "widget_set scr_traffic_by_address heart_wdgt {$cols} 1 HEART_OPEN",
        ]);
        for ($i = 0; $i < ($rows - 1); $i++) {
            $y = $i + 2;
            send_lcd_commands($lcd, [
                "widget_add scr_traffic_by_address descr_wdgt{$i} scroller",
                "widget_set scr_traffic_by_address descr_wdgt{$i} 1 {$y} 8 {$y} h 2 \"\"",
                "widget_add scr_traffic_by_address data_wdgt{$i} string",
                "widget_set scr_traffic_by_address data_wdgt{$i} 9 {$y} \"\"",
            ]);
        }
    }

    /* --- scr_apcupsd --- */
    if (screen_enabled('scr_apcupsd')) {
        send_lcd_commands($lcd, [
            "screen_add scr_apcupsd",
            "screen_set scr_apcupsd heartbeat off",
            "screen_set scr_apcupsd name scr_apcupsd",
            "screen_set scr_apcupsd duration {$duration}",
            "widget_add scr_apcupsd apctitlel_wdgt string",
            "widget_set scr_apcupsd apctitlel_wdgt 1 1 \"APC UPS:\"",
            "widget_add scr_apcupsd apcname_wdgt scroller",
            "widget_set scr_apcupsd apcname_wdgt 10 1 {$cols} 1 h 2 \"\"",
            "widget_add scr_apcupsd apcstatus_wdgt scroller",
            "widget_set scr_apcupsd apcstatus_wdgt 1 2 {$cols} 2 h 2 \"\"",
        ]);
        if ($rows >= 3) {
            send_lcd_commands($lcd, [
                "widget_add scr_apcupsd apctitle_summary string",
                "widget_set scr_apcupsd apctitle_summary 1 3 \"LINE CHRG TIMEL LOAD\"",
            ]);
        }
        if ($rows >= 4) {
            send_lcd_commands($lcd, [
                "widget_add scr_apcupsd apc_summary string",
                "widget_set scr_apcupsd apc_summary 1 4 \"\"",
            ]);
        }
    }

    /* --- scr_nutups --- */
    if (screen_enabled('scr_nutups')) {
        send_lcd_commands($lcd, [
            "screen_add scr_nutups",
            "screen_set scr_nutups heartbeat off",
            "screen_set scr_nutups name scr_nutups",
            "screen_set scr_nutups duration {$duration}",
            "widget_add scr_nutups nuttitlel_wdgt string",
            "widget_set scr_nutups nuttitlel_wdgt 1 1 \"NUT UPS:\"",
            "widget_add scr_nutups nutname_wdgt scroller",
            "widget_set scr_nutups nutname_wdgt 10 1 {$cols} 1 h 2 \"\"",
            "widget_add scr_nutups nutstatus_wdgt scroller",
            "widget_set scr_nutups nutstatus_wdgt 1 2 {$cols} 2 h 2 \"\"",
        ]);
        if ($rows >= 3) {
            send_lcd_commands($lcd, [
                "widget_add scr_nutups nuttitle_summary string",
                "widget_set scr_nutups nuttitle_summary 1 3 \"LIN CHRG RUNTHMS LOD\"",
            ]);
        }
        if ($rows >= 4) {
            send_lcd_commands($lcd, [
                "widget_add scr_nutups nut_summary string",
                "widget_set scr_nutups nut_summary 1 4 \"\"",
            ]);
        }
    }

    lcdproc_notice("All screens and widgets initialized");
}


/* ======================== OUTPUT STATUS ======================== */

/**
 * Update all screen widget data.
 * Called every refresh cycle from loop_status().
 * Matches pfSense output_status() exactly for LCD text output.
 */
function output_status($lcd) {
    global $lcdpanel_width, $lcdpanel_height;
    global $lcdproc_config;

    $cols = $lcdpanel_width;
    $rows = $lcdpanel_height;

    /* ---- Compute summary bar data (shown on lines 3-4 of standard screens) ---- */
    $cpu = lcdproc_cpu_usage();
    $mem = mem_usage();
    $states = get_pfstate();
    $summary_states = $states[0];

    if ($cols > 16) {
        $freq = get_cpu_freq();
        $curfreq = $freq[0];
        $maxfreq = $freq[1];
        $freqpct = ($maxfreq > 0) ? (int)round($curfreq / $maxfreq * 100) : 0;
        $lcd_summary_data = sprintf("%02d%% %02d%% %6d %3d%%", $cpu, $mem, $summary_states, $freqpct);
        $lcd_summary_title = "CPU MEM STATES FREQ";
    } else {
        $lcd_summary_data = sprintf("%02d%% %02d%% %6d", $cpu, $mem, $summary_states);
        $lcd_summary_title = "CPU MEM STATES";
    }

    /* Helper: update summary bar on a standard screen */
    $update_summary = function($scr) use ($lcd, $rows, $lcd_summary_title, $lcd_summary_data) {
        if ($rows >= 4) {
            send_lcd_commands($lcd, [
                "widget_set {$scr} title_summary 1 3 \"{$lcd_summary_title}\"",
                "widget_set {$scr} text_summary 1 4 \"{$lcd_summary_data}\"",
            ]);
        }
    };

    /* ---- scr_version ---- */
    if (screen_enabled('scr_version')) {
        $version = trim(@shell_exec("opnsense-version -v 2>/dev/null") ?? '');
        if (empty($version)) {
            $version = 'Unknown';
        }
        $version_text = PRODUCT_NAME . " " . $version;
        send_lcd_commands($lcd, [
            "widget_set scr_version title_wdgt 1 1 \"Welcome to\"",
            "widget_set scr_version text_wdgt 1 2 {$cols} 2 h 2 \"{$version_text}\"",
        ]);
        $update_summary('scr_version');
    }

    /* ---- scr_time ---- */
    if (screen_enabled('scr_time')) {
        $time_str = date("n/j/Y H:i");
        send_lcd_commands($lcd, [
            "widget_set scr_time title_wdgt 1 1 \"System Time\"",
            "widget_set scr_time text_wdgt 1 2 {$cols} 2 h 2 \"{$time_str}\"",
        ]);
        $update_summary('scr_time');
    }

    /* ---- scr_uptime ---- */
    if (screen_enabled('scr_uptime')) {
        $uptime = get_uptime_str();
        send_lcd_commands($lcd, [
            "widget_set scr_uptime title_wdgt 1 1 \"System Uptime\"",
            "widget_set scr_uptime text_wdgt 1 2 {$cols} 2 h 2 \"{$uptime}\"",
        ]);
        $update_summary('scr_uptime');
    }

    /* ---- scr_hostname ---- */
    if (screen_enabled('scr_hostname')) {
        $hostname = trim(@shell_exec("/bin/hostname 2>/dev/null") ?? '');
        if (empty($hostname)) {
            $hostname = gethostname() ?: 'unknown';
        }
        send_lcd_commands($lcd, [
            "widget_set scr_hostname title_wdgt 1 1 \"System Name\"",
            "widget_set scr_hostname text_wdgt 1 2 {$cols} 2 h 2 \"{$hostname}\"",
        ]);
        $update_summary('scr_hostname');
    }

    /* ---- scr_system ---- */
    if (screen_enabled('scr_system')) {
        $sys_text = sprintf("CPU %d%%, Mem %d%%", $cpu, $mem);
        send_lcd_commands($lcd, [
            "widget_set scr_system title_wdgt 1 1 \"System Stats\"",
            "widget_set scr_system text_wdgt 1 2 {$cols} 2 h 2 \"{$sys_text}\"",
        ]);
        $update_summary('scr_system');
    }

    /* ---- scr_disk ---- */
    if (screen_enabled('scr_disk')) {
        $disk = disk_usage();
        $disk_text = sprintf("Disk %d%%", $disk);
        send_lcd_commands($lcd, [
            "widget_set scr_disk title_wdgt 1 1 \"Disk Use\"",
            "widget_set scr_disk text_wdgt 1 2 {$cols} 2 h 2 \"{$disk_text}\"",
        ]);
        $update_summary('scr_disk');
    }

    /* ---- scr_load ---- */
    if (screen_enabled('scr_load')) {
        $load = get_loadavg_str();
        send_lcd_commands($lcd, [
            "widget_set scr_load title_wdgt 1 1 \"Load Averages\"",
            "widget_set scr_load text_wdgt 1 2 {$cols} 2 h 2 \"{$load}\"",
        ]);
        $update_summary('scr_load');
    }

    /* ---- scr_states ---- */
    if (screen_enabled('scr_states')) {
        $states_text = sprintf("Cur/Max %d/%d", $states[0], $states[1]);
        send_lcd_commands($lcd, [
            "widget_set scr_states title_wdgt 1 1 \"Traffic States\"",
            "widget_set scr_states text_wdgt 1 2 {$cols} 2 h 2 \"{$states_text}\"",
        ]);
        $update_summary('scr_states');
    }

    /* ---- scr_carp ---- */
    if (screen_enabled('scr_carp')) {
        $carp = get_carp_status();
        if ($carp === false) {
            $carp_text = "CARP Disabled";
        } else {
            list($master, $backup, $init) = $carp;
            $carp_text = sprintf("M/B/I %d/%d/%d", $master, $backup, $init);
        }
        send_lcd_commands($lcd, [
            "widget_set scr_carp title_wdgt 1 1 \"CARP State\"",
            "widget_set scr_carp text_wdgt 1 2 {$cols} 2 h 2 \"{$carp_text}\"",
        ]);
        $update_summary('scr_carp');
    }

    /* ---- scr_ipsec ---- */
    if (screen_enabled('scr_ipsec')) {
        if (!ipsec_enabled()) {
            $ipsec_text = "IPsec Disabled";
        } else {
            $sa = ipsec_list_sa();
            $ipsec_text = sprintf("Up/Down %d/%d", $sa['active'], $sa['inactive']);
        }
        send_lcd_commands($lcd, [
            "widget_set scr_ipsec title_wdgt 1 1 \"IPsec Tunnels\"",
            "widget_set scr_ipsec text_wdgt 1 2 {$cols} 2 h 2 \"{$ipsec_text}\"",
        ]);
        $update_summary('scr_ipsec');
    }

    /* ---- scr_interfaces ---- */
    if (screen_enabled('scr_interfaces')) {
        $ifdescrs = get_configured_interface_with_descr();
        $if_parts = [];
        foreach ($ifdescrs as $ifdescr => $ifname) {
            $info = get_interface_info($ifdescr);
            $status = ($info['status'] === 'up') ? 'Up' : 'Down';
            $if_parts[] = " {$ifname} [{$status}]";
        }
        $if_text = implode(",", $if_parts);
        if (empty($if_text)) {
            $if_text = "No interfaces";
        }
        send_lcd_commands($lcd, [
            "widget_set scr_interfaces title_wdgt 1 1 \"Interfaces\"",
            "widget_set scr_interfaces text_wdgt 1 2 {$cols} 2 h 2 \"{$if_text}\"",
        ]);
        $update_summary('scr_interfaces');
    }

    /* ---- scr_gwsum ---- */
    if (screen_enabled('scr_gwsum')) {
        $gateways = return_gateways_status();
        $gw_up = 0;
        $gw_down = 0;
        foreach ($gateways as $gw) {
            if ($gw['status'] === 'down') {
                $gw_down++;
            } else {
                $gw_up++;
            }
        }
        $gwsum_text = sprintf("Up: %d / Down: %d", $gw_up, $gw_down);
        send_lcd_commands($lcd, [
            "widget_set scr_gwsum title_wdgt 1 1 \"GW Summary\"",
            "widget_set scr_gwsum text_wdgt 1 2 {$cols} 2 h 2 \"{$gwsum_text}\"",
        ]);
        $update_summary('scr_gwsum');
    }

    /* ---- scr_mbuf ---- */
    if (screen_enabled('scr_mbuf')) {
        $mbufs = '';
        $mbufpct = 0;
        get_mbuf($mbufs, $mbufpct);
        $mbuf_text = "{$mbufs} ({$mbufpct}%)";
        send_lcd_commands($lcd, [
            "widget_set scr_mbuf title_wdgt 1 1 \"MBuf Usage\"",
            "widget_set scr_mbuf text_wdgt 1 2 {$cols} 2 h 2 \"{$mbuf_text}\"",
        ]);
        $update_summary('scr_mbuf');
    }

    /* ---- scr_packages ---- */
    if (screen_enabled('scr_packages')) {
        $pkg = get_package_info();
        $pkg_title = ($cols >= 20) ? "Installed Packages" : "Packages";

        if ($pkg['total'] === 0) {
            $pkg_text = "None Installed";
        } elseif ($pkg['updates'] > 0) {
            $pkg_text = sprintf("T: %d NU: %d", $pkg['total'], $pkg['updates']);
        } else {
            $pkg_text = sprintf("T: %d", $pkg['total']);
        }
        send_lcd_commands($lcd, [
            "widget_set scr_packages title_wdgt 1 1 \"{$pkg_title}\"",
            "widget_set scr_packages text_wdgt 1 2 {$cols} 2 h 2 \"{$pkg_text}\"",
        ]);
        $update_summary('scr_packages');
    }

    /* ---- scr_cpufrequency ---- */
    if (screen_enabled('scr_cpufrequency')) {
        $freq = get_cpu_freq();
        if ($freq[0] > 0 && $freq[1] > 0) {
            $freq_text = sprintf("%d/%d Mhz", $freq[0], $freq[1]);
        } elseif ($freq[0] > 0) {
            $freq_text = sprintf("%d Mhz", $freq[0]);
        } else {
            $freq_text = "N/A";
        }
        send_lcd_commands($lcd, [
            "widget_set scr_cpufrequency title_wdgt 1 1 \"CPU Frequency\"",
            "widget_set scr_cpufrequency text_wdgt 1 2 {$cols} 2 h 2 \"{$freq_text}\"",
        ]);
        $update_summary('scr_cpufrequency');
    }

    /* ---- scr_cputemperature ---- */
    if (screen_enabled('scr_cputemperature')) {
        $temp_title = ($cols >= 20) ? "CPU Temperature" : "CPU Temp";
        $temp_c = get_temp();
        if ($temp_c !== '') {
            /* Check config for F vs C preference */
            $unit = get_screen_config('scr_cputemperature_unit');
            if ($unit === 'f' || $unit === 'F') {
                $temp_f = ($temp_c * 9 / 5) + 32;
                $temp_text = sprintf("%.1fF", $temp_f);
            } else {
                $temp_text = sprintf("%.1fC", $temp_c);
            }
        } else {
            $temp_text = "N/A";
        }
        send_lcd_commands($lcd, [
            "widget_set scr_cputemperature title_wdgt 1 1 \"{$temp_title}\"",
            "widget_set scr_cputemperature text_wdgt 1 2 {$cols} 2 h 2 \"{$temp_text}\"",
        ]);
        $update_summary('scr_cputemperature');
    }

    /* ---- scr_ntp (special: 4 scrollers, no title_wdgt) ---- */
    if (screen_enabled('scr_ntp')) {
        $ntp = lcdproc_get_ntp_status();
        send_lcd_commands($lcd, [
            "widget_set scr_ntp time_st_wdgt 1 1 {$cols} 1 h 2 \"{$ntp['line1']}\"",
            "widget_set scr_ntp ref_wdgt 1 2 {$cols} 2 h 2 \"{$ntp['line2']}\"",
        ]);
        if ($rows >= 3) {
            send_lcd_commands($lcd, [
                "widget_set scr_ntp text_wdgt 1 3 {$cols} 3 h 2 \"{$ntp['line3']}\"",
            ]);
        }
        if ($rows >= 4) {
            send_lcd_commands($lcd, [
                "widget_set scr_ntp stats_wdgt 1 4 {$cols} 4 h 2 \"{$ntp['line4']}\"",
            ]);
        }
    }

    /* ---- scr_traffic ---- */
    if (screen_enabled('scr_traffic')) {
        $traffic_if = get_screen_config('scr_traffic_interface');
        if (empty($traffic_if)) {
            /* Default to first interface */
            $ifdescrs = get_configured_interface_with_descr();
            $traffic_if = !empty($ifdescrs) ? array_key_first($ifdescrs) : '';
        }

        if (!empty($traffic_if)) {
            $bps = get_traffic_bps($traffic_if);

            $in_str = sprintf("IN:  %s", format_bps($bps['in_bps']));
            $out_str = sprintf("OUT: %s", format_bps($bps['out_bps']));

            /* pfSense layout: line 1 = IN data, line 2 = OUT data */
            send_lcd_commands($lcd, [
                "widget_set scr_traffic title_wdgt 1 1 \"{$in_str}\"",
                "widget_set scr_traffic text_wdgt 1 2 \"{$out_str}\"",
            ]);
            if ($rows >= 4) {
                send_lcd_commands($lcd, [
                    "widget_set scr_traffic text_summary 1 4 \"{$lcd_summary_data}\"",
                ]);
            }
        }
    }

    /* ---- scr_top_interfaces_by_bps ---- */
    if (screen_enabled('scr_top_interfaces_by_bps')) {
        if ($cols >= 20) {
            $title = "Interface bps IN/OUT";
        } else {
            $title = "Intf. bps IN/OUT";
        }

        send_lcd_commands($lcd, [
            "widget_set scr_top_interfaces_by_bps title_wdgt 1 1 \"{$title}\"",
        ]);

        $ifdescrs = get_configured_interface_with_descr();
        $iface_data = [];
        foreach ($ifdescrs as $ifdescr => $ifname) {
            $bps = get_traffic_bps($ifdescr);
            $iface_data[] = [
                'name' => $ifname,
                'in_bps' => $bps['in_bps'],
                'out_bps' => $bps['out_bps'],
                'total_bps' => $bps['in_bps'] + $bps['out_bps'],
            ];
        }
        usort($iface_data, function($a, $b) {
            return $b['total_bps'] - $a['total_bps'];
        });

        for ($i = 0; $i < ($rows - 1); $i++) {
            if (isset($iface_data[$i])) {
                $d = $iface_data[$i];
                $name = substr($d['name'], 0, 5);
                $in = format_bytes_short($d['in_bps'] / 8);
                $out = format_bytes_short($d['out_bps'] / 8);
                $line = str_pad(sprintf("%-5s %s/%s", $name, $in, $out), $cols);
            } else {
                $line = str_pad("", $cols);
            }
            send_lcd_commands($lcd, [
                "widget_set scr_top_interfaces_by_bps text_wdgt{$i} 1 " . ($i + 2) . " \"{$line}\"",
            ]);
        }
    }

    /* ---- scr_top_interfaces_by_bytes_today ---- */
    if (screen_enabled('scr_top_interfaces_by_bytes_today')) {
        if ($cols >= 20) {
            $title = "Total today   IN/OUT";
        } else {
            $title = "Today  IN/OUT";
        }

        send_lcd_commands($lcd, [
            "widget_set scr_top_interfaces_by_bytes_today title_wdgt 1 1 \"{$title}\"",
        ]);

        $ifdescrs = get_configured_interface_with_descr();
        $iface_data = [];
        foreach ($ifdescrs as $ifdescr => $ifname) {
            $realif = get_real_interface($ifdescr);
            $stats = get_interface_stats($realif);
            $iface_data[] = [
                'name' => $ifname,
                'in' => $stats['inbytes'],
                'out' => $stats['outbytes'],
                'total' => $stats['inbytes'] + $stats['outbytes'],
            ];
        }
        usort($iface_data, function($a, $b) {
            return $b['total'] - $a['total'];
        });

        for ($i = 0; $i < ($rows - 1); $i++) {
            if (isset($iface_data[$i])) {
                $d = $iface_data[$i];
                $name = substr($d['name'], 0, 5);
                $in = format_bytes_short($d['in']);
                $out = format_bytes_short($d['out']);
                $line = str_pad(sprintf("%-5s %s/%s", $name, $in, $out), $cols);
            } else {
                $line = str_pad("", $cols);
            }
            send_lcd_commands($lcd, [
                "widget_set scr_top_interfaces_by_bytes_today text_wdgt{$i} 1 " . ($i + 2) . " \"{$line}\"",
            ]);
        }
    }

    /* ---- scr_top_interfaces_by_total_bytes ---- */
    if (screen_enabled('scr_top_interfaces_by_total_bytes')) {
        if ($cols >= 20) {
            $title = "Total         IN/OUT";
        } else {
            $title = "Total  IN/OUT";
        }

        send_lcd_commands($lcd, [
            "widget_set scr_top_interfaces_by_total_bytes title_wdgt 1 1 \"{$title}\"",
        ]);

        $ifdescrs = get_configured_interface_with_descr();
        $iface_data = [];
        foreach ($ifdescrs as $ifdescr => $ifname) {
            $realif = get_real_interface($ifdescr);
            $stats = get_interface_stats($realif);
            $iface_data[] = [
                'name' => $ifname,
                'in' => $stats['inbytes'],
                'out' => $stats['outbytes'],
                'total' => $stats['inbytes'] + $stats['outbytes'],
            ];
        }
        usort($iface_data, function($a, $b) {
            return $b['total'] - $a['total'];
        });

        for ($i = 0; $i < ($rows - 1); $i++) {
            if (isset($iface_data[$i])) {
                $d = $iface_data[$i];
                $name = substr($d['name'], 0, 5);
                $in = format_bytes_short($d['in']);
                $out = format_bytes_short($d['out']);
                $line = str_pad(sprintf("%-5s %s/%s", $name, $in, $out), $cols);
            } else {
                $line = str_pad("", $cols);
            }
            send_lcd_commands($lcd, [
                "widget_set scr_top_interfaces_by_total_bytes text_wdgt{$i} 1 " . ($i + 2) . " \"{$line}\"",
            ]);
        }
    }

    /* ---- scr_gwstatus (per-gateway sub-screens) ---- */
    if (screen_enabled('scr_gwstatus')) {
        $gateways = return_gateways_status();
        $gw_num = 0;
        foreach ($gateways as $gw) {
            $scr = "scr_gwstatus_{$gw_num}";

            /* Status description */
            if ($gw['status'] === 'down') {
                $status_text = "Offline";
            } elseif ($gw['status'] === 'warning' || $gw['substatus'] === 'loss') {
                $status_text = "Packetloss";
            } else {
                $status_text = "Online";
            }

            send_lcd_commands($lcd, [
                "widget_set {$scr} gwname_wdgt 1 1 \"{$gw['name']}\"",
                "widget_set {$scr} stata_wdgt 1 2 {$cols} 2 h 2 \"{$status_text}\"",
            ]);
            if ($rows >= 3) {
                send_lcd_commands($lcd, [
                    "widget_set {$scr} lossl_wdgt 1 3 \"Loss:\"",
                    "widget_set {$scr} lossa_wdgt 6 3 {$cols} 3 h 2 \"{$gw['loss']}\"",
                ]);
            }
            if ($rows >= 4) {
                send_lcd_commands($lcd, [
                    "widget_set {$scr} rttl_wdgt 1 4 \"Delay:\"",
                    "widget_set {$scr} rtta_wdgt 7 4 {$cols} 4 h 2 \"{$gw['delay']}\"",
                ]);
            }
            $gw_num++;
        }
    }

    /* ---- scr_interfaces_link (per-interface sub-screens) ---- */
    if (screen_enabled('scr_interfaces_link')) {
        $ifdescrs = get_configured_interface_with_descr();
        $if_num = 0;
        foreach ($ifdescrs as $ifdescr => $ifname) {
            $scr = "scr_interfaces_link_{$if_num}";
            $info = get_interface_info($ifdescr);

            /* Link status text */
            $link_text = ($info['status'] === 'up') ? 'up' : 'down';
            if (!empty($info['media'])) {
                $link_text .= " " . $info['media'];
            }

            $v4 = !empty($info['ipaddr']) ? $info['ipaddr'] : 'N/A';
            $v6 = !empty($info['ipv6addr']) ? $info['ipv6addr'] : 'N/A';
            $mac = !empty($info['macaddr']) ? $info['macaddr'] : 'N/A';

            $label_len = strlen($ifname) + 2;
            send_lcd_commands($lcd, [
                "widget_set {$scr} ifname_wdgt 1 1 \"{$ifname}:\"",
                "widget_set {$scr} link_wdgt {$label_len} 1 {$cols} 1 h 2 \"{$link_text}\"",
            ]);
            if ($rows >= 2) {
                send_lcd_commands($lcd, [
                    "widget_set {$scr} v4l_wdgt 1 2 \"v4:\"",
                    "widget_set {$scr} v4a_wdgt 4 2 {$cols} 2 h 2 \"{$v4}\"",
                ]);
            }
            if ($rows >= 3) {
                send_lcd_commands($lcd, [
                    "widget_set {$scr} v6l_wdgt 1 3 \"v6:\"",
                    "widget_set {$scr} v6a_wdgt 4 3 {$cols} 3 h 2 \"{$v6}\"",
                ]);
            }
            if ($rows >= 4) {
                send_lcd_commands($lcd, [
                    "widget_set {$scr} macl_wdgt 1 4 \"m:\"",
                    "widget_set {$scr} maca_wdgt 3 4 {$cols} 4 h 2 \"{$mac}\"",
                ]);
            }
            $if_num++;
        }
    }

    /* ---- scr_traffic_by_address ---- */
    if (screen_enabled('scr_traffic_by_address')) {
        $hosts = get_traffic_by_address();

        send_lcd_commands($lcd, [
            "widget_set scr_traffic_by_address title_wdgt 1 1 \"Host       IN / OUT\"",
            "widget_set scr_traffic_by_address heart_wdgt {$cols} 1 HEART_OPEN",
        ]);

        for ($i = 0; $i < ($rows - 1); $i++) {
            $y = $i + 2;
            if (isset($hosts[$i])) {
                $h = $hosts[$i];
                $host_short = substr($h['host'], 0, 8);
                $in = format_bytes_short($h['in']);
                $out = format_bytes_short($h['out']);
                $data = str_pad(sprintf("%s/%s", $in, $out), $cols - 8);
            } else {
                $host_short = "";
                $data = "";
            }
            send_lcd_commands($lcd, [
                "widget_set scr_traffic_by_address descr_wdgt{$i} 1 {$y} 8 {$y} h 2 \"{$host_short}\"",
                "widget_set scr_traffic_by_address data_wdgt{$i} 9 {$y} \"{$data}\"",
            ]);
        }
    }

    /* ---- scr_apcupsd ---- */
    if (screen_enabled('scr_apcupsd')) {
        $ups = get_apcupsd_stats();
        if ($ups !== null) {
            $name = str_replace('"', "'", $ups['upsname']);
            $status = str_replace('"', "'", $ups['status']);

            send_lcd_commands($lcd, [
                "widget_set scr_apcupsd apctitlel_wdgt 1 1 \"APC UPS:\"",
                "widget_set scr_apcupsd apcname_wdgt 10 1 {$cols} 1 h 2 \"{$name}\"",
                "widget_set scr_apcupsd apcstatus_wdgt 1 2 {$cols} 2 h 2 \"{$status}\"",
            ]);
            if ($rows >= 3) {
                send_lcd_commands($lcd, [
                    "widget_set scr_apcupsd apctitle_summary 1 3 \"LINE CHRG TIMEL LOAD\"",
                ]);
            }
            if ($rows >= 4) {
                $summary = sprintf("%4.0f %3.0f%% %5.1f %3.0f%%",
                    $ups['linev'], $ups['bcharge'], $ups['timeleft'], $ups['loadpct']);
                send_lcd_commands($lcd, [
                    "widget_set scr_apcupsd apc_summary 1 4 \"{$summary}\"",
                ]);
            }
        } else {
            send_lcd_commands($lcd, [
                "widget_set scr_apcupsd apctitlel_wdgt 1 1 \"APC UPS:\"",
                "widget_set scr_apcupsd apcname_wdgt 10 1 {$cols} 1 h 2 \"N/A\"",
                "widget_set scr_apcupsd apcstatus_wdgt 1 2 {$cols} 2 h 2 \"Not available\"",
            ]);
        }
    }

    /* ---- scr_nutups ---- */
    if (screen_enabled('scr_nutups')) {
        $ups = get_nutups_stats();
        if ($ups !== null) {
            $name = str_replace('"', "'", $ups['upsname']);
            $status = str_replace('"', "'", $ups['status']);

            send_lcd_commands($lcd, [
                "widget_set scr_nutups nuttitlel_wdgt 1 1 \"NUT UPS:\"",
                "widget_set scr_nutups nutname_wdgt 10 1 {$cols} 1 h 2 \"{$name}\"",
                "widget_set scr_nutups nutstatus_wdgt 1 2 {$cols} 2 h 2 \"{$status}\"",
            ]);
            if ($rows >= 3) {
                send_lcd_commands($lcd, [
                    "widget_set scr_nutups nuttitle_summary 1 3 \"LIN CHRG RUNTHMS LOD\"",
                ]);
            }
            if ($rows >= 4) {
                $summary = sprintf("%3.0f %3.0f%% %6.1f %2.0f%%",
                    $ups['input_voltage'], $ups['battery_charge'],
                    $ups['battery_runtime_mins'], $ups['ups_load']);
                send_lcd_commands($lcd, [
                    "widget_set scr_nutups nut_summary 1 4 \"{$summary}\"",
                ]);
            }
        } else {
            send_lcd_commands($lcd, [
                "widget_set scr_nutups nuttitlel_wdgt 1 1 \"NUT UPS:\"",
                "widget_set scr_nutups nutname_wdgt 10 1 {$cols} 1 h 2 \"N/A\"",
                "widget_set scr_nutups nutstatus_wdgt 1 2 {$cols} 2 h 2 \"Not available\"",
            ]);
        }
    }

    /* ---- CFontzPacket LED output ---- */
    if (($lcdproc_config['driver'] ?? '') === 'CFontzPacket' &&
        ($lcdproc_config['outputleds'] ?? '0') === '1') {
        $led_val = outputled_interface($lcd)
                 | outputled_carp()
                 | outputled_cpu($cpu)
                 | outputled_gateway();
        send_lcd_commands($lcd, ["output {$led_val}"]);
    }
}


/* ======================== MENU EVENT HANDLING ======================== */

/**
 * Handle LCDproc menu events from the server.
 * Called when the user activates a menu item on the LCD keypad.
 */
function handle_menu_event($event_line) {
    $event_line = trim($event_line);

    /* menuevent select <item_id> */
    if (preg_match('/^menuevent\s+select\s+(\S+)/', $event_line, $m)) {
        $item = $m[1];

        switch ($item) {
            case 'enter_maint':
                lcdproc_notice("Menu: Entering CARP maintenance mode");
                @shell_exec("sysctl net.inet.carp.allow=0 2>/dev/null");
                /* Set advskew on all CARP interfaces to demote */
                $output = @shell_exec("ifconfig -a 2>/dev/null");
                if (preg_match_all('/^(carp\d+):/m', $output, $matches)) {
                    foreach ($matches[1] as $carpif) {
                        @shell_exec("ifconfig " . escapeshellarg($carpif) . " advskew 240 2>/dev/null");
                    }
                }
                break;

            case 'leave_maint':
                lcdproc_notice("Menu: Leaving CARP maintenance mode");
                @shell_exec("sysctl net.inet.carp.allow=1 2>/dev/null");
                $output = @shell_exec("ifconfig -a 2>/dev/null");
                if (preg_match_all('/^(carp\d+):/m', $output, $matches)) {
                    foreach ($matches[1] as $carpif) {
                        @shell_exec("ifconfig " . escapeshellarg($carpif) . " advskew 0 2>/dev/null");
                    }
                }
                break;

            case 'restart_webgui_yes':
                lcdproc_notice("Menu: Restarting PHP+GUI");
                @shell_exec("configctl webgui restart 2>/dev/null &");
                break;

            case 'reboot_yes':
                lcdproc_notice("Menu: Rebooting system");
                @shell_exec("shutdown -r now 2>/dev/null &");
                break;

            case 'halt_yes':
                lcdproc_notice("Menu: Shutting down system");
                @shell_exec("shutdown -p now 2>/dev/null &");
                break;

            /* "No" selections - do nothing */
            case 'restart_webgui_no':
            case 'reboot_no':
            case 'halt_no':
                break;

            default:
                /* Unknown menu item - ignore */
                break;
        }
    }
}

/* ======================== LOOP STATUS ======================== */

/**
 * Main update loop.
 * Matches pfSense loop_status($lcd) structure.
 */
function loop_status($lcd) {
    global $refresh_frequency, $lcdpanel_width, $lcdpanel_height;
    global $lcdproc_config, $lcdproc_screen_config;

    $looped = 0;
    $config_reload_interval = 60; /* Reload config every 60 cycles (~5 min at 5s refresh) */

    lcdproc_notice("Entering main loop (refresh={$refresh_frequency}s)");

    while (true) {
        /* Update all screen data */
        output_status($lcd);

        /* Sleep for refresh interval */
        sleep($refresh_frequency);

        /* Check for incoming data from LCDd (menu events, etc.) */
        $read = [$lcd];
        $write = null;
        $except = null;
        if (@stream_select($read, $write, $except, 0) > 0) {
            $line = @fgets($lcd, 256);
            if ($line !== false) {
                $line = trim($line);
                if (strpos($line, 'menuevent') !== false) {
                    handle_menu_event($line);
                }
            }
        }

        /* Check if connection is still alive */
        if (@feof($lcd)) {
            lcdproc_warn("LCDd connection lost, attempting reconnect...");
            @fclose($lcd);

            /* Try to reconnect */
            $retries = 10;
            $lcd = false;
            while ($retries > 0) {
                $lcd = lcdproc_connect();
                if ($lcd !== false) {
                    break;
                }
                sleep(3);
                $retries--;
            }

            if ($lcd === false) {
                lcdproc_warn("Reconnect failed after multiple attempts. Exiting.");
                exit(1);
            }

            /* Rebuild screens after reconnect */
            build_interface($lcd);
            lcdproc_notice("Reconnected and screens rebuilt");
        }

        $looped++;

        /* Periodically reload configuration */
        if ($looped >= $config_reload_interval) {
            $looped = 0;

            /* Save current config for comparison */
            $old_screen_config = $lcdproc_screen_config;
            $old_config = $lcdproc_config;

            lcdproc_read_config();

            /* Check if config changed */
            if ($lcdproc_screen_config !== $old_screen_config ||
                $lcdproc_config !== $old_config) {
                lcdproc_notice("Configuration changed, rebuilding screens");

                /* Reconnect to reset all screens */
                @fclose($lcd);
                $lcd = lcdproc_connect();
                if ($lcd === false) {
                    lcdproc_warn("Reconnect failed after config change. Exiting.");
                    exit(1);
                }

                build_interface($lcd);
                lcdproc_notice("Screens rebuilt with new configuration");
            }
        }
    }
}

/* ======================== MAIN ENTRY POINT ======================== */

openlog("lcdproc_client", LOG_PID | LOG_PERROR, LOG_LOCAL0);
lcdproc_notice("LCDproc client starting...");

/* Read configuration */
lcdproc_read_config();

if (($lcdproc_config['enabled'] ?? '0') !== '1') {
    lcdproc_notice("LCDproc is not enabled in configuration. Exiting.");
    exit(0);
}

lcdproc_notice(sprintf("Display: %dx%d, Refresh: %ds, Driver: %s",
    $lcdpanel_width, $lcdpanel_height, $refresh_frequency,
    $lcdproc_config['driver'] ?? 'unknown'));

/* Wait for LCDd to be ready and connect */
$retries = 30;
$lcd = false;
while ($retries > 0) {
    $lcd = lcdproc_connect();
    if ($lcd !== false) {
        break;
    }
    lcdproc_notice("Waiting for LCDd... ({$retries} retries left)");
    sleep(2);
    $retries--;
}

if ($lcd === false) {
    lcdproc_warn("Could not connect to LCDd after all retries. Exiting.");
    exit(1);
}

lcdproc_notice("Connected to LCDd successfully");

/* Build all screens and widgets */
build_interface($lcd);

/* Enter main update loop */
loop_status($lcd);

/* Cleanup (unreachable in normal operation) */
if (is_resource($lcd)) {
    fclose($lcd);
}
closelog();
