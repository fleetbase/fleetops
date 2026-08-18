'use strict';
const { buildEngine } = require('ember-engines/lib/engine-addon');
const { name } = require('./package');
const Funnel = require('broccoli-funnel');
const MergeTrees = require('broccoli-merge-trees');
const resolve = require('resolve');
const path = require('path');

module.exports = buildEngine({
    name,

    // Lazy loading keeps the engine out of the host bundle, but it also keeps the
    // engine's modules out of the dummy app that `ember test` builds, so every test
    // importing `@fleetbase/fleetops-engine/*` fails to load. Eager loading is
    // scoped to `ember test` run from this package, so host apps consuming the
    // engine — including their own test builds — keep lazy loading. Addon index
    // files are evaluated before ember-cli assigns EMBER_ENV, hence the argv check.
    lazyLoading: {
        enabled: !(process.argv.includes('test') && process.cwd() === __dirname),
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
