import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import { A } from '@ember/array';
import { selectFiles } from 'ember-file-upload/test-support';
import { AbilitiesStub, makeRecord } from 'dummy/tests/helpers/stub-form-inputs';

module('Integration | Component | order/details/documents', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        const test = this;
        this.mode = 'success';
        this.owner.register('service:abilities', AbilitiesStub);
        this.owner.register(
            'service:notifications',
            class extends Service {
                serverError(error) {
                    calls.push(['serverError', error.message]);
                }
            }
        );
        this.owner.register(
            'service:fetch',
            class extends Service {
                uploadFile = {
                    perform: async (file, options, onSuccess, onError) => {
                        calls.push(['upload', file.name, options]);
                        if (test.mode === 'throw') {
                            throw new Error('upload rejected');
                        }
                        if (test.mode === 'error') {
                            onError();
                            return;
                        }
                        onSuccess({ id: 'file_1', original_filename: file.name, url: '/files/' + file.name, destroyRecord: async () => calls.push(['destroyRecord', 'file_1']) });
                    },
                };
            }
        );
        this.set('resource', makeRecord('order', { id: 'order_1', files: A([]) }, { isNew: false }));
    });

    test('selected documents are uploaded and attached to the order, then can be removed', async function (assert) {
        await render(hbs`<Order::Details::Documents @resource={{this.resource}} />`);

        assert.dom().includesText('Documents');
        assert.dom('input[type="file"]').exists();

        await selectFiles('input[type="file"]', new File(['%PDF'], 'invoice.pdf', { type: 'application/pdf' }));
        assert.deepEqual(this.calls, [['upload', 'invoice.pdf', { path: 'uploads/fleet-ops/order-files', subject_uuid: 'order_1', subject_type: 'fleet-ops:order', type: 'order_file' }]]);
        assert.strictEqual(this.calls.length, 1, 'one selection uploads once (DEFECTS #60)');
        assert.strictEqual(this.resource.files.length, 1, 'the uploaded file is attached');
        assert.dom().includesText('invoice.pdf');

        await click(findAll('.ember-basic-dropdown-trigger')[0]);
        await click(findAll('.next-dd-item').find((el) => /delete|remove/i.test(el.textContent)));
        assert.deepEqual(this.calls.at(-1), ['destroyRecord', 'file_1']);
    });

    test('a failed upload leaves the queue and a rejected one is reported', async function (assert) {
        await render(hbs`<Order::Details::Documents @resource={{this.resource}} />`);

        this.mode = 'error';
        await selectFiles('input[type="file"]', new File(['x'], 'broken.png', { type: 'image/png' }));
        assert.strictEqual(this.resource.files.length, 0);
        assert.dom().doesNotIncludeText('broken.png', 'the failed file left the upload queue');

        this.mode = 'throw';
        await selectFiles('input[type="file"]', new File(['x'], 'rejected.png', { type: 'image/png' }));
        assert.deepEqual(this.calls.at(-1), ['serverError', 'upload rejected']);
    });
});
