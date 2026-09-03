'use strict';

/**
 * Coverage gate for @fleetbase/fleetops-engine (the Ember addon under addon/).
 *
 * Reads the json-summary report produced by ember-cli-code-coverage and fails
 * (exit code 1) unless:
 *
 *   1. Global statements, branches, functions and lines are each exactly 100%.
 *   2. Every file entry in the report is at 100% for all four metrics.
 *   3. Every eligible first-party source file under addon/ appears in the
 *      report — so untested/unimported files can never silently drop out of
 *      the denominator.
 *   4. The artifacts on disk were actually produced by the run that just
 *      finished, rather than left behind by an earlier one. See
 *      `checkArtifactFreshness` below.
 */

const fs = require('fs');
const path = require('path');

const { STAMP_FILENAME } = require('./stamp-coverage-run');

const METRICS = ['statements', 'branches', 'functions', 'lines'];

/**
 * Confirms the coverage artifacts belong to the run that just finished.
 *
 * A run can finish green and leave the PREVIOUS
 * `coverage-final.json` in place, or write the summary and HTML report without
 * writing `coverage-final.json` at all. Neither announces itself. Reading
 * whichever files happen to be on disk then reports the last run's numbers as
 * this run's — and in the direction that matters, a line that regressed is
 * reported as still covered.
 *
 * `scripts/stamp-coverage-run.js` records when the run started; every required
 * artifact must have been written after that.
 *
 * @param {{stampPath: string, artifactPaths: string[], projectRoot: string}} options
 * @returns {string[]} failures, empty when the artifacts are demonstrably fresh
 */
function checkArtifactFreshness({ stampPath, artifactPaths, projectRoot }) {
    const failures = [];

    if (!fs.existsSync(stampPath)) {
        return [
            `no coverage run stamp at ${normalize(stampPath, projectRoot)} — run \`pnpm run test:coverage\`, which stamps the run, ` +
                'rather than reading whatever coverage artifacts are already on disk',
        ];
    }

    let startedAt;
    try {
        ({ startedAt } = JSON.parse(fs.readFileSync(stampPath, 'utf8')));
    } catch (error) {
        return [`coverage run stamp at ${normalize(stampPath, projectRoot)} is unreadable (${error.message}) — re-run \`pnpm run test:coverage\``];
    }

    if (typeof startedAt !== 'number') {
        return [`coverage run stamp at ${normalize(stampPath, projectRoot)} has no numeric "startedAt" — re-run \`pnpm run test:coverage\``];
    }

    for (const artifactPath of artifactPaths) {
        const relative = normalize(artifactPath, projectRoot);

        if (!fs.existsSync(artifactPath)) {
            failures.push(`${relative} was not written by this run — the suite reported results but produced no coverage artifact`);
            continue;
        }

        const writtenAt = fs.statSync(artifactPath).mtimeMs;
        if (writtenAt < startedAt) {
            const age = Math.round((startedAt - writtenAt) / 1000);
            failures.push(`${relative} is ${age}s older than this coverage run — it is a stale artifact from an earlier run, not this one's results`);
        }
    }

    return failures;
}

function listSourceFiles(sourceRoot) {
    const results = [];
    const walk = (dir) => {
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            const fullPath = path.join(dir, entry.name);
            if (entry.isDirectory()) {
                walk(fullPath);
            } else if (entry.isFile() && entry.name.endsWith('.js')) {
                results.push(fullPath);
            }
        }
    };
    walk(sourceRoot);
    return results.sort();
}

/**
 * Whether a coverage metric counts as fully covered.
 *
 * Compares covered/total rather than reading `pct`, because istanbul reports a
 * file with no executable code (an empty Glimmer component class, for example)
 * as 0/0 with `pct: 0`. Such a file is vacuously covered — there is nothing in
 * it that a test could execute — and 30 addon files are in exactly that shape,
 * so a pct-based check could never reach 100%.
 *
 * @param {{covered: number, total: number}} metric
 * @returns {boolean}
 */
function isFullyCovered(metric) {
    if (!metric || typeof metric.total !== 'number' || typeof metric.covered !== 'number') {
        return false;
    }

    return metric.covered === metric.total;
}

function normalize(filePath, projectRoot) {
    return path.relative(projectRoot, path.resolve(projectRoot, filePath)).split(path.sep).join('/');
}

function checkCoverage({ summaryPath, sourceRoot, projectRoot }) {
    const failures = [];

    if (!fs.existsSync(summaryPath)) {
        return { ok: false, failures: [`coverage summary not found at ${summaryPath} — run the coverage suite first`] };
    }

    const summary = JSON.parse(fs.readFileSync(summaryPath, 'utf8'));

    const total = summary.total;
    if (!total) {
        failures.push(`coverage summary at ${summaryPath} has no "total" entry`);
        return { ok: false, failures };
    }

    const reported = new Map();
    for (const [key, entry] of Object.entries(summary)) {
        if (key === 'total') {
            continue;
        }

        const relative = normalize(key, projectRoot);

        // Only gate on THIS package's own source. A pnpm workspace link (e.g. @fleetbase/ember-core)
        // is instrumented by the same build and lands in the report as `../ember-core/...`; holding
        // a sibling package to this package's threshold buries the real signal in hundreds of
        // foreign failures.
        if (relative.startsWith('../')) {
            continue;
        }

        reported.set(relative, entry);
    }

    // Global totals recomputed from first-party entries only. `summary.total` is istanbul's,
    // which sums every instrumented file including workspace-linked siblings.
    for (const metric of METRICS) {
        let covered = 0;
        let count = 0;
        for (const entry of reported.values()) {
            covered += entry[metric].covered;
            count += entry[metric].total;
        }
        const pct = count === 0 ? 100 : Math.round((covered / count) * 10000) / 100;
        if (covered !== count) {
            failures.push(`global ${metric} coverage is ${pct}% (${covered}/${count}) — must be 100%`);
        }
    }

    for (const [file, entry] of reported) {
        for (const metric of METRICS) {
            if (!isFullyCovered(entry[metric])) {
                failures.push(`${file}: ${metric} at ${entry[metric].pct}% (${entry[metric].covered}/${entry[metric].total}) — must be 100%`);
            }
        }
    }

    for (const sourceFile of listSourceFiles(sourceRoot)) {
        const relative = normalize(sourceFile, projectRoot);
        if (!reported.has(relative)) {
            failures.push(`${relative} is missing from the coverage report — every eligible addon file must be instrumented`);
        }
    }

    return { ok: failures.length === 0, failures };
}

function main(argv) {
    const projectRoot = process.cwd();
    const summaryPath = path.resolve(projectRoot, argv[2] || 'coverage/coverage-summary.json');
    const sourceRoot = path.resolve(projectRoot, argv[3] || 'addon');
    const stampPath = path.resolve(projectRoot, argv[4] || STAMP_FILENAME);

    // Freshness first: percentages read off a stale artifact are worse than no
    // percentages, because they look authoritative.
    const staleness = checkArtifactFreshness({
        stampPath,
        artifactPaths: [summaryPath, path.resolve(path.dirname(summaryPath), 'coverage-final.json')],
        projectRoot,
    });

    if (staleness.length > 0) {
        console.error(`Coverage gate failed — the report cannot be trusted (${staleness.length} problem(s)):`);
        for (const failure of staleness) {
            console.error(`  - ${failure}`);
        }
        return 1;
    }

    const { ok, failures } = checkCoverage({ summaryPath, sourceRoot, projectRoot });

    if (!ok) {
        console.error(`Coverage gate failed with ${failures.length} problem(s):`);
        for (const failure of failures) {
            console.error(`  - ${failure}`);
        }
        return 1;
    }

    console.log('Coverage gate passed: 100% statements, branches, functions and lines across all addon files.');
    return 0;
}

module.exports = { checkCoverage, checkArtifactFreshness, listSourceFiles, isFullyCovered, METRICS, STAMP_FILENAME };

if (require.main === module) {
    process.exitCode = main(process.argv);
}
