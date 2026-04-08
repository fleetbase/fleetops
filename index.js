'use strict';
const { buildEngine } = require('ember-engines/lib/engine-addon');
const { name } = require('./package');
const Funnel = require('broccoli-funnel');
const MergeTrees = require('broccoli-merge-trees');
const resolve = require('resolve');
const path = require('path');

module.exports = buildEngine({
    name,

    lazyLoading: {
        enabled: true,
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
        const jointJsTree = this.treeForJointJs();
        const assetsTree = [
            new Funnel(path.join(__dirname, 'assets'), {
                destDir: '',
            }),
            ...leafletTree,
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

    /**
     * Inject the Google Maps JavaScript API script tag into the app's index.html
     * when `mapProvider` is set to 'google' in the environment config.
     *
     * The script is injected with `defer` so it does not block the initial render.
     * The GoogleMapsAdapter polls for `window.google.maps` before initialising.
     *
     * To enable:
     *   1. Set MAP_PROVIDER=google in your .env file
     *   2. Set GOOGLE_MAPS_API_KEY=<your-key> in your .env file
     *   3. Ensure the key has Maps JS API, Drawing Library, and Geometry Library enabled
     *
     * @param {string} type   'head' | 'body' | 'all'
     * @param {Object} config  The resolved environment config
     * @returns {string|undefined}
     */
    contentFor(type, config) {
        if (type === 'head' && config.mapProvider === 'google' && config.googleMapsApiKey) {
            const libraries = config.googleMapsLibraries || 'drawing,geometry,places';
            return [
                '<!-- Google Maps JavaScript API (injected by @fleetbase/fleetops-engine) -->',
                '<script',
                `    src="https://maps.googleapis.com/maps/api/js?key=${config.googleMapsApiKey}&libraries=${libraries}&loading=async"`,
                '    defer',
                '></script>',
            ].join('\n');
        }
    },
});
