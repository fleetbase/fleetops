<?php

use Fleetbase\FleetOps\Support\LabelPdf;
use Illuminate\Container\Container;

class FleetOpsLabelPdfDompdfFake
{
    public array $options = [];

    public function set_option(string $key, mixed $value): void
    {
        $this->options[$key] = $value;
    }
}

class FleetOpsLabelPdfWrapperFake
{
    public array $loadedHtml = [];
    public array $options    = [];

    public FleetOpsLabelPdfDompdfFake $dompdf;

    public function __construct()
    {
        $this->dompdf = new FleetOpsLabelPdfDompdfFake();
    }

    public function loadHTML(string $html, ?string $encoding = null): self
    {
        $this->loadedHtml[] = [$html, $encoding];

        return $this;
    }

    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    public function getDomPDF(): FleetOpsLabelPdfDompdfFake
    {
        return $this->dompdf;
    }
}

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

test('label pdf loads html with utf8 and applies renderer options', function () {
    $wrapper = new FleetOpsLabelPdfWrapperFake();

    Container::getInstance()->instance('dompdf.wrapper', $wrapper);

    $pdf = LabelPdf::fromHtml('<strong>مرحبا</strong>');

    expect($pdf)->toBe($wrapper)
        ->and($wrapper->loadedHtml)->toBe([['<strong>مرحبا</strong>', 'UTF-8']])
        ->and($wrapper->options)->toBe(LabelPdf::options())
        ->and($wrapper->dompdf->options)->toBe(LabelPdf::dompdfOptions())
        ->and(LabelPdf::options())->toBe(LabelPdf::dompdfOptions());
});
