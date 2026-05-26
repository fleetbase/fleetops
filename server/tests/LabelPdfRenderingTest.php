<?php

test('labels use the shared utf8 pdf renderer instead of arabic-only html rewriting', function () {
    $labelPdf = file_get_contents(dirname(__DIR__) . '/src/Support/LabelPdf.php');
    $order    = file_get_contents(dirname(__DIR__) . '/src/Models/Order.php');
    $waypoint = file_get_contents(dirname(__DIR__) . '/src/Models/Waypoint.php');
    $entity   = file_get_contents(dirname(__DIR__) . '/src/Models/Entity.php');
    $default  = file_get_contents(dirname(__DIR__) . '/resources/views/labels/default.php');
    $stop     = file_get_contents(dirname(__DIR__) . '/resources/views/labels/waypoint-label.php');
    $utils    = file_get_contents(dirname(__DIR__) . '/src/Support/Utils.php');

    expect($labelPdf)
        ->toContain("Pdf::loadHTML(\$html, 'UTF-8')")
        ->toContain("'defaultFont'             => 'DejaVu Sans'")
        ->and($order)->toContain('LabelPdf::fromHtml($this->label())')
        ->and($waypoint)->toContain('LabelPdf::fromHtml($this->label())')
        ->and($entity)->toContain('LabelPdf::fromHtml($this->label())')
        ->and($default)->toContain('Noto Sans Arabic')
        ->not->toContain('strtoupper($company->name)')
        ->and($stop)->toContain('Noto Sans CJK JP')
        ->not->toContain('strtoupper($company->name)')
        ->and($utils)->not->toContain('fixArabicInHTML');
});
