import buildRoutes from 'ember-engines/routes';

export default buildRoutes(function () {
    this.route('virtual', { path: '/:section/:slug' });
    this.route('operations', { path: '/' }, function () {
        this.route('order-config', function () {});
        this.route('service-rates', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                });
                this.route('edit', { path: '/edit/:public_id' });
            });
        });
        this.route('scheduler', function () {});
        this.route('orders', { path: '/' }, function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                });
            });
        });
        this.route('routes', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' });
            });
        });
    });
    this.route('management', { path: '/manage' }, function () {
        this.route('fleets', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                    this.route('vehicles');
                    this.route('drivers');
                });
                this.route('edit', { path: '/edit/:public_id' });
            });
        });
        this.route('vendors', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                });
                this.route('edit', { path: '/edit/:public_id' });
            });
            this.route('integrated', function () {
                this.route('new');
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                });
                this.route('edit', { path: '/edit/:public_id' });
            });
        });
        this.route('drivers', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                    this.route('positions');
                    this.route('schedule');
                });
                this.route('edit', { path: '/edit/:public_id' });
            });
        });
        this.route('vehicles', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                    this.route('positions');
                    this.route('devices');
                    this.route('equipment');
                });
                this.route('edit', { path: '/edit/:public_id' });
            });
        });
        this.route('places', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                    this.route('operations');
                    this.route('performance');
                    this.route('activity');
                    this.route('map');
                    this.route('comments');
                    this.route('documents');
                    this.route('rules');
                });
                this.route('edit', { path: '/edit/:public_id' });
            });
        });
        this.route('contacts', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                });
                this.route('edit', { path: '/edit/:public_id' });
            });
            this.route('customers', function () {
                this.route('new');
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                });
                this.route('edit', { path: '/edit/:public_id' });
            });
        });
        this.route('fuel-reports', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                });
                this.route('edit', { path: '/edit/:public_id' });
            });
        });
        this.route('issues', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                });
                this.route('edit', { path: '/edit/:public_id' });
            });
        });
    });
    this.route('connectivity', function () {
        this.route('telematics', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('edit', { path: '/edit/:public_id' });
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                    this.route('devices');
                    this.route('sensors');
                    this.route('events');
                });
            });
        });

        this.route('devices', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('edit', { path: '/edit/:public_id' });
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                    this.route('events');
                });
            });
        });

        this.route('sensors', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('edit', { path: '/edit/:public_id' });
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                });
            });
        });

        this.route('events', function () {
            this.route('details', { path: '/:public_id' });
        });

        this.route('tracking');
    });
    this.route('maintenance', function () {
        this.route('work-orders', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('edit', { path: '/edit/:public_id' });
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                });
            });
        });

        this.route('equipment', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('edit', { path: '/edit/:public_id' });
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                });
            });
        });

        this.route('parts', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('edit', { path: '/edit/:public_id' });
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                });
            });
        });
    });
    this.route('analytics', function () {
        this.route('reports', function () {
            this.route('index', { path: '/' }, function () {
                this.route('new');
                this.route('edit', { path: '/edit/:public_id' });
                this.route('details', { path: '/:public_id' }, function () {
                    this.route('index', { path: '/' });
                    this.route('result');
                });
            });
        });
    });
    this.route('settings', function () {
        this.route('navigator-app');
        this.route('notifications');
        this.route('custom-fields');
        this.route('routing');
        this.route('payments', function () {
            this.route('index', { path: '/' });
            this.route('onboard');
        });
    });
});
