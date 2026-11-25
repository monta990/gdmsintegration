'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const candidates = [
    path.join(__dirname, '..', 'templates', 'dashboard.html.twig'),
    path.join(__dirname, '..', 'gdmsintegration', 'templates', 'dashboard.html.twig'),
];
const templatePath = candidates.find((candidate) => fs.existsSync(candidate));
assert.ok(templatePath, 'The dashboard Twig template must exist in the repository or installable package.');
const source = fs.readFileSync(templatePath, 'utf8');

const escMatch = source.match(/function esc\(value\)\s*\{[\s\S]*?\n    \}/);
assert.ok(escMatch, 'The shared HTML escaping helper must exist.');
const esc = vm.runInNewContext(`${escMatch[0]}; esc;`);
assert.equal(
    esc(`<img src=x onerror=alert(1)> &"'`),
    '&lt;img src=x onerror=alert(1)&gt; &amp;&quot;&#39;',
    'The HTML escaping helper must encode angle brackets, ampersands and both quote types.'
);

const blockStart = source.indexOf("            var ulBytes   = parseInt(container.getAttribute('data-upload')");
const sink = '            body.innerHTML = html;';
const blockEnd = source.indexOf(sink, blockStart);
assert.notEqual(blockStart, -1, 'The port detail modal renderer must exist.');
assert.notEqual(blockEnd, -1, 'The port detail modal HTML sink must exist.');
const modal = source.slice(blockStart, blockEnd + sink.length);

const requiredEscapedExpressions = [
    'esc(label)', 'esc(portName)', 'esc(p.wanName)', 'esc(p.ip)', 'esc(dnsVal)',
    'esc(p.wamMac)', 'esc(p.portIpv6)', 'esc(p.customName)', 'esc(p.desc)', 'esc(p.type)',
    'esc(statusText)', 'esc(firstSeen.slice(0,16))', 'esc(lastSeen.slice(0,16))',
    'esc(li.label)', "esc(wanTypeNames[p.wanType] || '—')", "esc(portSpeeds[p.speed] || '—')",
];
for (const expression of requiredEscapedExpressions) {
    assert.ok(modal.includes(expression), `Expected output escaping for ${expression}.`);
}

const forbiddenRawExpressions = [
    /\+\s*label\s*;/, /\+\s*portName\s*\+/, /\+\s*p\.wanName\s*\+/,
    /\+\s*p\.ip\s*\+/, /\+\s*dnsVal\s*\+/, /\+\s*p\.wamMac\s*\+/,
    /\+\s*p\.portIpv6\s*\+/, /\+\s*p\.customName\s*\+/,
    /\+\s*p\.desc\s*\+/, /\+\s*p\.type\s*\+/,
];
for (const pattern of forbiddenRawExpressions) {
    assert.equal(pattern.test(modal), false, `Unescaped GWN data interpolation remains: ${pattern}`);
}

console.log(`PASS: port modal XSS regression (${requiredEscapedExpressions.length} escaped expressions, HTML metacharacters encoded).`);
