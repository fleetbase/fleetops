#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

const root = process.cwd();
const checkOnly = process.argv.includes('--check');
const cssRoot = path.join(root, 'addon');
const rgbaPercentAlphaPattern = /rgba\(\s*([+-]?(?:\d*\.)?\d+)\s*,\s*([+-]?(?:\d*\.)?\d+)\s*,\s*([+-]?(?:\d*\.)?\d+)\s*,\s*([+-]?(?:\d*\.)?\d+)%\s*\)/g;

function collectCssFiles(dir, files = []) {
    if (!fs.existsSync(dir)) {
        return files;
    }

    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const fullPath = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            collectCssFiles(fullPath, files);
            continue;
        }

        if (entry.isFile() && entry.name.endsWith('.css')) {
            files.push(fullPath);
        }
    }

    return files;
}

function formatAlpha(percent) {
    const alpha = Number(percent) / 100;
    return Number.isInteger(alpha) ? String(alpha) : String(Number(alpha.toFixed(4)));
}

function lineForIndex(source, index) {
    return source.slice(0, index).split('\n').length;
}

const changes = [];

for (const filePath of collectCssFiles(cssRoot)) {
    const source = fs.readFileSync(filePath, 'utf8');
    const matches = [];

    const next = source.replace(rgbaPercentAlphaPattern, (match, red, green, blue, alphaPercent, offset) => {
        const replacement = `rgba(${red}, ${green}, ${blue}, ${formatAlpha(alphaPercent)})`;
        matches.push({
            line: lineForIndex(source, offset),
            before: match,
            after: replacement,
        });
        return replacement;
    });

    if (matches.length === 0) {
        continue;
    }

    changes.push({
        filePath,
        matches,
    });

    if (!checkOnly) {
        fs.writeFileSync(filePath, next);
    }
}

if (changes.length === 0) {
    console.log('No comma-form rgba() percent-alpha values found.');
    process.exit(0);
}

for (const change of changes) {
    const relativePath = path.relative(root, change.filePath);

    for (const match of change.matches) {
        console.log(`${relativePath}:${match.line} ${match.before} -> ${match.after}`);
    }
}

if (checkOnly) {
    console.error('\nRun `npm run fix:css-alpha` to normalize these values before production builds.');
    process.exit(1);
}

console.log(`\nNormalized ${changes.reduce((total, change) => total + change.matches.length, 0)} rgba() alpha value(s).`);
