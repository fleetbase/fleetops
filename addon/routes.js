import buildRoutes from 'ember-engines/routes';

export default buildRoutes(function () {
    this.route('virtual', { path: '/:slug/:view' });
    this.route('operations', { path: '/' }, function () {
        this.route('dispatch');
        this.route('zones', function () {});
        this.route('service-rates', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('edit', { path: '/:public_id' });
            });
        });
        this.route('scheduler', function () {});
        this.route('orders', { path: '/' }, function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('view', { path: '/:public_id' });
                this.route('config', function () {
                    this.route('types', { path: '/' });
                });
            });
        });
    });
    this.route('management', { path: '/manage' }, function () {
        this.route('fleets', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' });
                this.route('edit', { path: '/edit/:public_id' });
            });
        });
        this.route('vendors', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' });
                this.route('edit', { path: '/edit/:public_id' });
            });
        });
        this.route('drivers', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' });
                this.route('edit', { path: '/edit/:public_id' });
            });
        });
        this.route('vehicles', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' });
                this.route('edit', { path: '/edit/:public_id' });
            });
        });
        this.route('places', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' });
                this.route('edit', { path: '/edit/:public_id' });
            });
        });
        this.route('contacts', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' });
                this.route('edit', { path: '/edit/:public_id' });
            });
        });
        this.route('fuel-reports', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' });
                this.route('edit', { path: '/edit/:public_id' });
            });
        });
        this.route('issues', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' });
                this.route('edit', { path: '/edit/:public_id' });
            });
        });
        this.route('settings', function () {});
    });
    this.route('comms', function () {
        this.route('chat');
        this.route('intercom');
    });
});
