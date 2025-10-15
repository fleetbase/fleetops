import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class GlobalSearchService extends Service {
    @tracked visible = false;

    @action show() {
        this.visible = true;
    }

    @action hide() {
        this.visible = false;
    }

    @action toggle() {
        this.visible = !this.visible;
    }
}
