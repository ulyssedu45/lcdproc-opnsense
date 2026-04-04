#!/usr/local/bin/php
<?php

/**
 *    LCDproc Client Daemon for OPNsense
 *    Ported from pfSense LCDproc package (lcdproc_client.php)
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

/* Autoloader for OPNsense MVC */
require_once("config.inc");
require_once("util.inc");
require_once("interfaces.inc");

/* Global constants */
define('LCDPROC_HOST', '127.0.0.1');
define('LCDPROC_PORT', 13666);
define('PRODUCT_NAME', 'OPNsense');

/* Read configuration from OPNsense config */
function lcdproc_get_config()
{
    $config = [];

    /* Read OPNsense XML config directly */
    $xmlConfig = simplexml_load_file('/conf/config.xml');
    if ($xmlConfig === false) {
        lcdproc_warn("Could not load config.xml");
        return $config;
    }

    /* Navigate to lcdproc config */
    if (isset($xmlConfig->OPNsense->lcdproc)) {
        $lcdConfig = $xmlConfig->OPNsense->lcdproc;

        /* General settings */
        if (isset($lcdConfig->general)) {
            $gen = $lcdConfig->general;
            $config['enabled'] = (string)($gen->enabled ?? '0');
            $config['driver'] = (string)($gen->driver ?? 'curses');
            $config['comport'] = (string)($gen->comport ?? 'none');
            $config['size'] = (string)($gen->size ?? 's20x4');
            $config['refresh_frequency'] = (string)($gen->refresh_frequency ?? 'r5');
            $config['outputleds'] = (string)($gen->outputleds ?? '0');
        }

        /* Screen settings */
        if (isset($lcdConfig->screens)) {
            $scr = $lcdConfig->screens;
            foreach ($scr->children() as $key => $value) {
                $config[$key] = (string)$value;
            }
        }
    }

    return $config;
}

/* Logging helpers */
function lcdproc_notice($msg)
{
    syslog(LOG_NOTICE, "lcdproc: {$msg}");
}

function lcdproc_warn($msg)
{
    syslog(LOG_WARNING, "lcdproc: {$msg}");
}

/* Parse display size from option key */
function parse_display_size($size_key)
{
    /* Strip any leading non-numeric prefix (e.g. 's' from 's20x4', '_' from '_20x4') */
    $size_key = preg_replace('/^[^0-9]+/', '', $size_key);
    $parts = explode('x', $size_key);
    if (count($parts) === 2 && (int)$parts[0] > 0 && (int)$parts[1] > 0) {
        return [(int)$parts[0], (int)$parts[1]];
    }
    return [20, 4]; /* default */
}

/* Parse refresh frequency from option key */
function parse_refresh($freq_key)
{
    /* Strip any leading non-numeric prefix (e.g. 'r' from 'r5', '_' from '_5') */
    $freq_key = preg_replace('/^[^0-9]+/', '', $freq_key);
    $val = (int)$freq_key;
    return $val > 0 ? $val : 5;
}

/* Connect to LCDd server */
function lcdproc_connect()
{
    $fp = @fsockopen(LCDPROC_HOST, LCDPROC_PORT, $errno, $errstr, 10);
    if (!$fp) {
        lcdproc_warn("Cannot connect to LCDd: {$errstr} ({$errno})");
        return false;
    }
    stream_set_timeout($fp, 5);

    /* Read hello message */
    $hello = fgets($fp, 4096);
    if (strpos($hello, 'connect') === false) {
        lcdproc_warn("Unexpected response from LCDd: {$hello}");
        fclose($fp);
        return false;
    }

    /* Set client name */
    fputs($fp, "client_set -name OPNsense\n");
    fgets($fp, 4096);

    return $fp;
}

/* Send commands to LCDd */
function lcdproc_send($fp, $commands)
{
    if (!is_array($commands)) {
        $commands = [$commands];
    }

    foreach ($commands as $cmd) {
        fputs($fp, $cmd . "\n");
        /* Read response but don't block */
        $response = @fgets($fp, 4096);
    }
}

/* Add a screen with basic setup */
function add_screen($fp, $name, $duration)
{
    $cmds = [];
    $cmds[] = "screen_add {$name}";
    $cmds[] = "screen_set {$name} heartbeat off";
    $cmds[] = "screen_set {$name} name {$name}";
    $cmds[] = "screen_set {$name} duration {$duration}";
    lcdproc_send($fp, $cmds);
}

/* Add a string widget */
function add_widget_string($fp, $screen, $widget, $x, $y, $text)
{
    $text = str_replace('"', "'", $text);
    lcdproc_send($fp, [
        "widget_add {$screen} {$widget} string",
        "widget_set {$screen} {$widget} {$x} {$y} \"{$text}\""
    ]);
}

/* Update a string widget */
function update_widget_string($fp, $screen, $widget, $x, $y, $text)
{
    $text = str_replace('"', "'", $text);
    lcdproc_send($fp, [
        "widget_set {$screen} {$widget} {$x} {$y} \"{$text}\""
    ]);
}

/* Add a scroller widget */
function add_widget_scroller($fp, $screen, $widget, $x1, $y1, $x2, $y2, $direction, $speed, $text)
{
    $text = str_replace('"', "'", $text);
    lcdproc_send($fp, [
        "widget_add {$screen} {$widget} scroller",
        "widget_set {$screen} {$widget} {$x1} {$y1} {$x2} {$y2} {$direction} {$speed} \"{$text}\""
    ]);
}

/* Update a scroller widget */
function update_widget_scroller($fp, $screen, $widget, $x1, $y1, $x2, $y2, $direction, $speed, $text)
{
    $text = str_replace('"', "'", $text);
    lcdproc_send($fp, [
        "widget_set {$screen} {$widget} {$x1} {$y1} {$x2} {$y2} {$direction} {$speed} \"{$text}\""
    ]);
}

/* ======================== SYSTEM DATA FUNCTIONS ======================== */

/* Get CPU usage percentage */
function lcdproc_cpu_usage()
{
    static $prev_idle = 0;
    static $prev_total = 0;

    $raw = shell_exec("sysctl -n kern.cp_time");
    if ($raw === null) {
        return 0;
    }
    $parts = preg_split('/\s+/', trim($raw));
    if (count($parts) < 5) {
        return 0;
    }

    $idle = (int)$parts[4];
    $total = array_sum(array_map('intval', $parts));

    if ($prev_total === 0) {
        $prev_idle = $idle;
        $prev_total = $total;
        usleep(250000); /* 250ms sample */
        return lcdproc_cpu_usage();
    }

    $diff_idle = $idle - $prev_idle;
    $diff_total = $total - $prev_total;
    $prev_idle = $idle;
    $prev_total = $total;

    if ($diff_total === 0) {
        return 0;
    }

    return round((1.0 - ($diff_idle / $diff_total)) * 100, 1);
}

/* Get system uptime */
function get_uptime_stats()
{
    $boottime = shell_exec("sysctl -n kern.boottime");
    if (preg_match('/sec = (\d+)/', $boottime, $matches)) {
        $boot_ts = (int)$matches[1];
        $uptime_secs = time() - $boot_ts;
        $days = floor($uptime_secs / 86400);
        $hours = floor(($uptime_secs % 86400) / 3600);
        $mins = floor(($uptime_secs % 3600) / 60);
        return sprintf("%dd %dh %dm", $days, $hours, $mins);
    }
    return "Unknown";
}

/* Get load averages */
function get_loadavg_stats()
{
    $loadavg = shell_exec("sysctl -n vm.loadavg");
    if (preg_match('/\{\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\}/', $loadavg, $matches)) {
        return sprintf("%.2f %.2f %.2f", $matches[1], $matches[2], $matches[3]);
    }
    return "0.00 0.00 0.00";
}

/* Get memory usage */
function get_memory_stats()
{
    $total_pages = (int)trim(shell_exec("sysctl -n vm.stats.vm.v_page_count") ?? '0');
    $free_pages = (int)trim(shell_exec("sysctl -n vm.stats.vm.v_free_count") ?? '0');
    $page_size = (int)trim(shell_exec("sysctl -n hw.pagesize") ?? '4096');

    $total = ($total_pages * $page_size) / 1024 / 1024; /* MB */
    $free = ($free_pages * $page_size) / 1024 / 1024;
    $used = $total - $free;

    if ($total > 0) {
        $pct = round(($used / $total) * 100);
    } else {
        $pct = 0;
    }

    return sprintf("%.0fMB/%.0fMB (%d%%)", $used, $total, $pct);
}

/* Get disk usage */
function get_disk_stats()
{
    $output = shell_exec("df -h / 2>/dev/null | tail -1");
    if (preg_match('/\S+\s+(\S+)\s+(\S+)\s+(\S+)\s+(\d+)%/', $output, $m)) {
        return sprintf("Used: %s/%s (%s%%)", $m[2], $m[1], $m[4]);
    }
    return "N/A";
}

/* Get state table usage */
function get_states_stats()
{
    $current = trim(shell_exec("pfctl -si 2>/dev/null | grep -i 'current entries' | awk '{print \$3}'") ?? '0');
    $limit = trim(shell_exec("sysctl -n net.pf.states_limit 2>/dev/null") ?? '0');

    if ((int)$limit > 0) {
        $pct = round(((int)$current / (int)$limit) * 100);
    } else {
        $pct = 0;
    }

    return sprintf("%s/%s (%d%%)", number_format((int)$current), number_format((int)$limit), $pct);
}

/* Get mbuf usage */
function get_mbuf_stats()
{
    $output = shell_exec("netstat -m 2>/dev/null | head -1");
    if (preg_match('/([\d\/]+)\s+mbufs/', $output, $m)) {
        return "mbufs: " . $m[1];
    }
    return "mbufs: N/A";
}

/* Get CPU temperature */
function get_cpu_temperature($unit = 'c')
{
    $temp_raw = trim(shell_exec("sysctl -n dev.cpu.0.temperature 2>/dev/null") ?? '');
    if (preg_match('/([\d.]+)/', $temp_raw, $m)) {
        $temp_c = (float)$m[1];
        if ($unit === 'f') {
            $temp = ($temp_c * 9 / 5) + 32;
            return sprintf("%.1f°F", $temp);
        }
        return sprintf("%.1f°C", $temp_c);
    }
    return "N/A";
}

/* Get CPU frequency */
function get_cpu_frequency()
{
    $current = trim(shell_exec("sysctl -n dev.cpu.0.freq 2>/dev/null") ?? '');
    $max = trim(shell_exec("sysctl -n dev.cpu.0.freq_levels 2>/dev/null") ?? '');

    $max_freq = '';
    if (preg_match('/^(\d+)\//', $max, $m)) {
        $max_freq = $m[1];
    }

    if ($current && $max_freq) {
        return sprintf("%sMHz / %sMHz", $current, $max_freq);
    } elseif ($current) {
        return sprintf("%sMHz", $current);
    }
    return "N/A";
}

/* Get interface statistics */
function get_interfaces_stats()
{
    $interfaces = [];
    $config_xml = simplexml_load_file('/conf/config.xml');
    if ($config_xml === false) {
        return $interfaces;
    }

    if (isset($config_xml->interfaces)) {
        foreach ($config_xml->interfaces->children() as $ifname => $ifcfg) {
            if ((string)($ifcfg->enable ?? '') !== '1') {
                continue;
            }
            $realif = (string)($ifcfg->if ?? '');
            if (empty($realif)) {
                continue;
            }

            $descr = (string)($ifcfg->descr ?? strtoupper($ifname));
            $ipaddr = trim(shell_exec("ifconfig {$realif} 2>/dev/null | grep 'inet ' | head -1 | awk '{print \$2}'") ?? '');
            $status = trim(shell_exec("ifconfig {$realif} 2>/dev/null | grep 'status:' | awk '{print \$2}'") ?? '');

            $interfaces[] = [
                'name' => $ifname,
                'descr' => $descr,
                'if' => $realif,
                'ipaddr' => $ipaddr ?: 'N/A',
                'status' => $status ?: 'unknown'
            ];
        }
    }

    return $interfaces;
}

/* Get interface traffic stats */
function get_traffic_stats($realif)
{
    $output = shell_exec("netstat -I {$realif} -b 2>/dev/null | tail -1");
    if (preg_match('/\S+\s+\S+\s+\S+\s+(\d+)\s+\S+\s+(\d+)\s+\S+\s+(\d+)\s+\S+\s+(\d+)/', $output, $m)) {
        return [
            'in_bytes' => (int)$m[1],
            'in_pkts' => 0,
            'out_bytes' => (int)$m[3],
            'out_pkts' => 0
        ];
    }
    return ['in_bytes' => 0, 'in_pkts' => 0, 'out_bytes' => 0, 'out_pkts' => 0];
}

/* Format bytes to human readable */
function format_bytes($bytes)
{
    if ($bytes >= 1073741824) {
        return sprintf("%.1fG", $bytes / 1073741824);
    } elseif ($bytes >= 1048576) {
        return sprintf("%.1fM", $bytes / 1048576);
    } elseif ($bytes >= 1024) {
        return sprintf("%.1fK", $bytes / 1024);
    }
    return sprintf("%dB", $bytes);
}

/* Get CARP status */
function get_carp_stats()
{
    $output = shell_exec("ifconfig | grep 'carp:' 2>/dev/null");
    if (empty($output)) {
        return "No CARP configured";
    }

    $master = 0;
    $backup = 0;
    $init = 0;
    $lines = explode("\n", trim($output));
    foreach ($lines as $line) {
        if (stripos($line, 'MASTER') !== false) {
            $master++;
        } elseif (stripos($line, 'BACKUP') !== false) {
            $backup++;
        } elseif (stripos($line, 'INIT') !== false) {
            $init++;
        }
    }

    $status = [];
    if ($master > 0) {
        $status[] = "M:{$master}";
    }
    if ($backup > 0) {
        $status[] = "B:{$backup}";
    }
    if ($init > 0) {
        $status[] = "I:{$init}";
    }

    return implode(' ', $status) ?: "CARP: None";
}

/* Get CARP status for LED output */
function get_carp_led_status()
{
    $output = shell_exec("ifconfig | grep 'carp:' 2>/dev/null");
    if (empty($output)) {
        return 'none';
    }

    $has_master = stripos($output, 'MASTER') !== false;
    $has_backup = stripos($output, 'BACKUP') !== false;

    if ($has_master && !$has_backup) {
        return 'master';
    } elseif (!$has_master && $has_backup) {
        return 'backup';
    } elseif ($has_master && $has_backup) {
        return 'split';
    }
    return 'init';
}

/* Get gateway status */
function get_gateway_status()
{
    $gateways = [];

    /* Try to use dpinger status files */
    $dpinger_files = glob("/var/run/dpinger_*.sock");
    if (!empty($dpinger_files)) {
        foreach ($dpinger_files as $sock) {
            $name = basename($sock, '.sock');
            $name = str_replace('dpinger_', '', $name);

            $status_file = "/var/run/dpinger_{$name}.status";
            if (file_exists($status_file)) {
                $data = trim(file_get_contents($status_file));
                /* Format: delay_avg loss_avg rtt_avg */
                $parts = explode(' ', $data);
                $delay = isset($parts[0]) ? round((int)$parts[0] / 1000, 1) : 0;
                $loss = isset($parts[2]) ? (int)$parts[2] : 0;

                $status = 'online';
                if ($loss >= 100) {
                    $status = 'down';
                } elseif ($loss > 0) {
                    $status = 'packetloss';
                }

                $gateways[] = [
                    'name' => $name,
                    'status' => $status,
                    'delay' => sprintf("%.1fms", $delay),
                    'loss' => sprintf("%d%%", $loss)
                ];
            }
        }
    }

    /* Fallback: check routing table */
    if (empty($gateways)) {
        $routes = shell_exec("netstat -rn -f inet 2>/dev/null | grep '^default'");
        if (!empty($routes)) {
            foreach (explode("\n", trim($routes)) as $route) {
                if (preg_match('/default\s+(\S+)\s+\S+\s+(\S+)/', $route, $m)) {
                    $gateways[] = [
                        'name' => $m[2],
                        'status' => 'online',
                        'delay' => 'N/A',
                        'loss' => '0%'
                    ];
                }
            }
        }
    }

    return $gateways;
}

/* Get IPsec tunnel status */
function get_ipsec_stats()
{
    $output = shell_exec("ipsec statusall 2>/dev/null");
    if (empty($output)) {
        return "IPsec: N/A";
    }

    $established = 0;
    $connecting = 0;
    foreach (explode("\n", $output) as $line) {
        if (preg_match('/ESTABLISHED/', $line)) {
            $established++;
        } elseif (preg_match('/CONNECTING/', $line)) {
            $connecting++;
        }
    }

    return sprintf("Up:%d Conn:%d", $established, $connecting);
}

/* Get NTP status */
function get_ntp_status()
{
    $output = shell_exec("ntpq -pn 2>/dev/null | grep '^\*'");
    if (!empty($output)) {
        if (preg_match('/^\*(\S+)\s+/', trim($output), $m)) {
            return "Sync: " . $m[1];
        }
        return "Synced";
    }

    $output = shell_exec("ntpq -pn 2>/dev/null | grep '^\+'");
    if (!empty($output)) {
        return "Candidate";
    }

    return "Not synced";
}

/* Get package information */
function get_packages_stats()
{
    $total = trim(shell_exec("pkg info 2>/dev/null | wc -l") ?? '0');
    $updates = trim(shell_exec("pkg version -vRL= 2>/dev/null | wc -l") ?? '0');
    return sprintf("Pkgs:%s Upd:%s", trim($total), trim($updates));
}

/* Get APC UPS status */
function get_apcupsd_stats()
{
    $output = shell_exec("apcaccess 2>/dev/null");
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

    return [
        'upsname' => $stats['UPSNAME'] ?? 'UPS',
        'status' => $stats['STATUS'] ?? 'N/A',
        'linev' => $stats['LINEV'] ?? 'N/A',
        'bcharge' => $stats['BCHARGE'] ?? 'N/A',
        'timeleft' => $stats['TIMELEFT'] ?? 'N/A',
        'loadpct' => $stats['LOADPCT'] ?? 'N/A'
    ];
}

/* Get NUT UPS status */
function get_nutups_stats()
{
    $output = shell_exec("upsc ups@localhost 2>/dev/null");
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

    return [
        'upsname' => $stats['ups.mfr'] ?? 'UPS',
        'status' => $stats['ups.status'] ?? 'N/A',
        'input_voltage' => $stats['input.voltage'] ?? 'N/A',
        'battery_charge' => $stats['battery.charge'] ?? 'N/A',
        'battery_runtime' => $stats['battery.runtime'] ?? 'N/A',
        'ups_load' => $stats['ups.load'] ?? 'N/A'
    ];
}

/* CFontzPacket LED output for CARP status */
function outputled_carp($fp, $config)
{
    if (($config['driver'] ?? '') !== 'CFontzPacket' || ($config['outputleds'] ?? '0') !== '1') {
        return;
    }

    $carp_status = get_carp_led_status();
    switch ($carp_status) {
        case 'master':
            lcdproc_send($fp, ["output 4"]);  /* Green LED */
            break;
        case 'backup':
            lcdproc_send($fp, ["output 32"]); /* Yellow LED */
            break;
        case 'split':
            lcdproc_send($fp, ["output 36"]); /* Both LEDs */
            break;
        default:
            lcdproc_send($fp, ["output 128"]); /* Red LED */
            break;
    }
}

/* CFontzPacket LED output for gateway status */
function outputled_gateway($fp, $config)
{
    if (($config['driver'] ?? '') !== 'CFontzPacket' || ($config['outputleds'] ?? '0') !== '1') {
        return;
    }

    $gateways = get_gateway_status();
    $all_up = true;
    foreach ($gateways as $gw) {
        if ($gw['status'] !== 'online') {
            $all_up = false;
            break;
        }
    }

    if ($all_up) {
        lcdproc_send($fp, ["output 2"]); /* Green LED */
    } else {
        lcdproc_send($fp, ["output 64"]); /* Red LED */
    }
}

/* ======================== SCREEN BUILD FUNCTIONS ======================== */

function build_screens($fp, $config, $cols, $rows, $refresh)
{
    $duration = $refresh * 8; /* Each screen display time in 1/8 seconds */

    /* Screen: Version */
    if (($config['scr_version'] ?? '0') === '1') {
        $version = trim(shell_exec("opnsense-version -v 2>/dev/null") ?? PRODUCT_NAME);
        add_screen($fp, 'scr_version', $duration);
        add_widget_string($fp, 'scr_version', 'title', 1, 1, str_pad(PRODUCT_NAME, $cols));
        if ($rows >= 2) {
            add_widget_scroller($fp, 'scr_version', 'version', 1, 2, $cols, 2, 'h', 3, "Version: {$version}");
        }
        if ($rows >= 3) {
            $arch = trim(shell_exec("uname -m 2>/dev/null") ?? '');
            add_widget_string($fp, 'scr_version', 'arch', 1, 3, str_pad("Arch: {$arch}", $cols));
        }
        if ($rows >= 4) {
            $kernel = trim(shell_exec("uname -r 2>/dev/null") ?? '');
            add_widget_scroller($fp, 'scr_version', 'kernel', 1, 4, $cols, 4, 'h', 3, "Kernel: {$kernel}");
        }
    }

    /* Screen: Time */
    if (($config['scr_time'] ?? '0') === '1') {
        add_screen($fp, 'scr_time', $duration);
        add_widget_string($fp, 'scr_time', 'title', 1, 1, str_pad("System Time", $cols));
        add_widget_string($fp, 'scr_time', 'time', 1, 2, str_pad(date('H:i:s'), $cols));
        if ($rows >= 3) {
            add_widget_string($fp, 'scr_time', 'date', 1, 3, str_pad(date('Y-m-d'), $cols));
        }
        if ($rows >= 4) {
            add_widget_string($fp, 'scr_time', 'tz', 1, 4, str_pad(date('T'), $cols));
        }
    }

    /* Screen: Uptime */
    if (($config['scr_uptime'] ?? '0') === '1') {
        add_screen($fp, 'scr_uptime', $duration);
        add_widget_string($fp, 'scr_uptime', 'title', 1, 1, str_pad("Uptime", $cols));
        add_widget_string($fp, 'scr_uptime', 'uptime', 1, 2, str_pad(get_uptime_stats(), $cols));
    }

    /* Screen: Hostname */
    if (($config['scr_hostname'] ?? '0') === '1') {
        add_screen($fp, 'scr_hostname', $duration);
        $hostname = gethostname() ?: 'unknown';
        add_widget_string($fp, 'scr_hostname', 'title', 1, 1, str_pad("Hostname", $cols));
        add_widget_scroller($fp, 'scr_hostname', 'name', 1, 2, $cols, 2, 'h', 3, $hostname);
    }

    /* Screen: System */
    if (($config['scr_system'] ?? '0') === '1') {
        add_screen($fp, 'scr_system', $duration);
        add_widget_string($fp, 'scr_system', 'title', 1, 1, str_pad("System", $cols));
        add_widget_string($fp, 'scr_system', 'cpu', 1, 2, str_pad("CPU: " . lcdproc_cpu_usage() . "%", $cols));
        if ($rows >= 3) {
            add_widget_scroller($fp, 'scr_system', 'mem', 1, 3, $cols, 3, 'h', 3, "Mem: " . get_memory_stats());
        }
        if ($rows >= 4) {
            add_widget_string($fp, 'scr_system', 'load', 1, 4, str_pad("Load: " . get_loadavg_stats(), $cols));
        }
    }

    /* Screen: Disk */
    if (($config['scr_disk'] ?? '0') === '1') {
        add_screen($fp, 'scr_disk', $duration);
        add_widget_string($fp, 'scr_disk', 'title', 1, 1, str_pad("Disk Usage", $cols));
        add_widget_scroller($fp, 'scr_disk', 'usage', 1, 2, $cols, 2, 'h', 3, get_disk_stats());
    }

    /* Screen: Load */
    if (($config['scr_load'] ?? '0') === '1') {
        add_screen($fp, 'scr_load', $duration);
        add_widget_string($fp, 'scr_load', 'title', 1, 1, str_pad("Load Averages", $cols));
        add_widget_string($fp, 'scr_load', 'load', 1, 2, str_pad(get_loadavg_stats(), $cols));
    }

    /* Screen: States */
    if (($config['scr_states'] ?? '0') === '1') {
        add_screen($fp, 'scr_states', $duration);
        add_widget_string($fp, 'scr_states', 'title', 1, 1, str_pad("State Table", $cols));
        add_widget_scroller($fp, 'scr_states', 'states', 1, 2, $cols, 2, 'h', 3, get_states_stats());
    }

    /* Screen: CARP */
    if (($config['scr_carp'] ?? '0') === '1') {
        add_screen($fp, 'scr_carp', $duration);
        add_widget_string($fp, 'scr_carp', 'title', 1, 1, str_pad("CARP Status", $cols));
        add_widget_scroller($fp, 'scr_carp', 'status', 1, 2, $cols, 2, 'h', 3, get_carp_stats());
    }

    /* Screen: IPsec */
    if (($config['scr_ipsec'] ?? '0') === '1') {
        add_screen($fp, 'scr_ipsec', $duration);
        add_widget_string($fp, 'scr_ipsec', 'title', 1, 1, str_pad("IPsec", $cols));
        add_widget_scroller($fp, 'scr_ipsec', 'status', 1, 2, $cols, 2, 'h', 3, get_ipsec_stats());
    }

    /* Screen: Interfaces summary */
    if (($config['scr_interfaces'] ?? '0') === '1') {
        add_screen($fp, 'scr_interfaces', $duration);
        add_widget_string($fp, 'scr_interfaces', 'title', 1, 1, str_pad("Interfaces", $cols));
        $interfaces = get_interfaces_stats();
        $row = 2;
        foreach ($interfaces as $iface) {
            if ($row > $rows) {
                break;
            }
            $text = sprintf("%s:%s", substr($iface['descr'], 0, 6), $iface['ipaddr']);
            add_widget_scroller($fp, 'scr_interfaces', "if_{$row}", 1, $row, $cols, $row, 'h', 3, $text);
            $row++;
        }
    }

    /* Screen: Interface Link Status (detailed per-interface) */
    if (($config['scr_interfaces_link'] ?? '0') === '1') {
        $interfaces = get_interfaces_stats();
        $scr_num = 0;
        foreach ($interfaces as $iface) {
            $scr_num++;
            $scr_name = "scr_iflink_{$scr_num}";
            add_screen($fp, $scr_name, $duration);
            add_widget_string($fp, $scr_name, 'title', 1, 1, str_pad($iface['descr'] . " (" . $iface['if'] . ")", $cols));
            add_widget_string($fp, $scr_name, 'ip', 1, 2, str_pad("IP:" . $iface['ipaddr'], $cols));
            if ($rows >= 3) {
                $mac = trim(shell_exec("ifconfig {$iface['if']} 2>/dev/null | grep ether | awk '{print \$2}'") ?? '');
                add_widget_scroller($fp, $scr_name, 'mac', 1, 3, $cols, 3, 'h', 3, "MAC:" . ($mac ?: 'N/A'));
            }
            if ($rows >= 4) {
                add_widget_string($fp, $scr_name, 'status', 1, 4, str_pad("Link:" . $iface['status'], $cols));
            }
        }
    }

    /* Screen: Gateway Summary */
    if (($config['scr_gwsum'] ?? '0') === '1') {
        add_screen($fp, 'scr_gwsum', $duration);
        add_widget_string($fp, 'scr_gwsum', 'title', 1, 1, str_pad("Gateway Summary", $cols));
        $gateways = get_gateway_status();
        $up = 0;
        $down = 0;
        foreach ($gateways as $gw) {
            if ($gw['status'] === 'online') {
                $up++;
            } else {
                $down++;
            }
        }
        $text = sprintf("Up:%d Down:%d Total:%d", $up, $down, count($gateways));
        add_widget_scroller($fp, 'scr_gwsum', 'summary', 1, 2, $cols, 2, 'h', 3, $text);
    }

    /* Screen: Gateway Status (per-gateway sub-screens) */
    if (($config['scr_gwstatus'] ?? '0') === '1') {
        $gateways = get_gateway_status();
        $gw_num = 0;
        foreach ($gateways as $gw) {
            $gw_num++;
            $scr_name = "scr_gw_{$gw_num}";
            add_screen($fp, $scr_name, $duration);
            add_widget_string($fp, $scr_name, 'title', 1, 1, str_pad("GW:" . $gw['name'], $cols));
            add_widget_string($fp, $scr_name, 'status', 1, 2, str_pad("Status:" . $gw['status'], $cols));
            if ($rows >= 3) {
                add_widget_string($fp, $scr_name, 'delay', 1, 3, str_pad("Delay:" . $gw['delay'], $cols));
            }
            if ($rows >= 4) {
                add_widget_string($fp, $scr_name, 'loss', 1, 4, str_pad("Loss:" . $gw['loss'], $cols));
            }
        }
    }

    /* Screen: Memory Buffers */
    if (($config['scr_mbuf'] ?? '0') === '1') {
        add_screen($fp, 'scr_mbuf', $duration);
        add_widget_string($fp, 'scr_mbuf', 'title', 1, 1, str_pad("Memory Buffers", $cols));
        add_widget_scroller($fp, 'scr_mbuf', 'mbuf', 1, 2, $cols, 2, 'h', 3, get_mbuf_stats());
    }

    /* Screen: Packages */
    if (($config['scr_packages'] ?? '0') === '1') {
        add_screen($fp, 'scr_packages', $duration);
        add_widget_string($fp, 'scr_packages', 'title', 1, 1, str_pad("Packages", $cols));
        add_widget_scroller($fp, 'scr_packages', 'pkgs', 1, 2, $cols, 2, 'h', 3, get_packages_stats());
    }

    /* Screen: CPU Frequency */
    if (($config['scr_cpufrequency'] ?? '0') === '1') {
        add_screen($fp, 'scr_cpufrequency', $duration);
        add_widget_string($fp, 'scr_cpufrequency', 'title', 1, 1, str_pad("CPU Frequency", $cols));
        add_widget_scroller($fp, 'scr_cpufrequency', 'freq', 1, 2, $cols, 2, 'h', 3, get_cpu_frequency());
    }

    /* Screen: CPU Temperature */
    if (($config['scr_cputemperature'] ?? '0') === '1') {
        $unit = $config['scr_cputemperature_unit'] ?? 'c';
        add_screen($fp, 'scr_cputemperature', $duration);
        add_widget_string($fp, 'scr_cputemperature', 'title', 1, 1, str_pad("CPU Temperature", $cols));
        add_widget_string($fp, 'scr_cputemperature', 'temp', 1, 2, str_pad(get_cpu_temperature($unit), $cols));
    }

    /* Screen: NTP */
    if (($config['scr_ntp'] ?? '0') === '1') {
        add_screen($fp, 'scr_ntp', $duration);
        add_widget_string($fp, 'scr_ntp', 'title', 1, 1, str_pad("NTP Status", $cols));
        add_widget_scroller($fp, 'scr_ntp', 'status', 1, 2, $cols, 2, 'h', 3, get_ntp_status());
    }

    /* Screen: Traffic */
    if (($config['scr_traffic'] ?? '0') === '1') {
        $traffic_if = $config['scr_traffic_interface'] ?? '';
        if (!empty($traffic_if)) {
            /* Resolve interface name to physical device */
            $realif = resolve_interface($traffic_if);
            if (!empty($realif)) {
                add_screen($fp, 'scr_traffic', $duration);
                add_widget_string($fp, 'scr_traffic', 'title', 1, 1, str_pad("Traffic: {$traffic_if}", $cols));
                $stats = get_traffic_stats($realif);
                add_widget_string($fp, 'scr_traffic', 'in', 1, 2, str_pad("In: " . format_bytes($stats['in_bytes']), $cols));
                if ($rows >= 3) {
                    add_widget_string($fp, 'scr_traffic', 'out', 1, 3, str_pad("Out: " . format_bytes($stats['out_bytes']), $cols));
                }
            }
        }
    }

    /* Screen: Top interfaces by bps */
    if (($config['scr_top_interfaces_by_bps'] ?? '0') === '1') {
        add_screen($fp, 'scr_top_bps', $duration);
        add_widget_string($fp, 'scr_top_bps', 'title', 1, 1, str_pad("Top IF by bps", $cols));
        /* Show top interfaces - will be updated in the loop */
        add_widget_string($fp, 'scr_top_bps', 'line1', 1, 2, str_pad("Loading...", $cols));
    }

    /* Screen: Top interfaces by total bytes */
    if (($config['scr_top_interfaces_by_total_bytes'] ?? '0') === '1') {
        add_screen($fp, 'scr_top_total', $duration);
        add_widget_string($fp, 'scr_top_total', 'title', 1, 1, str_pad("Top IF Total", $cols));
        add_widget_string($fp, 'scr_top_total', 'line1', 1, 2, str_pad("Loading...", $cols));
    }

    /* Screen: Top interfaces by bytes today */
    if (($config['scr_top_interfaces_by_bytes_today'] ?? '0') === '1') {
        add_screen($fp, 'scr_top_today', $duration);
        add_widget_string($fp, 'scr_top_today', 'title', 1, 1, str_pad("Top IF Today", $cols));
        add_widget_string($fp, 'scr_top_today', 'line1', 1, 2, str_pad("Loading...", $cols));
    }

    /* Screen: Traffic by address */
    if (($config['scr_traffic_by_address'] ?? '0') === '1') {
        add_screen($fp, 'scr_tba', $duration);
        add_widget_string($fp, 'scr_tba', 'title', 1, 1, str_pad("Traffic/Address", $cols));
        add_widget_string($fp, 'scr_tba', 'line1', 1, 2, str_pad("Loading...", $cols));
    }

    /* Screen: APC UPS */
    if (($config['scr_apcupsd'] ?? '0') === '1') {
        $ups = get_apcupsd_stats();
        if ($ups !== null) {
            add_screen($fp, 'scr_apcupsd', $duration);
            add_widget_string($fp, 'scr_apcupsd', 'title', 1, 1, str_pad("UPS:" . $ups['upsname'], $cols));
            add_widget_string($fp, 'scr_apcupsd', 'status', 1, 2, str_pad($ups['status'], $cols));
            if ($rows >= 3) {
                add_widget_scroller($fp, 'scr_apcupsd', 'detail1', 1, 3, $cols, 3, 'h', 3,
                    sprintf("Batt:%s Line:%s", $ups['bcharge'], $ups['linev']));
            }
            if ($rows >= 4) {
                add_widget_scroller($fp, 'scr_apcupsd', 'detail2', 1, 4, $cols, 4, 'h', 3,
                    sprintf("Time:%s Load:%s", $ups['timeleft'], $ups['loadpct']));
            }
        }
    }

    /* Screen: NUT UPS */
    if (($config['scr_nutups'] ?? '0') === '1') {
        $ups = get_nutups_stats();
        if ($ups !== null) {
            add_screen($fp, 'scr_nutups', $duration);
            add_widget_string($fp, 'scr_nutups', 'title', 1, 1, str_pad("UPS:" . $ups['upsname'], $cols));
            add_widget_string($fp, 'scr_nutups', 'status', 1, 2, str_pad($ups['status'], $cols));
            if ($rows >= 3) {
                add_widget_scroller($fp, 'scr_nutups', 'detail1', 1, 3, $cols, 3, 'h', 3,
                    sprintf("Batt:%s%% In:%sV", $ups['battery_charge'], $ups['input_voltage']));
            }
            if ($rows >= 4) {
                add_widget_scroller($fp, 'scr_nutups', 'detail2', 1, 4, $cols, 4, 'h', 3,
                    sprintf("RT:%ss Load:%s%%", $ups['battery_runtime'], $ups['ups_load']));
            }
        }
    }
}

/* Resolve OPNsense interface name to physical device */
function resolve_interface($if_name)
{
    $config_xml = simplexml_load_file('/conf/config.xml');
    if ($config_xml === false) {
        return '';
    }

    if (isset($config_xml->interfaces->{$if_name})) {
        return (string)($config_xml->interfaces->{$if_name}->if ?? '');
    }

    /* Try direct match */
    return $if_name;
}

/* ======================== UPDATE FUNCTIONS ======================== */

function update_dynamic_screens($fp, $config, $cols, $rows)
{
    /* Update time */
    if (($config['scr_time'] ?? '0') === '1') {
        update_widget_string($fp, 'scr_time', 'time', 1, 2, str_pad(date('H:i:s'), $cols));
        if ($rows >= 3) {
            update_widget_string($fp, 'scr_time', 'date', 1, 3, str_pad(date('Y-m-d'), $cols));
        }
    }

    /* Update uptime */
    if (($config['scr_uptime'] ?? '0') === '1') {
        update_widget_string($fp, 'scr_uptime', 'uptime', 1, 2, str_pad(get_uptime_stats(), $cols));
    }

    /* Update system stats */
    if (($config['scr_system'] ?? '0') === '1') {
        update_widget_string($fp, 'scr_system', 'cpu', 1, 2, str_pad("CPU: " . lcdproc_cpu_usage() . "%", $cols));
        if ($rows >= 3) {
            update_widget_scroller($fp, 'scr_system', 'mem', 1, 3, $cols, 3, 'h', 3, "Mem: " . get_memory_stats());
        }
        if ($rows >= 4) {
            update_widget_string($fp, 'scr_system', 'load', 1, 4, str_pad("Load: " . get_loadavg_stats(), $cols));
        }
    }

    /* Update states */
    if (($config['scr_states'] ?? '0') === '1') {
        update_widget_scroller($fp, 'scr_states', 'states', 1, 2, $cols, 2, 'h', 3, get_states_stats());
    }

    /* Update CARP */
    if (($config['scr_carp'] ?? '0') === '1') {
        update_widget_scroller($fp, 'scr_carp', 'status', 1, 2, $cols, 2, 'h', 3, get_carp_stats());
        outputled_carp($fp, $config);
    }

    /* Update IPsec */
    if (($config['scr_ipsec'] ?? '0') === '1') {
        update_widget_scroller($fp, 'scr_ipsec', 'status', 1, 2, $cols, 2, 'h', 3, get_ipsec_stats());
    }

    /* Update CPU temperature */
    if (($config['scr_cputemperature'] ?? '0') === '1') {
        $unit = $config['scr_cputemperature_unit'] ?? 'c';
        update_widget_string($fp, 'scr_cputemperature', 'temp', 1, 2, str_pad(get_cpu_temperature($unit), $cols));
    }

    /* Update CPU frequency */
    if (($config['scr_cpufrequency'] ?? '0') === '1') {
        update_widget_scroller($fp, 'scr_cpufrequency', 'freq', 1, 2, $cols, 2, 'h', 3, get_cpu_frequency());
    }

    /* Update mbuf */
    if (($config['scr_mbuf'] ?? '0') === '1') {
        update_widget_scroller($fp, 'scr_mbuf', 'mbuf', 1, 2, $cols, 2, 'h', 3, get_mbuf_stats());
    }

    /* Update NTP */
    if (($config['scr_ntp'] ?? '0') === '1') {
        update_widget_scroller($fp, 'scr_ntp', 'status', 1, 2, $cols, 2, 'h', 3, get_ntp_status());
    }

    /* Update traffic */
    if (($config['scr_traffic'] ?? '0') === '1') {
        $traffic_if = $config['scr_traffic_interface'] ?? '';
        if (!empty($traffic_if)) {
            $realif = resolve_interface($traffic_if);
            if (!empty($realif)) {
                $stats = get_traffic_stats($realif);
                update_widget_string($fp, 'scr_traffic', 'in', 1, 2, str_pad("In: " . format_bytes($stats['in_bytes']), $cols));
                if ($rows >= 3) {
                    update_widget_string($fp, 'scr_traffic', 'out', 1, 3, str_pad("Out: " . format_bytes($stats['out_bytes']), $cols));
                }
            }
        }
    }

    /* Update top interfaces by bps */
    if (($config['scr_top_interfaces_by_bps'] ?? '0') === '1') {
        $interfaces = get_interfaces_stats();
        $iface_traffic = [];
        foreach ($interfaces as $iface) {
            $stats = get_traffic_stats($iface['if']);
            $iface_traffic[] = [
                'name' => $iface['descr'],
                'bps' => $stats['in_bytes'] + $stats['out_bytes']
            ];
        }
        usort($iface_traffic, function ($a, $b) {
            return $b['bps'] - $a['bps'];
        });
        $row = 2;
        foreach (array_slice($iface_traffic, 0, $rows - 1) as $i => $entry) {
            $text = sprintf("%s:%s", substr($entry['name'], 0, 8), format_bytes($entry['bps']));
            update_widget_string($fp, 'scr_top_bps', "line" . ($i + 1), 1, $row, str_pad($text, $cols));
            $row++;
        }
    }

    /* Update gateway status sub-screens */
    if (($config['scr_gwstatus'] ?? '0') === '1') {
        $gateways = get_gateway_status();
        $gw_num = 0;
        foreach ($gateways as $gw) {
            $gw_num++;
            $scr_name = "scr_gw_{$gw_num}";
            update_widget_string($fp, $scr_name, 'status', 1, 2, str_pad("Status:" . $gw['status'], $cols));
            if ($rows >= 3) {
                update_widget_string($fp, $scr_name, 'delay', 1, 3, str_pad("Delay:" . $gw['delay'], $cols));
            }
            if ($rows >= 4) {
                update_widget_string($fp, $scr_name, 'loss', 1, 4, str_pad("Loss:" . $gw['loss'], $cols));
            }
        }
        outputled_gateway($fp, $config);
    }

    /* Update APC UPS */
    if (($config['scr_apcupsd'] ?? '0') === '1') {
        $ups = get_apcupsd_stats();
        if ($ups !== null) {
            update_widget_string($fp, 'scr_apcupsd', 'status', 1, 2, str_pad($ups['status'], $cols));
            if ($rows >= 3) {
                update_widget_scroller($fp, 'scr_apcupsd', 'detail1', 1, 3, $cols, 3, 'h', 3,
                    sprintf("Batt:%s Line:%s", $ups['bcharge'], $ups['linev']));
            }
            if ($rows >= 4) {
                update_widget_scroller($fp, 'scr_apcupsd', 'detail2', 1, 4, $cols, 4, 'h', 3,
                    sprintf("Time:%s Load:%s", $ups['timeleft'], $ups['loadpct']));
            }
        }
    }

    /* Update NUT UPS */
    if (($config['scr_nutups'] ?? '0') === '1') {
        $ups = get_nutups_stats();
        if ($ups !== null) {
            update_widget_string($fp, 'scr_nutups', 'status', 1, 2, str_pad($ups['status'], $cols));
            if ($rows >= 3) {
                update_widget_scroller($fp, 'scr_nutups', 'detail1', 1, 3, $cols, 3, 'h', 3,
                    sprintf("Batt:%s%% In:%sV", $ups['battery_charge'], $ups['input_voltage']));
            }
            if ($rows >= 4) {
                update_widget_scroller($fp, 'scr_nutups', 'detail2', 1, 4, $cols, 4, 'h', 3,
                    sprintf("RT:%ss Load:%s%%", $ups['battery_runtime'], $ups['ups_load']));
            }
        }
    }
}

/* ======================== MAIN DAEMON LOOP ======================== */

openlog("lcdproc_client", LOG_PID | LOG_PERROR, LOG_LOCAL0);
lcdproc_notice("LCDproc client starting...");

/* Read configuration */
$config = lcdproc_get_config();
if (($config['enabled'] ?? '0') !== '1') {
    lcdproc_notice("LCDproc is not enabled, exiting.");
    exit(0);
}

/* Parse display parameters */
$size = parse_display_size($config['size'] ?? 's20x4');
$cols = $size[0];
$rows = $size[1];
$refresh = parse_refresh($config['refresh_frequency'] ?? 'r5');

lcdproc_notice("Display: {$cols}x{$rows}, Refresh: {$refresh}s, Driver: " . ($config['driver'] ?? 'unknown'));

/* Wait for LCDd to be ready */
$retries = 30;
$fp = false;
while ($retries > 0) {
    $fp = lcdproc_connect();
    if ($fp !== false) {
        break;
    }
    lcdproc_notice("Waiting for LCDd... ({$retries} retries left)");
    sleep(2);
    $retries--;
}

if ($fp === false) {
    lcdproc_warn("Could not connect to LCDd after all retries. Exiting.");
    exit(1);
}

lcdproc_notice("Connected to LCDd successfully");

/* Build initial screens */
build_screens($fp, $config, $cols, $rows, $refresh);

lcdproc_notice("Screens initialized, entering main loop");

/* Main update loop */
$loop_count = 0;
while (true) {
    sleep($refresh);

    /* Check if connection is still alive */
    if (feof($fp)) {
        lcdproc_warn("LCDd connection lost, attempting reconnect...");
        fclose($fp);

        $fp = lcdproc_connect();
        if ($fp === false) {
            lcdproc_warn("Reconnect failed, exiting.");
            exit(1);
        }

        /* Rebuild screens after reconnect */
        build_screens($fp, $config, $cols, $rows, $refresh);
        lcdproc_notice("Reconnected and screens rebuilt");
    }

    /* Update dynamic screen content */
    update_dynamic_screens($fp, $config, $cols, $rows);

    $loop_count++;

    /* Reload config periodically (every 60 iterations) */
    if ($loop_count % 60 === 0) {
        $new_config = lcdproc_get_config();
        if ($new_config !== $config) {
            lcdproc_notice("Configuration changed, restarting screens");
            $config = $new_config;

            /* Reconnect and rebuild */
            fclose($fp);
            $fp = lcdproc_connect();
            if ($fp === false) {
                lcdproc_warn("Reconnect failed after config change, exiting.");
                exit(1);
            }
            $size = parse_display_size($config['size'] ?? 's20x4');
            $cols = $size[0];
            $rows = $size[1];
            $refresh = parse_refresh($config['refresh_frequency'] ?? 'r5');
            build_screens($fp, $config, $cols, $rows, $refresh);
        }
    }
}

/* Cleanup */
if ($fp) {
    fclose($fp);
}
closelog();
