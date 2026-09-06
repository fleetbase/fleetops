'use strict';

/**
 * Prepares a coverage run.
 *
 * Two jobs, both aimed at coverage collection that fails
 * silently rather than loudly:
 *
 *   1. Removes the previous `coverage/` directory. A run that leaves the old
 *      artifacts in place is indistinguishable from a run that produced them,
 *      and the resulting report is read as current. Deleting the whole
 *      directory (rather than individual files) is also the only sequence
 *      observed to produce a complete artifact set reliably.
 *
 *   2. Writes a stamp recording when this run started. `check-coverage.js`
 *      refuses any artifact older than the stamp, so a stale report fails the
 *      gate instead of passing it.
 *
 * Usage: node scripts/stamp-coverage-run.js [coverageDir] [stampPath]
 */

const fs = require('fs');
const path = require('path');

const STAMP_FILENAME = '.coverage-run-stamp.json';

/**
 * Guard against ever removing something that is not a generated coverage
 * directory: it must be named `coverage` and sit directly inside the project.
 */
function isSafeCoverageDir(coverageDir, projectRoot) {
    const resolved = path.resolve(coverageDir);
    return path.basename(resolved) === 'coverage' && path.dirname(resolved) === path.resolve(projectRoot);
}

function stampRun({ coverageDir, stampPath, projectRoot, now }) {
    const removed = fs.existsSync(coverageDir);

    if (removed) {
        if (!isSafeCoverageDir(coverageDir, projectRoot)) {
            throw new Error(`refusing to remove ${coverageDir} — expected a "coverage" directory directly inside ${projectRoot}`);
        }

        fs.rmSync(coverageDir, { recursive: true, force: true });
    }

    fs.writeFileSync(stampPath, `${JSON.stringify({ startedAt: now }, null, 2)}\n`);

    return { removed, startedAt: now };
}

function main(argv) {
    const projectRoot = process.cwd();
    const coverageDir = path.resolve(projectRoot, argv[2] || 'coverage');
    const stampPath = path.resolve(projectRoot, argv[3] || STAMP_FILENAME);

    // One second earlier than "now": some filesystems store mtimes at
    // whole-second resolution, so an artifact written in the same second as the
    // stamp can round to just below it and read as stale.
    const startedAt = Date.now() - 1000;

    const { removed } = stampRun({ coverageDir, stampPath, projectRoot, now: startedAt });

    console.log(removed ? 'Cleared the previous coverage/ directory and stamped this run.' : 'Stamped this coverage run.');

    return 0;
}

module.exports = { stampRun, isSafeCoverageDir, STAMP_FILENAME };

if (require.main === module) {
    process.exitCode = main(process.argv);
}
