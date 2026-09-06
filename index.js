'use strict';
const { buildEngine } = require('ember-engines/lib/engine-addon');
const { name } = require('./package');
const Funnel = require('broccoli-funnel');
const MergeTrees = require('broccoli-merge-trees');
const resolve = require('resolve');
const path = require('path');

// Only require ember-cli-code-coverage (a devDependency) when coverage is requested, so consuming
// applications never need it installed. ember-cli-code-coverage 3.x has no `included` hook: the
// istanbul babel plugin must be added to this addon's babel options explicitly, otherwise
// `window.__coverage__` is never defined and `sendCoverage()` silently writes nothing.
function coverageBabelPlugin() {
    if (process.env.COVERAGE === 'true') {
        return require('ember-cli-code-coverage').buildBabelPlugin();
    }

    return [];
}

module.exports = buildEngine({
    name,

    // NOTE: `buildEngine` assigns this whole object to `this.options` (ember-engines'
    // `engine-addon.js` init), so ember-cli-babel reads the addon's babel options from HERE —
    // a nested `options: { babel }` key, the plain-addon shape, is silently ignored.
    babel: {
        plugins: [...coverageBabelPlugin()],
    },

    // Lazy loading keeps the engine out of the host bundle, but it also keeps the
    // engine's modules out of the dummy app that `ember test` builds, so every test
    // importing `@fleetbase/fleetops-engine/*` fails to load. Eager loading is
    // scoped to `ember test` run from this package, so host apps consuming the
    // engine — including their own test builds — keep lazy loading. Addon index
    // files are evaluated before ember-cli assigns EMBER_ENV, hence the argv check;
    // an explicit `EMBER_ENV=test` in the shell is honoured too so that
    // `EMBER_ENV=test ember build --environment=test --output-path=<dir>` produces a
    // dist that `ember test --path <dir> --filter ...` can re-run without rebuilding.
    lazyLoading: {
        enabled: !((process.argv.includes('test') || process.env.EMBER_ENV === 'test') && process.cwd() === __dirname),
    },

    treeForLeaflet: function () {
        const alwaysExclude = ['LICENSE', 'package.json', 'example.html'];
        const leafletAddons = [
            { package: 'leaflet-contextmenu', include: undefined, exclude: [...alwaysExclude], path: ['dist'] },
            { package: 'leaflet-draw', include: undefined, exclude: [...alwaysExclude], path: ['dist'] },
        ];

        const trees = [];
        for (let i = 0; i < leafletAddons.length; i++) {
            const leafletAdddon = leafletAddons[i];
            const leafletAddonDist = path.join(this.pathBase(leafletAdddon.package), ...leafletAdddon.path);

            trees.push(
                new Funnel(leafletAddonDist, {
                    destDir: 'leaflet',
                    include: leafletAdddon.include,
                    exclude: leafletAdddon.exclude,
                    getDestinationPath: leafletAdddon.getDestinationPath,
                })
            );
        }

        return trees;
    },

    treeForLeafletImages: function () {
        const leafletImagesPath = path.join(this.pathBase('leaflet'), 'dist', 'images');

        return new Funnel(leafletImagesPath, {
            destDir: 'assets/images',
            include: ['marker-icon.png', 'marker-icon-2x.png', 'marker-shadow.png'],
        });
    },

    treeForJointJs: function () {
        const trees = [];

        const jointJsPath = path.join(this.pathBase('@joint/core'), 'dist');
        trees.push(
            new Funnel(jointJsPath, {
                destDir: '',
                include: ['joint.min.js'],
                exclude: [],
            })
        );

        const jointJsDirectedGraphPath = path.join(this.pathBase('@joint/layout-directed-graph'), 'dist');
        trees.push(
            new Funnel(jointJsDirectedGraphPath, {
                destDir: '/',
                include: ['DirectedGraph.min.js'],
                exclude: [],
            })
        );

        return trees;
    },

    mergeWithPublicTree: function (publicTree) {
        const leafletTree = this.treeForLeaflet();
        const leafletImagesTree = this.treeForLeafletImages();
        const jointJsTree = this.treeForJointJs();
        const assetsTree = [
            new Funnel(path.join(__dirname, 'assets'), {
                destDir: '',
            }),
            ...leafletTree,
            leafletImagesTree,
            ...jointJsTree,
        ];

        // Merge the addon tree with the existing tree
        return publicTree ? new MergeTrees([publicTree, ...assetsTree], { overwrite: true }) : new MergeTrees([...assetsTree], { overwrite: true });
    },

    treeForPublic: function () {
        const publicTree = this._super.treeForPublic.apply(this, arguments);

        return this.mergeWithPublicTree(publicTree);
    },

    pathBase(packageName) {
        return path.dirname(resolve.sync(packageName + '/package.json', { basedir: __dirname }));
    },

    isDevelopingAddon() {
        return true;
    },
});
