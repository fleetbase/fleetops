<?php

test('order file updates attach files to the order like creation does', function () {
    $controller = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Internal/v1/OrderController.php');
    $orderModel = file_get_contents(dirname(__DIR__) . '/src/Models/Order.php');
    $detailsJs  = file_get_contents(dirname(dirname(__DIR__)) . '/addon/components/order/details/documents.js');
    $formJs     = file_get_contents(dirname(dirname(__DIR__)) . '/addon/components/order/form/documents.js');

    expect($controller)
        ->toContain("\$uploads = \$request->array('order.files')")
        ->toContain('$order->attachFiles($uploads)')
        ->and($orderModel)
        ->toContain('public function attachFiles($uploads): self')
        ->toContain('$file->setKey($this)')
        ->and($detailsJs)
        ->toContain("subject_type: 'fleet-ops:order'")
        ->and($formJs)
        ->toContain('if (!this.args.resource.isNew)')
        ->toContain('subject_uuid = this.args.resource.id');
});
