<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$template = file_get_contents($root . '/templates/dashboard.html.twig');
if (!is_string($template)) {
    throw new RuntimeException('Could not read the dashboard template.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$helperStart = strpos($template, 'function gatewayUrl(value)');
$helperEnd = $helperStart === false ? false : strpos($template, 'function fmtDuration(secs)', $helperStart);
$assert(
    $helperStart !== false && $helperEnd !== false,
    'The dedicated gateway URL validator must exist before port rendering.',
);
$helper = substr($template, $helperStart, $helperEnd - $helperStart);
$assert(str_contains($helper, "gateway.split('.')"), 'IPv4 gateway validation must split the address into four octets.');
$assert(str_contains($helper, 'Number(part) <= 255'), 'IPv4 gateway validation must enforce the octet range.');
$assert(str_contains($helper, 'return /^(0|[1-9]\\d{0,2})$/.test(part)'), 'IPv4 validation must use a digit matcher with a single regex escape.');
$assert(str_contains($helper, 'new URL('), 'IPv6 gateway validation must use the platform URL parser.');
$assert(str_contains($helper, '/^[0-9a-f:.]+$/i'), 'IPv6 gateway validation must reject non-address characters.');
$assert(str_contains($helper, "return 'http://' + gateway + '/';"), 'IPv4 gateway links must use the device HTTP interface.');

$detailsStart = strpos($template, 'var html = infoHtml + legend');
$wanBlockStart = $detailsStart === false ? false : strpos($template, 'if (isWan) {', $detailsStart);
$lanBlockStart = $wanBlockStart === false ? false : strpos($template, '} else {', $wanBlockStart);
$gatewayBlockStart = $wanBlockStart === false ? false : strpos($template, 'if (p.gateway) {', $wanBlockStart);
$dnsBlockStart = $gatewayBlockStart === false ? false : strpos($template, 'if (p.firstDns || p.secondDns)', $gatewayBlockStart);
$assert(
    $wanBlockStart !== false
        && $lanBlockStart !== false
        && $gatewayBlockStart !== false
        && $dnsBlockStart !== false
        && $gatewayBlockStart < $lanBlockStart
        && $dnsBlockStart < $lanBlockStart,
    'Gateway links must be rendered only inside the WAN port details.',
);

$gatewayBlock = substr($template, $gatewayBlockStart, $dnsBlockStart - $gatewayBlockStart);
$assert(str_contains($gatewayBlock, 'gatewayUrl(gatewayValue)'), 'WAN gateway rendering must validate the address before linking.');
$assert(str_contains($gatewayBlock, 'esc(gatewayValue)'), 'Gateway text must be HTML-escaped.');
$assert(str_contains($gatewayBlock, 'target="_blank"'), 'Gateway links must open in a new tab.');
$assert(str_contains($gatewayBlock, 'rel="noopener noreferrer"'), 'New-tab links must not retain access to the opener window.');
$assert(str_contains($gatewayBlock, 'ti-external-link'), 'Gateway links must display an external-link indicator.');
$assert(
    !str_contains($template, "'<code>' + p.gateway + '</code>'"),
    'The previous unsafe direct interpolation of the gateway value must be removed.',
);

fwrite(STDOUT, "PASS: WAN gateway link regression checks.\n");
