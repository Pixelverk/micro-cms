<?php
declare(strict_types=1);



// timezone convert
function format_local_datetime(?int $timestamp, string $format = 'Y-m-d H:i'): string
{
    if (!$timestamp) {
        return '—';
    }

    $dt = new DateTime('@' . $timestamp); // UTC
    $dt->setTimezone(new DateTimeZone(site_timezone()));

    return $dt->format($format);
}


/**
 * The site timezone, from Settings, falling back to the shipped default.
 */
function site_timezone(): string
{
    $timezone = (string) get_setting('timezone', 'Europe/Stockholm');

    return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Europe/Stockholm';
}


/**
 * Format a timestamp for the public site, using the configured date format.
 *
 * Unlike format_local_datetime() this returns an empty string rather than a
 * placeholder, because theme templates already guard against a missing date.
 */
function format_date(?int $timestamp, ?string $format = null): string
{
    if (!$timestamp) {
        return '';
    }

    $format = $format ?? (string) get_setting('date_format', 'F j, Y');

    $dt = new DateTime('@' . $timestamp); // UTC
    $dt->setTimezone(new DateTimeZone(site_timezone()));

    return $dt->format($format);
}
