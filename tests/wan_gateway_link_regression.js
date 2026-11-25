'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const templatePath = path.join(__dirname, '..', 'templates', 'dashboard.html.twig');
const template = fs.readFileSync(templatePath, 'utf8');
const helperMatch = template.match(/function gatewayUrl\(value\)\s*\{[\s\S]*?\n    \}/);

assert.ok(helperMatch, 'The production gatewayUrl(value) helper must exist in dashboard.html.twig.');

const context = vm.createContext(
    { URL },
    { codeGeneration: { strings: false, wasm: false } },
);
new vm.Script(helperMatch[0], { filename: 'dashboard.html.twig#gatewayUrl' })
    .runInContext(context, { timeout: 1000 });

const cases = [
    ['192.168.100.1', 'http://192.168.100.1/'],
    ['8.8.8.8', 'http://8.8.8.8/'],
    ['10.0.0.1', 'http://10.0.0.1/'],
    ['256.1.1.1', ''],
    ['192.168.001.1', ''],
    ['1.2.3', ''],
    ['http://192.168.1.1', ''],
    ['192.168.1.1:8080', ''],
    ['router.local', ''],
    ['2001:db8::1', 'http://[2001:db8::1]/'],
    ['[fe80::1]', 'http://[fe80::1]/'],
    ['::1', 'http://[::1]/'],
    ['2001:db8::g', ''],
    [':::1', ''],
    ['', ''],
    [null, ''],
];

for (const [input, expected] of cases) {
    assert.equal(
        context.gatewayUrl(input),
        expected,
        'gatewayUrl(' + JSON.stringify(input) + ') must return ' + JSON.stringify(expected),
    );
}

process.stdout.write('PASS: ' + cases.length + ' production WAN gateway URL cases.\n');
