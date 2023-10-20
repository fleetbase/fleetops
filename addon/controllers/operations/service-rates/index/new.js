import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action, computed } from '@ember/object';
import { isBlank } from '@ember/utils';

export default class OperationsServiceRatesIndexNewController extends Controller {
    /**
     * Inject the `currentUser` service
     *
     * @var {Service}
     */
    @service store;

    /**
     * Inject the `notifications` service
     *
     * @var {Service}
     */
    @service notifications;

    /**
     * Inject the `loader` service
     *
     * @var {Service}
     */
    @service loader;

    /**
     * Inject the `hostRouter` service
     *
     * @var {Service}
     */
    @service hostRouter;

    /**
     * The service rate being created.
     *
     * @var {ServiceRateModel}
     */
    @tracked serviceRate = this.store.createRecord('service-rate', { per_meter_unit: 'm' });

    /**
     * Different service types available, based on order type.
     *
     * @var {Array}
     */
    @tracked serviceTypes = [];

    /**
     * Service areas.
     *
     * @var {Array}
     */
    @tracked serviceAreas = [];

    /**
     * Zones.
     *
     * @var {Array}
     */
    @tracked zones = [];

    /**
     * True if creating service rate.
     *
     * @var {Boolean}
     */
    @tracked isCreatingServiceRate = false;

    /**
     * True if updating service rate.
     *
     * @var {Boolean}
     */
    @tracked isUpdatingServiceRate = false;

    /**
     * Dimension units.
     *
     * @var {Array}
     */
    dimensionUnits = ['cm', 'in', 'ft', 'mm', 'm', 'yd'];

    /**
     * Weight units.
     *
     * @var {Array}
     */
    weightUnits = ['g', 'oz', 'lb', 'kg'];

    /**
     * Rate calculation methods
     *
     * @var {Array}
     */
    calculationMethods = [
        { name: 'Fixed Meter', key: 'fixed_meter' },
        { name: 'Per Meter', key: 'per_meter' },
        { name: 'Per Drop-off', key: 'per_drop' },
        { name: 'Algorithm', key: 'algo' },
    ];

    /**
     * COD Fee calculation methods
     *
     * @var {Array}
     */
    codCalculationMethods = [
        { name: 'Flat Fee', key: 'flat' },
        { name: 'Percentage', key: 'percentage' },
    ];

    /**
     * Peak hour fee calculation methods
     *
     * @var {Array}
     */
    peakHourCalculationMethods = [
        { name: 'Flat Fee', key: 'flat' },
        { name: 'Percentage', key: 'percentage' },
    ];

    /**
     * The applicable distance units for calculation.
     *
     * @var {Array}
     */
    distanceUnits = [
        { name: 'Meter', key: 'm' },
        { name: 'Kilometer', key: 'km' },
    ];

    /**
     * By km max distance set
     *
     * @var {String}
     */
    @tracked fixedMeterUnit = 'km';

    /**
     * By km max distance set
     *
     * @var {Integer}
     */
    @tracked fixedMeterMaxDistance = 5;

    /**
     * Mutable rate fee's.
     *
     * @var {Array}
     */
    @tracked _rateFees = [];

    /**
     * The rate feess for per km
     *
     * @var {Array}
     */
    @computed('fixedMeterMaxDistance', 'fixedMeterUnit', 'serviceRate.currency', '_rateFees') get rateFees() {
        if (!isBlank(this._rateFees)) {
            return this._rateFees;
        }

        let maxDistance = parseInt(this.fixedMeterMaxDistance ?? 0);
        let distanceUnit = this.fixedMeterUnit;
        let currency = this.serviceRate.currency;
        let rateFees = [];

        for (let distance = 0; distance < maxDistance; distance++) {
            rateFees.pushObject({
                distance,
                distance_unit: distanceUnit,
                fee: 0,
                currency,
            });
        }

        return rateFees;
    }

    /** setter for rate fee's */
    set rateFees(rateFees) {
        this._rateFees = rateFees;
    }

    /**
     * Mutable per drop-off rate fee's.
     *
     * @var {Array}
     */
    @tracked perDropRateFees = this.serviceRate.isNew
        ? [
              {
                  min: 1,
                  max: 5,
                  fee: 0,
                  unit: 'waypoint',
                  currency: this.serviceRate.currency,
              },
          ]
        : this.serviceRate.rate_fees.toArray();

    /**
     * Default parcel fee's
     *
     * @var {Array}
     */
    @tracked parcelFees = [
        {
            size: 'small',
            length: 34,
            width: 18,
            height: 10,
            dimensions_unit: 'cm',
            weight: 2,
            weight_unit: 'kg',
            fee: 0,
            currency: this.serviceRate.currency,
        },
        {
            size: 'medium',
            length: 34,
            width: 32,
            height: 10,
            dimensions_unit: 'cm',
            weight: 4,
            weight_unit: 'kg',
            fee: 0,
            currency: this.serviceRate.currency,
        },
        {
            size: 'large',
            length: 34,
            width: 32,
            height: 18,
            dimensions_unit: 'cm',
            weight: 8,
            weight_unit: 'kg',
            fee: 0,
            currency: this.serviceRate.currency,
        },
        {
            size: 'x-large',
            length: 34,
            width: 32,
            height: 34,
            dimensions_unit: 'cm',
            weight: 13,
            weight_unit: 'kg',
            fee: 0,
            currency: this.serviceRate.currency,
        },
    ];

    /**
     * Adds a per drop-off rate fee
     */
    @action addPerDropoffRateFee() {
        const rateFees = this.perDropRateFees;
        const currency = this.serviceRate.currency;

        const min = rateFees.lastObject?.max ? rateFees.lastObject?.max + 1 : 1;
        const max = min + 5;

        rateFees.pushObject({
            min: min,
            max: max,
            unit: 'waypoint',
            fee: 0,
            currency,
        });
    }

    /**
     * Adds a per drop-off rate fee
     */
    @action removePerDropoffRateFee(index) {
        this.perDropRateFees.removeAt(index);
    }

    /**
     * Saves the service rate to server
     *
     * @void
     */
    @action createServiceRate() {
        const { serviceRate, rateFees, parcelFees } = this;

        serviceRate.setServiceRateFees(rateFees).setServiceRateParcelFees(parcelFees);

        if (serviceRate.isPerDrop) {
            serviceRate.clearServiceRateFees().setServiceRateFees(this.perDropRateFees);
        }

        this.isCreatingServiceRate = true;
        this.loader.showLoader('.overlay-inner-content', { loadingMessage: 'Creating service rate...' });

        try {
            return serviceRate
                .save()
                .then((serviceRate) => {
                    return this.transitionToRoute('operations.service-rates.index').then(() => {
                        this.notifications.success(`New Service Rate ${serviceRate.service_name} Created`);
                        this.resetForm();
                        this.hostRouter.refresh();
                    });
                })
                .catch((error) => {
                    this.notifications.serverError(error);
                })
                .finally(() => {
                    this.isCreatingServiceRate = false;
                    this.loader.removeLoader();
                });
        } catch (error) {
            this.isCreatingServiceRate = false;
            this.loader.removeLoader();
        }
    }

    /**
     * Select a service area and load it's zones
     *
     * @param {String} serviceAreaId
     * @memberof OperationsServiceRatesIndexNewController
     */
    @action selectServiceArea(serviceAreaId) {
        if (typeof serviceAreaId === 'string' && !isBlank(serviceAreaId)) {
            this.serviceRate.service_area_uuid = serviceAreaId;

            // load zones for this service area
            this.store.query('zone', { service_area_uuid: serviceAreaId }).then((zones) => {
                this.zones = zones;
            });
        } else {
            this.zones = [];
        }
    }

    /**
     * Resets the service rate form
     *
     * @void
     */
    @action resetForm() {
        this.serviceRate = this.store.createRecord('service-rate');
        this.byKmMaxDistance = 5;
        this.rateFees = this.rateFees.map((rateFee) => ({ ...rateFee, fee: 0 }));
        this.parcelFees = this.parcelFees.map((parcelFee) => ({
            ...parcelFee,
            fee: 0,
            dimensions_unit: 'cm',
            weight_unit: 'kg',
        }));
    }

    /**
     * Handle back button action
     *
     * @return {Transition}
     */
    @action transitionBack() {
        return this.transitionToRoute('operations.service-rates.index').then(() => {
            this.resetForm();
        });
    }
}
