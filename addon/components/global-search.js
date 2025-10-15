import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { debug } from '@ember/debug';
import { next } from '@ember/runloop';
import { task, timeout } from 'ember-concurrency';

export default class GlobalSearchComponent extends Component {
    @service store;
    @service globalSearch;
    @service orderActions;
    @tracked results = [];
    @tracked query = '';

    @action setupComponent(element) {
        next(() => {
            element.querySelector('input').focus();
        });
    }

    @action onInput(e) {
        this.search.perform(e.target.value);
    }

    @action onKeydown(e) {
        if (e.key === 'Escape') this.globalSearch.hide();
    }

    // for now we only search and display orders but in the future it will perform
    // system wide resource search
    @task({ restartable: true }) *search() {
        yield timeout(300);

        const query = this.query;
        if (!query) {
            this.results = [];
            return;
        }

        try {
            const results = yield this.store.query('order', { query });
            this.results = isArray(results) ? results : [];
        } catch (err) {
            debug('Search failed: ' + err.message);
            this.results = [];
        }
    }
}
